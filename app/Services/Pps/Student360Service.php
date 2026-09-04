<?php

namespace App\Services\Pps;

use App\Models\Pps\BehaviorCard;
use App\Models\Pps\ClassroomRating;
use App\Models\Pps\CounselingSession;
use App\Models\Pps\Extracurricular;
use App\Models\Pps\PerformanceSnapshot;
use App\Models\Pps\PpsAlert;
use App\Models\Pps\PpsNotificationLog;
use App\Models\Pps\PrivateTuition;
use App\Models\Pps\SchoolPpsConfig;
use App\Models\Pps\TeacherAssignment;
use App\Models\Pps\WelfareIntervention;
use App\Support\StudentTaxonomyFilter;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use SmsCore\Models\Student;
use SmsCore\Models\User;

/**
 * Everything the "Student 360" page shows, in one payload.
 *
 * Deliberately separate from StudentPerformanceController::show so the
 * existing profile page keeps its contract while the principal compares the
 * two views.
 */
class Student360Service
{
    public const NOTIFICATION_TYPE = 'student_360_teacher_alert';

    public function __construct(
        private readonly StudentInsightService $insights,
        private readonly ForecastService $forecast,
    ) {
    }

    // ── Whole page ─────────────────────────────────────────────────────────

    public function build(Student $student, ?User $viewer, string $period, int $years): array
    {
        $snapshot = PerformanceSnapshot::query()
            ->where('student_id', $student->id)->forPeriod($period)->first();
        $previous = PerformanceSnapshot::query()
            ->where('student_id', $student->id)
            ->where('snapshot_period', '<', $period)
            ->orderByDesc('snapshot_period')
            ->first();

        $sectionId = $student->section_id;
        $assignments = $this->assignmentsForSection($sectionId);
        $grid = $this->buildMarksGrid($student, $years);
        $tuitions = $this->buildTuitions($student, $assignments);
        $rows = $this->attachTuitions($grid['rows'], $tuitions);
        $config = SchoolPpsConfig::current();

        return [
            'student' => array_merge(
                $student->only(['id', 'student_code', 'name', 'photo_path', 'guardian_name', 'guardian_phone']),
                StudentTaxonomyFilter::present($student),
            ),
            'period' => $period,
            'years' => $years,
            'available_years' => $grid['available_years'],
            'snapshot' => $this->serializeSnapshot($snapshot, $previous),
            'class_average' => $this->classAverage($sectionId, $period),
            'forecast' => $this->forecast->forecastForStudent($student->id, $period),
            'why' => $this->buildWhy($student, $snapshot, $rows, $config),
            'marks_grid' => ['columns' => $grid['columns'], 'rows' => $rows],
            'signals' => $this->buildSignals($student, $snapshot, $previous, $viewer),
            'people' => $this->buildPeople($student, $assignments, $viewer),
            'tuitions' => $tuitions,
            'timeline' => $this->buildTimeline($student, $period),
            'notifications' => $this->recentNotifications($student),
        ];
    }

    private function serializeSnapshot(?PerformanceSnapshot $snapshot, ?PerformanceSnapshot $previous): ?array
    {
        if ($snapshot === null) {
            return null;
        }

        return [
            'period' => $snapshot->snapshot_period,
            'overall_score' => (float) $snapshot->overall_score,
            'risk_score' => (float) $snapshot->risk_score,
            'academic_score' => (float) $snapshot->academic_score,
            'attendance_score' => (float) $snapshot->attendance_score,
            'behavior_score' => (float) $snapshot->behavior_score,
            'participation_score' => (float) $snapshot->participation_score,
            'extracurricular_score' => (float) $snapshot->extracurricular_score,
            'alert_level' => $snapshot->alert_level,
            'trend_direction' => $snapshot->trend_direction,
            'previous_period' => $previous?->snapshot_period,
            'overall_delta' => $previous ? round((float) $snapshot->overall_score - (float) $previous->overall_score, 1) : null,
            'risk_delta' => $previous ? round((float) $snapshot->risk_score - (float) $previous->risk_score, 1) : null,
        ];
    }

    private function classAverage(?int $sectionId, string $period): ?float
    {
        if ($sectionId === null) {
            return null;
        }

        $classmates = Student::query();
        StudentTaxonomyFilter::applySectionIds($classmates, [$sectionId]);

        $avg = PerformanceSnapshot::query()
            ->forPeriod($period)
            ->whereIn('student_id', $classmates->select('students.id'))
            ->avg('overall_score');

        return $avg === null ? null : round((float) $avg, 1);
    }

    /**
     * Max three one-line reasons, most severe first: alert triggers, then the
     * weakest subject against class average, then attendance.
     *
     * @return array<int, array{kind: string, text: string}>
     */
    private function buildWhy(Student $student, ?PerformanceSnapshot $snapshot, array $rows, SchoolPpsConfig $config): array
    {
        $why = [];

        $alert = PpsAlert::query()->where('student_id', $student->id)->active()->orderByDesc('created_at')->first();
        foreach (collect($alert?->trigger_reasons ?? [])->take(2) as $reason) {
            if (! empty($reason['detail'])) {
                $why[] = ['kind' => 'alert', 'text' => $reason['detail']];
            }
        }

        $weakest = collect($rows)->first(fn (array $row) => $row['gap'] !== null && $row['gap'] < 0);
        if ($weakest !== null) {
            $trend = $weakest['delta'] === null ? '' : sprintf(' (%s%.1f since previous exam)', $weakest['delta'] > 0 ? '+' : '', $weakest['delta']);
            $why[] = [
                'kind' => 'subject',
                'text' => sprintf('%s %.1f%% vs class average %.1f%%%s.', $weakest['subject'], $weakest['latest_pct'], $weakest['latest_class_avg'], $trend),
            ];
        }

        if ($snapshot !== null && (float) $snapshot->attendance_score < (float) $config->threshold_attendance_warning) {
            $absent = (int) DB::table('pps_attendance')
                ->where('student_id', $student->id)
                ->where('date', '>=', now()->subDays(30)->toDateString())
                ->where('status', 'absent')
                ->count();
            $why[] = [
                'kind' => 'attendance',
                'text' => sprintf('Attendance score %.0f — %d absences in the last 30 days.', (float) $snapshot->attendance_score, $absent),
            ];
        }

        return array_slice(array_values(array_unique($why, SORT_REGULAR)), 0, 3);
    }

    private function buildSignals(Student $student, ?PerformanceSnapshot $snapshot, ?PerformanceSnapshot $previous, ?User $viewer): array
    {
        $scores = [];
        foreach ([
            'academic' => 'Academic', 'attendance' => 'Attendance', 'behavior' => 'Behaviour',
            'participation' => 'Participation', 'extracurricular' => 'Extracurricular',
        ] as $key => $label) {
            $column = "{$key}_score";
            $value = $snapshot ? (float) $snapshot->{$column} : null;
            $prev = $previous ? (float) $previous->{$column} : null;
            $scores[] = [
                'key' => $key,
                'label' => $label,
                'value' => $value,
                'delta' => ($value !== null && $prev !== null) ? round($value - $prev, 1) : null,
            ];
        }

        $attendance = DB::table('pps_attendance')
            ->where('student_id', $student->id)
            ->where('date', '>=', now()->subDays(30)->toDateString())
            ->groupBy('status')
            ->selectRaw('status, COUNT(*) as n')
            ->pluck('n', 'status');

        $cards = BehaviorCard::query()
            ->where('student_id', $student->id)
            ->where('issued_at', '>=', now()->subDays(60))
            ->groupBy('card_type')
            ->selectRaw('card_type, COUNT(*) as n')
            ->pluck('n', 'card_type');

        return [
            'scores' => $scores,
            'attendance_30d' => [
                'present' => (int) ($attendance['present'] ?? 0),
                'absent' => (int) ($attendance['absent'] ?? 0),
                'late' => (int) ($attendance['late'] ?? 0),
            ],
            'behavior_cards_60d' => [
                'red' => (int) ($cards['red'] ?? 0),
                'yellow' => (int) ($cards['yellow'] ?? 0),
                'green' => (int) ($cards['green'] ?? 0),
            ],
            'counseling_sessions' => CounselingSession::query()->where('student_id', $student->id)->count(),
            'wellbeing' => $this->insights->buildWellbeing($student, $viewer),
        ];
    }

    /** @return Collection<int, TeacherAssignment> */
    private function assignmentsForSection(?int $sectionId): Collection
    {
        if ($sectionId === null) {
            return collect();
        }

        return TeacherAssignment::query()
            ->where('section_id', $sectionId)
            ->with(['teacher:id,full_name,user_id', 'subject:id,full_name'])
            ->orderBy('id')
            ->get();
    }

    private function buildPeople(Student $student, Collection $assignments, ?User $viewer): array
    {
        $context = $this->insights->buildContext($student, $viewer);
        $classTeacher = $assignments->first(fn (TeacherAssignment $a) => $a->is_class_teacher && $a->teacher !== null);

        $subjectTeachers = $assignments
            ->filter(fn (TeacherAssignment $a) => $a->subject_id !== null && $a->teacher !== null)
            ->unique('subject_id')
            ->values()
            ->map(fn (TeacherAssignment $a) => [
                'subject_id' => (int) $a->subject_id,
                'subject' => $a->subject?->full_name,
                'teacher_id' => (int) $a->teacher_id,
                'name' => $a->teacher?->full_name,
                'has_login' => $a->teacher?->user_id !== null,
            ])
            ->all();

        $lastSession = CounselingSession::query()
            ->where('student_id', $student->id)
            ->with('counselor:id,name')
            ->orderByDesc('session_date')
            ->first();

        return [
            'guardian' => [
                'name' => $student->guardian_name,
                'phone' => $student->guardian_phone,
                'relation' => $context['guardian_relation'] ?? null,
                'profession' => $context['guardian_profession'] ?? null,
                'family_status' => $context['family_status'] ?? null,
                'economic_status' => $context['economic_status'] ?? null,
                'restricted' => (bool) ($context['restricted'] ?? true),
            ],
            'class_teacher' => $classTeacher ? [
                'teacher_id' => (int) $classTeacher->teacher_id,
                'name' => $classTeacher->teacher?->full_name,
                'has_login' => $classTeacher->teacher?->user_id !== null,
            ] : null,
            'subject_teachers' => $subjectTeachers,
            'counselor' => $lastSession?->counselor?->name,
        ];
    }

    /**
     * Recorded rows from pps_private_tuitions plus the legacy JSON on
     * students.private_tuition_subjects (source = "declared").
     */
    private function buildTuitions(Student $student, Collection $assignments): array
    {
        $sectionTeacherIds = $assignments->pluck('teacher_id')->map(fn ($id) => (int) $id)->unique()->all();

        $recorded = PrivateTuition::query()
            ->where('student_id', $student->id)
            ->with(['teacher:id,full_name', 'subject:id,full_name'])
            ->orderByDesc('started_on')
            ->orderByDesc('id')
            ->get()
            ->map(fn (PrivateTuition $t) => [
                'id' => $t->id,
                'subject_id' => $t->subject_id,
                'subject' => $t->subject?->full_name ?? $t->subject_name,
                'teacher_id' => $t->teacher_id,
                'teacher_name' => $t->teacher?->full_name ?? $t->tutor_name,
                'is_school_teacher' => $t->teacher_id !== null,
                'teaches_this_class' => $t->teacher_id !== null && in_array((int) $t->teacher_id, $sectionTeacherIds, true),
                'hours_per_week' => $t->hours_per_week,
                'started_on' => $t->started_on?->toDateString(),
                'ended_on' => $t->ended_on?->toDateString(),
                'notes' => $t->notes,
                'source' => 'recorded',
            ]);

        $declared = collect($student->private_tuition_subjects ?? [])
            ->map(fn (mixed $entry) => is_string($entry) ? ['subject' => $entry] : $entry)
            ->filter(fn ($entry) => ! empty($entry['subject']))
            ->map(fn (array $entry) => [
                'id' => null,
                'subject_id' => null,
                'subject' => $entry['subject'],
                'teacher_id' => null,
                'teacher_name' => $entry['tutor_name'] ?? null,
                'is_school_teacher' => false,
                'teaches_this_class' => false,
                'hours_per_week' => $entry['hours_per_week'] ?? null,
                'started_on' => null,
                'ended_on' => null,
                'notes' => $student->private_tuition_notes,
                'source' => 'declared',
            ]);

        return $recorded->concat($declared)->values()->all();
    }

    private function attachTuitions(array $rows, array $tuitions): array
    {
        $active = array_filter($tuitions, fn (array $t) => $t['ended_on'] === null);

        foreach ($rows as &$row) {
            foreach ($active as $tuition) {
                $matchesId = $tuition['subject_id'] !== null && $tuition['subject_id'] === $row['subject_id'];
                $matchesName = $tuition['subject_id'] === null && strcasecmp($tuition['subject'], $row['subject']) === 0;
                if ($matchesId || $matchesName) {
                    $row['tuition'] = $tuition;
                    $row['tuition_flag'] = $tuition['is_school_teacher']
                        && $row['latest_class_avg'] !== null
                        && $row['latest_pct'] < $row['latest_class_avg'];
                    break;
                }
            }
        }
        unset($row);

        return $rows;
    }

    /** Last six months of events, newest first, capped at 20. */
    private function buildTimeline(Student $student, string $period): array
    {
        $end = Carbon::createFromFormat('Y-m', $period)->endOfMonth();
        $start = $end->copy()->subMonths(6)->startOfMonth();
        $events = collect();

        BehaviorCard::query()->where('student_id', $student->id)->whereBetween('issued_at', [$start, $end])->get()
            ->each(fn (BehaviorCard $c) => $events->push([
                'type' => 'behavior_card', 'level' => $c->card_type, 'subject' => null,
                'text' => $c->reason, 'date' => $c->issued_at?->toDateString(),
            ]));

        ClassroomRating::query()->where('student_id', $student->id)->whereNotNull('free_comment')
            ->whereBetween('rating_period', [$start->toDateString(), $end->toDateString()])->get()
            ->each(fn (ClassroomRating $r) => $events->push([
                'type' => 'teacher_note', 'level' => $r->behavioral_flag, 'subject' => $r->subject,
                'text' => $r->free_comment, 'date' => $r->rating_period?->toDateString(),
            ]));

        CounselingSession::query()->where('student_id', $student->id)
            ->whereBetween('session_date', [$start->toDateString(), $end->toDateString()])->get()
            ->each(fn (CounselingSession $s) => $events->push([
                'type' => 'counseling', 'level' => $s->progress_status, 'subject' => null,
                'text' => $s->session_type === 'psychometric' ? 'Psychometric assessment recorded.' : ($s->action_plan ?: 'Counselling session recorded.'),
                'date' => $s->session_date?->toDateString(),
            ]));

        PpsAlert::query()->where('student_id', $student->id)->whereBetween('created_at', [$start, $end])->get()
            ->each(fn (PpsAlert $a) => $events->push([
                'type' => 'alert', 'level' => $a->alert_level, 'subject' => null,
                'text' => collect($a->trigger_reasons)->pluck('detail')->implode(' '),
                'date' => $a->created_at?->toDateString(),
            ]));

        WelfareIntervention::query()->where('student_id', $student->id)->whereBetween('created_at', [$start, $end])->get()
            ->each(fn (WelfareIntervention $w) => $events->push([
                'type' => 'welfare', 'level' => $w->intervention_type, 'subject' => null,
                'text' => $w->notes ?: $w->intervention_type, 'date' => $w->created_at?->toDateString(),
            ]));

        Extracurricular::query()->where('student_id', $student->id)->whereNotNull('achievement')
            ->whereBetween('event_date', [$start->toDateString(), $end->toDateString()])->get()
            ->each(fn (Extracurricular $e) => $events->push([
                'type' => 'achievement', 'level' => 'green', 'subject' => null,
                'text' => "{$e->activity_name}: {$e->achievement}", 'date' => $e->event_date?->toDateString(),
            ]));

        return $events->sortByDesc('date')->values()->take(20)->all();
    }

    private function recentNotifications(Student $student): array
    {
        return PpsNotificationLog::query()
            ->where('student_id', $student->id)
            ->where('type', self::NOTIFICATION_TYPE)
            ->orderByDesc('generated_at')
            ->limit(5)
            ->get()
            ->map(fn (PpsNotificationLog $log) => [
                'id' => $log->id,
                'recipient_role' => $log->recipient_role,
                'teacher_name' => $log->meta['teacher_name'] ?? null,
                'subject' => $log->subject,
                'generated_at' => $log->generated_at?->toIso8601String(),
            ])
            ->all();
    }

    // ── Notify teachers ────────────────────────────────────────────────────

    /**
     * One pps_notification_logs row per assigned teacher matched by the
     * selected subjects (role subject_teacher) or the class-teacher flag.
     *
     * @param  int[]  $subjectIds
     * @return array<int, array{teacher_id: int, teacher_name: string|null, role: string, has_login: bool, log_id: int}>
     */
    public function notifyTeachers(Student $student, User $sender, array $subjectIds, bool $includeClassTeacher, ?string $message, string $period): array
    {
        $assignments = $this->assignmentsForSection($student->section_id);
        $targets = [];

        foreach ($assignments as $assignment) {
            if ($assignment->teacher === null) {
                continue;
            }
            $teacherId = (int) $assignment->teacher_id;
            if ($assignment->subject_id !== null && in_array((int) $assignment->subject_id, $subjectIds, true)) {
                $targets[$teacherId] ??= ['assignment' => $assignment, 'role' => 'subject_teacher', 'subjects' => []];
                $targets[$teacherId]['subjects'][] = $assignment->subject?->full_name;
            } elseif ($includeClassTeacher && $assignment->is_class_teacher) {
                $targets[$teacherId] ??= ['assignment' => $assignment, 'role' => 'class_teacher', 'subjects' => []];
            }
        }

        if ($targets === []) {
            return [];
        }

        $taxonomy = StudentTaxonomyFilter::present($student);
        $classLabel = trim(($taxonomy['class_name'] ?? '').' '.($taxonomy['section_name'] ?? ''));
        $body = $message !== null && trim($message) !== ''
            ? trim($message)
            : $this->defaultNotifyBody($student, $period);

        $sent = [];
        foreach ($targets as $teacherId => $target) {
            $subjects = array_values(array_filter($target['subjects']));
            $log = PpsNotificationLog::query()->create([
                'type' => self::NOTIFICATION_TYPE,
                'channel' => 'database',
                'recipient_role' => $target['role'],
                'recipient_user_id' => $target['assignment']->teacher?->user_id,
                'student_id' => $student->id,
                'snapshot_period' => $period,
                'subject' => mb_substr(sprintf('Early warning: %s (%s)%s', $student->name, $classLabel, $subjects === [] ? '' : ' — '.implode(', ', $subjects)), 0, 180),
                'body' => $body,
                'meta' => [
                    'source' => 'student_360',
                    'teacher_id' => $teacherId,
                    'teacher_name' => $target['assignment']->teacher?->full_name,
                    'subject_ids' => $subjectIds,
                    'sent_by' => $sender->id,
                ],
                'generated_at' => now(),
            ]);

            $sent[] = [
                'teacher_id' => $teacherId,
                'teacher_name' => $target['assignment']->teacher?->full_name,
                'role' => $target['role'],
                'has_login' => $target['assignment']->teacher?->user_id !== null,
                'log_id' => $log->id,
            ];
        }

        return $sent;
    }

    private function defaultNotifyBody(Student $student, string $period): string
    {
        $snapshot = PerformanceSnapshot::query()->where('student_id', $student->id)->forPeriod($period)->first();
        $grid = $this->buildMarksGrid($student, 1);
        $why = $this->buildWhy($student, $snapshot, $grid['rows'], SchoolPpsConfig::current());
        $lines = array_map(fn (array $w) => '- '.$w['text'], $why);

        return $lines === []
            ? "{$student->name} needs early academic follow-up. Please review and respond with a short plan."
            : "{$student->name} needs early academic follow-up:\n".implode("\n", $lines)."\nPlease review and respond with a short plan.";
    }

    // ── Marks grid ─────────────────────────────────────────────────────────

    /**
     * Rows = subjects, columns = exams (oldest left), limited to the last
     * $years distinct academic years the student has marks in.
     *
     * @return array{available_years: int[], columns: array<int, array>, rows: array<int, array>}
     */
    public function buildMarksGrid(Student $student, int $years): array
    {
        $marks = DB::table('pps_marks as m')
            ->join('pps_exam_components as c', 'c.id', '=', 'm.component_id')
            ->join('pps_exams as e', 'e.id', '=', 'c.exam_id')
            ->join('pps_exam_types as t', 't.id', '=', 'e.exam_type_id')
            ->join('subjects as s', 's.id', '=', 'm.subject_id')
            ->where('m.student_id', $student->id)
            ->groupBy('e.id', 'e.title', 'e.academic_year', 'e.term', 'e.exam_date', 't.name', 't.code', 's.id', 's.full_name')
            ->selectRaw('e.id as exam_id, e.title, e.academic_year, e.term, e.exam_date, t.name as type_name, t.code as type_code, s.id as subject_id, s.full_name as subject, SUM(m.marks_obtained) as obtained, SUM(c.max_raw_marks) as total')
            ->get();

        $availableYears = $marks->pluck('academic_year')->map(fn ($y) => (int) $y)->unique()->sortDesc()->values();
        $selectedYears = $availableYears->take(max(1, $years))->all();
        $marks = $marks->filter(fn ($row) => in_array((int) $row->academic_year, $selectedYears, true));

        $columns = $marks
            ->unique('exam_id')
            ->sortBy(fn ($row) => sprintf('%04d-%02d-%s', $row->academic_year, $row->term, $row->exam_date))
            ->values()
            ->map(fn ($row) => [
                'exam_id' => (int) $row->exam_id,
                'title' => $row->title,
                'academic_year' => (int) $row->academic_year,
                'term' => (int) $row->term,
                'exam_date' => $row->exam_date,
                'type_name' => $row->type_name,
                'type_code' => $row->type_code,
            ])
            ->all();

        $examIds = array_column($columns, 'exam_id');
        $subjectIds = $marks->pluck('subject_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        $peerStats = $this->peerStats($examIds, $subjectIds);

        $rows = [];
        foreach ($marks->groupBy('subject_id') as $subjectId => $subjectMarks) {
            $cells = [];
            foreach ($columns as $column) {
                $mark = $subjectMarks->firstWhere('exam_id', $column['exam_id']);
                if ($mark === null || (float) $mark->total <= 0) {
                    continue;
                }
                $pct = round((float) $mark->obtained / (float) $mark->total * 100, 1);
                $peers = $peerStats[$column['exam_id'].':'.$subjectId] ?? [];
                $cells[(string) $column['exam_id']] = [
                    'pct' => $pct,
                    'obtained' => round((float) $mark->obtained, 1),
                    'total' => round((float) $mark->total, 1),
                    'grade' => $this->grade($pct),
                    'class_avg' => $peers === [] ? null : round(array_sum($peers) / count($peers), 1),
                    'rank' => $peers === [] ? null : 1 + count(array_filter($peers, fn (float $p) => $p > $pct)),
                    'cohort' => count($peers),
                ];
            }

            $ordered = array_values($cells);
            $latest = $ordered === [] ? null : end($ordered);
            $previous = count($ordered) >= 2 ? $ordered[count($ordered) - 2] : null;
            $delta = ($latest && $previous) ? round($latest['pct'] - $previous['pct'], 1) : null;

            $rows[] = [
                'subject_id' => (int) $subjectId,
                'subject' => $subjectMarks->first()->subject,
                'cells' => $cells,
                'latest_pct' => $latest['pct'] ?? null,
                'latest_class_avg' => $latest['class_avg'] ?? null,
                'gap' => ($latest && $latest['class_avg'] !== null) ? round($latest['pct'] - $latest['class_avg'], 1) : null,
                'delta' => $delta,
                'trend' => $delta === null ? 'flat' : ($delta <= -3 ? 'down' : ($delta >= 3 ? 'up' : 'flat')),
                'tuition' => null,
                'tuition_flag' => false,
            ];
        }

        // Weakest first: biggest negative gap to class average on top.
        usort($rows, fn (array $a, array $b) => ($a['gap'] ?? 0) <=> ($b['gap'] ?? 0));

        return [
            'available_years' => $availableYears->all(),
            'columns' => $columns,
            'rows' => $rows,
        ];
    }

    /**
     * Per (exam, subject): every student's percentage, for class average and rank.
     *
     * @return array<string, float[]>
     */
    private function peerStats(array $examIds, array $subjectIds): array
    {
        if ($examIds === [] || $subjectIds === []) {
            return [];
        }

        $rows = DB::table('pps_marks as m')
            ->join('pps_exam_components as c', 'c.id', '=', 'm.component_id')
            ->whereIn('c.exam_id', $examIds)
            ->whereIn('m.subject_id', $subjectIds)
            ->groupBy('c.exam_id', 'm.subject_id', 'm.student_id')
            ->selectRaw('c.exam_id, m.subject_id, SUM(m.marks_obtained) / NULLIF(SUM(c.max_raw_marks), 0) * 100 as pct')
            ->get();

        $stats = [];
        foreach ($rows as $row) {
            if ($row->pct === null) {
                continue;
            }
            $stats[$row->exam_id.':'.$row->subject_id][] = round((float) $row->pct, 1);
        }

        return $stats;
    }

    private function grade(float $pct): string
    {
        return match (true) {
            $pct >= 80 => 'A+',
            $pct >= 70 => 'A',
            $pct >= 60 => 'A-',
            $pct >= 50 => 'B',
            $pct >= 40 => 'C',
            $pct >= 33 => 'D',
            default => 'F',
        };
    }
}

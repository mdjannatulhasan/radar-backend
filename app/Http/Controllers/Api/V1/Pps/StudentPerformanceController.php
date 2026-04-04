<?php

namespace App\Http\Controllers\Api\V1\Pps;

use App\Http\Controllers\Controller;
use App\Models\Pps\Assessment;
use App\Models\Pps\BehaviorCard;
use App\Models\Pps\ClassroomRating;
use App\Models\Pps\CounselingSession;
use App\Models\Pps\Extracurricular;
use App\Models\Pps\PerformanceSnapshot;
use App\Models\Pps\PpsAlert;
use App\Models\Student;
use App\Models\User;
use App\Services\Pps\ForecastService;
use App\Services\Pps\ReportExportService;
use App\Services\Pps\RecommendationService;
use App\Services\Pps\SimplePdfService;
use App\Services\Pps\StudentInsightService;
use App\Services\Pps\TrendAnalyzerService;
use App\Services\Pps\WhatIfAnalyzerService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class StudentPerformanceController extends Controller
{
    public function __construct(
        private readonly RecommendationService $recommendations,
        private readonly TrendAnalyzerService $trendAnalyzer,
        private readonly WhatIfAnalyzerService $whatIfAnalyzer,
        private readonly ForecastService $forecastService,
        private readonly ReportExportService $reportExportService,
        private readonly SimplePdfService $pdfService,
        private readonly StudentInsightService $insights,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        /** @var User|null $viewer */
        $viewer = $request->user();
        $period = $this->resolvePeriod($request);
        $perPage = min(100, max(1, (int) $request->integer('limit', 24)));

        $snapshots = PerformanceSnapshot::query()
            ->forPeriod($period)
            ->with('student:id,name,student_code,class_name,section,roll_number,guardian_name,guardian_phone')
            ->when($viewer?->hasAnyRole('teacher'), function (Builder $query) use ($viewer): void {
                $query->whereHas('student', fn (Builder $studentQuery) => $this->applyTeacherStudentScope($studentQuery, $viewer));
            })
            ->when(
                $request->filled('alert_level'),
                fn (Builder $query) => $query->where('alert_level', $request->string('alert_level')->toString())
            )
            ->when($request->filled('class_name'), function (Builder $query) use ($request): void {
                $query->whereHas('student', fn (Builder $studentQuery) => $studentQuery->where('class_name', $request->string('class_name')->toString()));
            })
            ->when($request->filled('section'), function (Builder $query) use ($request): void {
                $query->whereHas('student', fn (Builder $studentQuery) => $studentQuery->where('section', $request->string('section')->toString()));
            })
            ->when($request->filled('search'), function (Builder $query) use ($request): void {
                $term = $request->string('search')->toString();
                $query->whereHas('student', function (Builder $studentQuery) use ($term): void {
                    $studentQuery->where('name', 'like', "%{$term}%")
                        ->orWhere('student_code', 'like', "%{$term}%");
                });
            })
            ->orderByDesc('risk_score')
            ->paginate($perPage);

        return response()->json($snapshots);
    }

    public function show(Request $request, Student $student): JsonResponse
    {
        /** @var User|null $viewer */
        $viewer = $request->user();
        if ($viewer?->hasAnyRole('teacher') && ! $viewer->canAccessStudent($student)) {
            abort(Response::HTTP_FORBIDDEN, 'You are not assigned to this student.');
        }

        $period = $this->resolvePeriod($request);
        $snapshot = PerformanceSnapshot::query()
            ->where('student_id', $student->id)
            ->forPeriod($period)
            ->first();

        $history = PerformanceSnapshot::query()
            ->where('student_id', $student->id)
            ->orderByDesc('snapshot_period')
            ->limit(9)
            ->get([
                'snapshot_period',
                'overall_score',
                'academic_score',
                'attendance_score',
                'behavior_score',
                'participation_score',
                'alert_level',
            ])
            ->reverse()
            ->values();

        $teacherComments = ClassroomRating::query()
            ->where('student_id', $student->id)
            ->whereNotNull('free_comment')
            ->with('teacher:id,name')
            ->orderByDesc('rating_period')
            ->limit(5)
            ->get(['rating_period', 'subject', 'free_comment', 'behavioral_flag', 'teacher_id']);

        $activeAlerts = PpsAlert::query()
            ->where('student_id', $student->id)
            ->active()
            ->orderByDesc('created_at')
            ->get();

        $defaultWhatIf = $snapshot
            ? $this->whatIfAnalyzer->analyze($student->id, $period, $this->whatIfAnalyzer->defaultScenarios($snapshot))
            : [];

        return response()->json([
            'student' => $student->only([
                'id',
                'student_code',
                'name',
                'class_name',
                'section',
                'roll_number',
                'photo_path',
                'guardian_name',
                'guardian_phone',
                'guardian_email',
            ]),
            'period' => $period,
            'current_snapshot' => $snapshot,
            'academic_profile' => $this->insights->buildAcademicProfile($student, $period, $snapshot),
            'context' => $this->insights->buildContext($student, $request->user()),
            'wellbeing' => $this->insights->buildWellbeing($student, $request->user()),
            'tuition_analysis' => $this->insights->buildTuitionAnalysis($student, $snapshot),
            'history' => $history,
            'recent_events' => $this->getRecentEvents($student->id, $period),
            'teacher_comments' => $teacherComments,
            'active_alerts' => $activeAlerts,
            'recommendations' => $this->recommendations->forStudent($student->id, $snapshot),
            'advisory_brief' => $snapshot?->snapshot_data['ai_recommendation'] ?? $this->recommendations->narrativeForStudent($snapshot),
            'what_if_preview' => $defaultWhatIf,
            'forecast' => $this->forecastService->forecastForStudent($student->id, $period),
        ]);
    }

    public function context(Request $request, Student $student): JsonResponse
    {
        $this->authorize('viewContext', $student);
        $viewer = $request->user();

        return response()->json([
            'student_id' => $student->id,
            'context' => $this->insights->buildContext($student, $viewer),
            'wellbeing' => $this->insights->buildWellbeing($student, $viewer),
        ]);
    }

    public function updateContext(Request $request, Student $student): JsonResponse
    {
        $viewer = $request->user();
        $this->authorize('updateContext', $student);
        $fullAccess = (bool) $viewer?->hasAnyRole(['principal', 'admin', 'counselor']);

        $validated = $request->validate([
            'admission_date' => ['nullable', 'date'],
            'current_gpa' => ['nullable', 'numeric', 'min:0', 'max:5'],
            'current_grade' => ['nullable', 'string', 'max:10'],
            'class_rank' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'private_tuition_subjects' => ['nullable', 'array'],
            'private_tuition_subjects.*.subject' => ['required_with:private_tuition_subjects', 'string', 'max:100'],
            'private_tuition_subjects.*.hours_per_week' => ['nullable', 'numeric', 'min:0', 'max:50'],
            'private_tuition_subjects.*.tutor_name' => ['nullable', 'string', 'max:120'],
            'private_tuition_notes' => ['nullable', 'string', 'max:1000'],
            'family_status' => ['nullable', 'string', 'max:120'],
            'economic_status' => ['nullable', 'string', 'max:120'],
            'scholarship_status' => ['nullable', 'string', 'max:120'],
            'health_notes' => ['nullable', 'string', 'max:1500'],
            'allergies' => ['nullable', 'string', 'max:255'],
            'medications' => ['nullable', 'string', 'max:255'],
            'residence_change_note' => ['nullable', 'string', 'max:255'],
            'special_needs' => ['nullable', 'array'],
            'confidential_context' => ['nullable', 'string', 'max:1500'],
        ]);

        if (! $fullAccess) {
            $validated = collect($validated)->only([
                'private_tuition_subjects',
                'private_tuition_notes',
                'family_status',
                'health_notes',
                'allergies',
                'medications',
                'residence_change_note',
            ])->all();
        }

        $student->update($validated);

        return response()->json([
            'message' => 'Student context updated.',
            'context' => $this->insights->buildContext($student->fresh(), $viewer),
        ]);
    }

    public function whatIf(Request $request, Student $student): JsonResponse
    {
        $data = $request->validate([
            'period' => ['nullable', 'date_format:Y-m'],
            'hypotheticals' => ['required', 'array', 'min:1'],
            'hypotheticals.*.type' => ['required', 'in:academic,attendance,behavior,participation,extracurricular'],
            'hypotheticals.*.new_value' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        $period = $data['period'] ?? now()->format('Y-m');

        return response()->json([
            'period' => $period,
            'results' => $this->whatIfAnalyzer->analyze($student->id, $period, $data['hypotheticals']),
        ]);
    }

    public function classAnalytics(Request $request, string $className, string $section): JsonResponse
    {
        /** @var User|null $viewer */
        $viewer = $request->user();
        if ($viewer?->hasAnyRole('teacher') && ! $viewer->isAssignedToClass($className, $section)) {
            abort(Response::HTTP_FORBIDDEN, 'You are not assigned to this class.');
        }

        $visibleSubjects = $viewer?->hasAnyRole('teacher') ? $viewer->assignedSubjectsForClass($className, $section) : [];
        $fullSubjectVisibility = ! $viewer?->hasAnyRole('teacher') || $viewer->isClassTeacherForClass($className, $section);

        $period = $this->resolvePeriod($request);
        $studentIds = Student::query()
            ->where('class_name', $className)
            ->where('section', $section)
            ->pluck('id');

        $summary = PerformanceSnapshot::query()
            ->whereIn('student_id', $studentIds)
            ->forPeriod($period)
            ->selectRaw("
                COUNT(*) as total,
                ROUND(AVG(overall_score), 1) as avg_overall,
                ROUND(AVG(academic_score), 1) as avg_academic,
                ROUND(AVG(attendance_score), 1) as avg_attendance,
                ROUND(AVG(behavior_score), 1) as avg_behavior,
                ROUND(AVG(participation_score), 1) as avg_participation,
                SUM(CASE WHEN alert_level = 'urgent' THEN 1 ELSE 0 END) as urgent,
                SUM(CASE WHEN alert_level = 'warning' THEN 1 ELSE 0 END) as warning,
                SUM(CASE WHEN alert_level = 'watch' THEN 1 ELSE 0 END) as watch,
                SUM(CASE WHEN overall_score >= 80 THEN 1 ELSE 0 END) as good_performers,
                SUM(CASE WHEN overall_score < 60 THEN 1 ELSE 0 END) as at_risk
            ")
            ->first();

        $subjectPerformance = Assessment::query()
            ->whereIn('student_id', $studentIds)
            ->whereYear('exam_date', substr($period, 0, 4))
            ->whereMonth('exam_date', substr($period, 5, 2))
            ->when(
                $viewer?->hasAnyRole('teacher') && ! $fullSubjectVisibility,
                fn ($query) => $query->whereIn('subject', $visibleSubjects)
            )
            ->groupBy('subject')
            ->selectRaw("
                subject,
                ROUND(AVG(percentage), 1) as class_avg,
                MIN(percentage) as min_score,
                MAX(percentage) as max_score,
                COUNT(*) as assessment_count
            ")
            ->orderBy('class_avg')
            ->get()
            ->map(function (Assessment $assessment) use ($period): array {
                $schoolAverage = Assessment::query()
                    ->where('subject', $assessment->subject)
                    ->whereYear('exam_date', substr($period, 0, 4))
                    ->whereMonth('exam_date', substr($period, 5, 2))
                    ->avg('percentage');

                return [
                    'subject' => $assessment->subject,
                    'class_avg' => round((float) $assessment->class_avg, 1),
                    'min_score' => round((float) $assessment->min_score, 1),
                    'max_score' => round((float) $assessment->max_score, 1),
                    'assessment_count' => (int) $assessment->assessment_count,
                    'school_gap' => round((float) $assessment->class_avg - (float) $schoolAverage, 1),
                ];
            });

        $classTrend = PerformanceSnapshot::query()
            ->whereIn('student_id', $studentIds)
            ->whereIn('snapshot_period', $this->trendAnalyzer->getLastPeriods($period, 6))
            ->groupBy('snapshot_period')
            ->selectRaw('snapshot_period, ROUND(AVG(overall_score), 1) as avg_score')
            ->orderBy('snapshot_period')
            ->get();

        $schoolTrend = PerformanceSnapshot::query()
            ->whereIn('snapshot_period', $this->trendAnalyzer->getLastPeriods($period, 6))
            ->groupBy('snapshot_period')
            ->selectRaw('snapshot_period, ROUND(AVG(overall_score), 1) as avg_score')
            ->orderBy('snapshot_period')
            ->get()
            ->keyBy('snapshot_period');

        $studentRanking = PerformanceSnapshot::query()
            ->whereIn('student_id', $studentIds)
            ->forPeriod($period)
            ->with('student:id,name,roll_number,photo_path')
            ->orderByDesc('overall_score')
            ->get([
                'student_id',
                'overall_score',
                'academic_score',
                'attendance_score',
                'alert_level',
                'trend_direction',
            ]);

        return response()->json([
            'class_name' => $className,
            'section' => $section,
            'period' => $period,
            'summary' => $summary,
            'subject_performance' => $subjectPerformance,
            'recommendations' => $this->classRecommendations($subjectPerformance->all(), $summary),
            'viewer_scope' => [
                'is_class_teacher' => $viewer?->hasAnyRole('teacher') ? $viewer->isClassTeacherForClass($className, $section) : false,
                'subjects' => $fullSubjectVisibility ? [] : $visibleSubjects,
            ],
            'class_trend' => $classTrend->map(fn ($point) => [
                'snapshot_period' => $point->snapshot_period,
                'class_avg' => round((float) $point->avg_score, 1),
                'school_avg' => round((float) ($schoolTrend[$point->snapshot_period]->avg_score ?? 0), 1),
            ])->values(),
            'student_ranking' => $studentRanking,
        ]);
    }

    public function teacherEffectiveness(Request $request): JsonResponse
    {
        /** @var User|null $viewer */
        $viewer = $request->user();
        $period = $this->resolvePeriod($request);
        $previousPeriod = Carbon::createFromFormat('Y-m', $period)->subMonth()->format('Y-m');

        $previous = Assessment::query()
            ->whereYear('exam_date', substr($previousPeriod, 0, 4))
            ->whereMonth('exam_date', substr($previousPeriod, 5, 2))
            ->whereNotNull('teacher_id')
            ->when($viewer?->hasAnyRole('teacher'), fn ($query) => $query->where('teacher_id', $viewer->id))
            ->groupBy('teacher_id', 'subject')
            ->selectRaw('teacher_id, subject, ROUND(AVG(percentage), 1) as prev_avg')
            ->get()
            ->keyBy(fn ($row) => "{$row->teacher_id}_{$row->subject}");

        $effectiveness = Assessment::query()
            ->whereYear('exam_date', substr($period, 0, 4))
            ->whereMonth('exam_date', substr($period, 5, 2))
            ->whereNotNull('teacher_id')
            ->when($viewer?->hasAnyRole('teacher'), fn ($query) => $query->where('teacher_id', $viewer->id))
            ->with('teacher:id,name')
            ->groupBy('teacher_id', 'subject')
            ->selectRaw("
                teacher_id,
                subject,
                ROUND(AVG(percentage), 1) as avg_score,
                COUNT(DISTINCT student_id) as student_count,
                COUNT(*) as assessment_count
            ")
            ->orderByDesc('avg_score')
            ->get()
            ->map(function (Assessment $row) use ($previous): array {
                $key = "{$row->teacher_id}_{$row->subject}";
                $previousValue = $previous->get($key);

                return [
                    'teacher_id' => $row->teacher_id,
                    'teacher_name' => $row->teacher?->name ?? 'Unknown teacher',
                    'subject' => $row->subject,
                    'avg_score' => round((float) $row->avg_score, 1),
                    'student_count' => (int) $row->student_count,
                    'assessment_count' => (int) $row->assessment_count,
                    'change' => $previousValue ? round((float) $row->avg_score - (float) $previousValue->prev_avg, 1) : null,
                ];
            });

        return response()->json([
            'period' => $period,
            'data' => $effectiveness,
        ]);
    }

    public function customReport(Request $request): JsonResponse
    {
        $data = $request->validate([
            'period' => ['required', 'date_format:Y-m'],
            'classes' => ['nullable', 'array'],
            'classes.*' => ['string'],
            'sections' => ['nullable', 'array'],
            'sections.*' => ['string'],
            'alert_levels' => ['nullable', 'array'],
            'alert_levels.*' => ['in:urgent,warning,watch,none'],
            'min_risk_score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'sort_by' => ['nullable', 'in:risk_score,overall_score,academic_score,attendance_score'],
            'sort_dir' => ['nullable', 'in:asc,desc'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
            'format' => ['nullable', 'in:json,csv,pdf'],
        ]);

        $query = PerformanceSnapshot::query()
            ->forPeriod($data['period'])
            ->with('student:id,name,class_name,section,roll_number,guardian_phone');

        if (! empty($data['classes'])) {
            $query->whereHas('student', fn (Builder $studentQuery) => $studentQuery->whereIn('class_name', $data['classes']));
        }

        if (! empty($data['sections'])) {
            $query->whereHas('student', fn (Builder $studentQuery) => $studentQuery->whereIn('section', $data['sections']));
        }

        if (! empty($data['alert_levels'])) {
            $query->whereIn('alert_level', $data['alert_levels']);
        }

        if (isset($data['min_risk_score'])) {
            $query->where('risk_score', '>=', $data['min_risk_score']);
        }

        $sortBy = $data['sort_by'] ?? 'risk_score';
        $sortDir = $data['sort_dir'] ?? 'desc';
        $limit = $data['limit'] ?? 100;
        $results = $query->orderBy($sortBy, $sortDir)->limit($limit)->get();

        if (($data['format'] ?? 'json') === 'csv') {
            $csv = $this->reportExportService->toCsv(
                ['student', 'class', 'section', 'risk_score', 'overall_score', 'alert_level'],
                $results->map(fn ($row) => [
                    $row->student?->name,
                    $row->student?->class_name,
                    $row->student?->section,
                    $row->risk_score,
                    $row->overall_score,
                    $row->alert_level,
                ])->all()
            );

            return response($csv, 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => "attachment; filename=\"pps-custom-{$data['period']}.csv\"",
            ]);
        }

        if (($data['format'] ?? 'json') === 'pdf') {
            $lines = $this->reportExportService->buildAtRiskList($results);

            return response($this->pdfService->render('PPS custom report', $lines), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => "attachment; filename=\"pps-custom-{$data['period']}.pdf\"",
            ]);
        }

        return response()->json([
            'period' => $data['period'],
            'total' => $results->count(),
            'data' => $results,
        ]);
    }

    private function getRecentEvents(int $studentId, string $period): array
    {
        $start = Carbon::createFromFormat('Y-m', $period)->subMonth()->startOfMonth();
        $end = Carbon::createFromFormat('Y-m', $period)->endOfMonth();
        $events = collect();

        BehaviorCard::query()
            ->where('student_id', $studentId)
            ->whereBetween('issued_at', [$start, $end])
            ->get()
            ->each(fn (BehaviorCard $card) => $events->push([
                'type' => 'behavior_card',
                'level' => $card->card_type,
                'text' => $card->reason,
                'date' => $card->issued_at?->toDateString(),
                'display_date' => $card->issued_at?->format('d M'),
            ]));

        Extracurricular::query()
            ->where('student_id', $studentId)
            ->whereBetween('event_date', [$start, $end])
            ->whereNotNull('achievement')
            ->get()
            ->each(fn (Extracurricular $activity) => $events->push([
                'type' => 'achievement',
                'level' => 'green',
                'text' => "{$activity->activity_name}: {$activity->achievement}",
                'date' => $activity->event_date?->toDateString(),
                'display_date' => $activity->event_date?->format('d M'),
            ]));

        PpsAlert::query()
            ->where('student_id', $studentId)
            ->whereBetween('created_at', [$start, $end])
            ->get()
            ->each(fn (PpsAlert $alert) => $events->push([
                'type' => 'alert',
                'level' => $alert->alert_level,
                'text' => collect($alert->trigger_reasons)->pluck('detail')->implode(', '),
                'date' => $alert->created_at?->toDateString(),
                'display_date' => $alert->created_at?->format('d M'),
            ]));

        CounselingSession::query()
            ->where('student_id', $studentId)
            ->whereBetween('session_date', [$start->toDateString(), $end->toDateString()])
            ->get()
            ->each(fn (CounselingSession $session) => $events->push([
                'type' => 'counseling',
                'level' => $session->progress_status ?? 'support',
                'text' => $session->session_type === 'psychometric'
                    ? 'Psychometric review completed.'
                    : 'Counseling follow-up recorded.',
                'date' => $session->session_date?->toDateString(),
                'display_date' => $session->session_date?->format('d M'),
            ]));

        return $events
            ->sortByDesc('date')
            ->values()
            ->take(10)
            ->toArray();
    }

    private function resolvePeriod(Request $request): string
    {
        return $request->string('period')->toString() ?: now()->format('Y-m');
    }

    private function applyTeacherStudentScope(Builder $query, User $teacher): void
    {
        $assignments = $teacher->teacherAssignments()
            ->get(['class_name', 'section'])
            ->unique(fn ($assignment) => "{$assignment->class_name}:{$assignment->section}");

        if ($assignments->isEmpty()) {
            $query->whereRaw('1 = 0');
            return;
        }

        $query->where(function (Builder $classQuery) use ($assignments): void {
            $assignments->each(function ($assignment) use ($classQuery): void {
                $classQuery->orWhere(function (Builder $studentClassQuery) use ($assignment): void {
                    $studentClassQuery
                        ->where('class_name', $assignment->class_name)
                        ->where('section', $assignment->section);
                });
            });
        });
    }

    private function classRecommendations(array $subjects, ?object $summary): array
    {
        $recommendations = [];

        foreach ($subjects as $subject) {
            if (($subject['class_avg'] ?? 0) < 60) {
                $recommendations[] = "Most students are weak in {$subject['subject']}; review teaching approach and add remediation time.";
            }
        }

        if (($summary->urgent ?? 0) > 0 && ($summary->warning ?? 0) > 2) {
            $recommendations[] = 'The section has clustered risk and needs a teacher-principal review.';
        }

        return array_values(array_unique($recommendations));
    }
}

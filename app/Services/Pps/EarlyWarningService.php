<?php

namespace App\Services\Pps;

use App\Models\Pps\EarlyWarning;
use App\Models\Pps\PerformanceSnapshot;
use App\Models\Pps\PpsNotificationLog;
use App\Models\Pps\SchoolPpsConfig;
use App\Models\Pps\TeacherAssignment;
use App\Support\StudentTaxonomyFilter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use SmsCore\Models\Student;
use SmsCore\Models\Teacher;
use SmsCore\Models\User;

/**
 * Predicts which students will cross the early-warning risk threshold within
 * 1, 3 or 6 months, records one open warning per student, and notifies the
 * people who can act: class teacher, teachers of the declining subjects,
 * scoped Vice Principals, and (for imminent) the principal.
 */
class EarlyWarningService
{
    public const HORIZONS = [1, 3, 6];
    public const SCORE_KEYS = ['attendance', 'academic', 'behavior', 'participation', 'extracurricular'];
    private const DRIVER_SLOPE = -2.0; // per month

    public function __construct(private readonly ForecastService $forecast)
    {
    }

    /**
     * @return array{period: string, scanned: int, created: int, updated: int, cleared: int, notified: int}
     */
    public function generate(string $period): array
    {
        $config = SchoolPpsConfig::current();
        $threshold = (float) $config->early_warning_risk_threshold;
        $minHistory = max(2, (int) $config->early_warning_min_history);

        $histories = PerformanceSnapshot::query()
            ->where('snapshot_period', '<=', $period)
            ->orderBy('student_id')
            ->orderBy('snapshot_period')
            ->get(['student_id', 'snapshot_period', 'overall_score', 'risk_score', 'academic_score', 'attendance_score', 'behavior_score', 'participation_score', 'extracurricular_score', 'snapshot_data'])
            ->groupBy('student_id');

        $stats = ['period' => $period, 'scanned' => 0, 'created' => 0, 'updated' => 0, 'cleared' => 0, 'notified' => 0];
        $flaggedStudentIds = [];

        foreach ($histories as $studentId => $history) {
            /** @var Collection<int, PerformanceSnapshot> $history */
            if ($history->last()->snapshot_period !== $period) {
                continue; // no snapshot this period — nothing new to judge
            }
            $stats['scanned']++;
            if ($history->count() < $minHistory) {
                continue;
            }

            $prediction = $this->predict($history->slice(-6)->values(), $threshold);
            if ($prediction === null) {
                continue;
            }

            $flaggedStudentIds[] = (int) $studentId;
            $warning = EarlyWarning::query()->where('student_id', $studentId)->where('snapshot_period', $period)->first();
            $attributes = [
                'horizon_months' => $prediction['horizon'],
                'category' => EarlyWarning::CATEGORIES[$prediction['horizon']],
                'current_risk' => $prediction['current_risk'],
                'projected_risk' => $prediction['projected_risk'],
                'projected_overall' => $prediction['projected_overall'],
                'drivers' => $prediction['drivers'],
            ];
            if ($warning === null) {
                $warning = EarlyWarning::query()->create($attributes + ['student_id' => $studentId, 'snapshot_period' => $period, 'status' => 'open']);
                $stats['created']++;
            } else {
                $warning->fill($attributes)->save();
                $stats['updated']++;
            }

            // Older open rows for the same student are superseded by this period's row.
            EarlyWarning::query()->where('student_id', $studentId)->where('snapshot_period', '<', $period)->open()
                ->update(['status' => 'cleared']);

            if ($warning->notified_at === null) {
                $stats['notified'] += count($this->notify($warning));
            }
        }

        // Students that were open and are no longer predicted to fall (or now have no snapshot) clear out.
        $stats['cleared'] += EarlyWarning::query()
            ->open()
            ->where('snapshot_period', '<', $period)
            ->whereNotIn('student_id', $flaggedStudentIds)
            ->update(['status' => 'cleared']);

        return $stats;
    }

    /**
     * @param  Collection<int, PerformanceSnapshot>  $history  oldest → newest, newest = the period being judged
     * @return array{horizon: int, current_risk: float, projected_risk: float, projected_overall: float, drivers: array}|null
     */
    public function predict(Collection $history, float $threshold): ?array
    {
        $risks = $history->pluck('risk_score')->map(fn ($v) => (float) $v)->all();
        $currentRisk = end($risks);
        if ($currentRisk >= $threshold) {
            return null; // already in the zone: the reactive alert system owns it
        }

        foreach (self::HORIZONS as $months) {
            $projected = round($this->forecast->projectAhead($risks, $months), 1);
            if ($projected >= $threshold) {
                $overall = $history->pluck('overall_score')->map(fn ($v) => (float) $v)->all();

                return [
                    'horizon' => $months,
                    'current_risk' => round($currentRisk, 1),
                    'projected_risk' => $projected,
                    'projected_overall' => round($this->forecast->projectAhead($overall, $months), 1),
                    'drivers' => $this->drivers($history),
                ];
            }
        }

        return null;
    }

    /** Declining component scores and subjects, steepest first. @return array<int, array{kind: string, key: string, slope: float, latest: float}> */
    private function drivers(Collection $history): array
    {
        $drivers = [];
        $priority = array_flip(self::SCORE_KEYS);

        foreach (self::SCORE_KEYS as $key) {
            $values = $history->pluck("{$key}_score")->map(fn ($v) => (float) $v)->all();
            [$slope] = $this->forecast->fit($values);
            if ($slope <= self::DRIVER_SLOPE) {
                $drivers[] = ['kind' => 'score', 'key' => $key, 'slope' => round($slope, 1), 'latest' => round(end($values), 1)];
            }
        }

        $subjectSeries = [];
        foreach ($history as $snapshot) {
            foreach (($snapshot->snapshot_data['subjects'] ?? []) as $subject => $data) {
                $subjectSeries[$subject][] = (float) ($data['avg'] ?? 0);
            }
        }
        foreach ($subjectSeries as $subject => $values) {
            if (count($values) < 2) {
                continue;
            }
            [$slope] = $this->forecast->fit($values);
            if ($slope <= self::DRIVER_SLOPE) {
                $drivers[] = ['kind' => 'subject', 'key' => (string) $subject, 'slope' => round($slope, 1), 'latest' => round(end($values), 1)];
            }
        }

        usort($drivers, function (array $a, array $b) use ($priority): int {
            if ($a['kind'] !== $b['kind']) {
                return $a['kind'] === 'score' ? -1 : 1;
            }
            if ($a['slope'] !== $b['slope']) {
                return $a['slope'] <=> $b['slope'];
            }
            if ($a['kind'] === 'score') {
                return ($priority[$a['key']] ?? 99) <=> ($priority[$b['key']] ?? 99);
            }

            return strcmp($a['key'], $b['key']);
        });

        return $drivers;
    }

    /**
     * @return array<int, array{user_id: int|null, role: string, name: string|null}>
     */
    public function notify(EarlyWarning $warning): array
    {
        $student = Student::query()->with('currentEnrollment.section.classLevel')->find($warning->student_id);
        if ($student === null) {
            return [];
        }

        $classLevel = $student->currentEnrollment?->section?->classLevel;
        $sectionId = $student->section_id;
        $subjectDrivers = collect($warning->drivers ?? [])->where('kind', 'subject')->pluck('key')->map(fn ($s) => mb_strtolower((string) $s))->all();

        $recipients = [];
        $assignments = $sectionId === null ? collect() : TeacherAssignment::query()
            ->where('section_id', $sectionId)
            ->with(['teacher:id,full_name,user_id', 'subject:id,full_name'])
            ->get();

        foreach ($assignments as $assignment) {
            if ($assignment->teacher === null) {
                continue;
            }
            if ($assignment->is_class_teacher) {
                $recipients[] = ['teacher' => $assignment->teacher, 'role' => 'class_teacher', 'subject' => null];
            }
            $subjectName = mb_strtolower((string) ($assignment->subject?->full_name ?? ''));
            if ($assignment->subject_id !== null && $subjectName !== '' && $this->subjectMatches($subjectName, $subjectDrivers)) {
                $recipients[] = ['teacher' => $assignment->teacher, 'role' => 'subject_teacher', 'subject' => $assignment->subject->full_name];
            }
        }

        foreach ($this->vicePrincipalsFor($classLevel?->version_id, $classLevel?->level_id) as $vp) {
            $recipients[] = ['teacher' => $vp, 'role' => 'vice_principal', 'subject' => null];
        }

        $sent = [];
        $seenUsers = [];
        foreach ($recipients as $r) {
            /** @var Teacher $teacher */
            $teacher = $r['teacher'];
            $key = $teacher->user_id ?? ('t'.$teacher->id.$r['role']);
            if (isset($seenUsers[$key]) && $r['role'] !== 'subject_teacher') {
                continue; // same person already reached in a stronger role
            }
            $seenUsers[$key] = true;
            if ($this->writeLog($warning, $student, $r['role'], $teacher->user_id, $teacher->full_name, $r['subject'])) {
                $sent[] = ['user_id' => $teacher->user_id, 'role' => $r['role'], 'name' => $teacher->full_name];
            }
        }

        if ($warning->category === 'imminent') {
            foreach (User::query()->where('role', 'principal')->get() as $principal) {
                if ($this->writeLog($warning, $student, 'principal', $principal->id, $principal->name, null)) {
                    $sent[] = ['user_id' => $principal->id, 'role' => 'principal', 'name' => $principal->name];
                }
            }
        }

        $warning->forceFill(['notified_at' => now()])->save();

        return $sent;
    }

    private function subjectMatches(string $assignedSubject, array $drivers): bool
    {
        foreach ($drivers as $driver) {
            if ($driver === $assignedSubject || str_contains($assignedSubject, $driver) || str_contains($driver, $assignedSubject)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Active teachers on a VP designation whose scope covers (version, level),
     * or who have no scope rows at all (unscoped = whole school).
     *
     * @return Collection<int, Teacher>
     */
    public function vicePrincipalsFor(?int $versionId, ?int $levelId): Collection
    {
        return Teacher::query()
            ->where('is_active', true)
            ->whereHas('designation', fn ($q) => $q->where('title', 'like', 'VP%')->orWhere('title', 'like', 'Vice Principal%'))
            ->where(function ($q) use ($versionId, $levelId): void {
                $q->whereDoesntHave('levelScopes');
                if ($versionId !== null && $levelId !== null) {
                    $q->orWhereHas('levelScopes', fn ($s) => $s->where('version_id', $versionId)->where('level_id', $levelId));
                }
            })
            ->orderBy('id')
            ->get();
    }

    private function writeLog(EarlyWarning $warning, Student $student, string $role, ?int $userId, ?string $name, ?string $subject): bool
    {
        $type = 'early_warning_'.$warning->category;
        $exists = PpsNotificationLog::query()
            ->where('type', $type)
            ->where('recipient_role', $role)
            ->where('recipient_user_id', $userId)
            ->where('student_id', $student->id)
            ->where('snapshot_period', $warning->snapshot_period)
            ->exists();
        if ($exists) {
            return false;
        }

        $taxonomy = StudentTaxonomyFilter::present($student);
        $classLabel = trim(($taxonomy['class_name'] ?? '').' '.($taxonomy['section_name'] ?? ''));
        $drivers = collect($warning->drivers ?? []);
        $driverText = $drivers->map(fn (array $d) => sprintf('%s %s (%s%.1f/month)', ucfirst($d['key']), $d['kind'] === 'subject' ? 'marks' : 'score', $d['slope'] > 0 ? '+' : '', $d['slope']))->implode(', ');
        $horizonText = match ($warning->horizon_months) { 1 => 'within a month', 3 => 'within 3 months', default => 'within 6 months' };

        PpsNotificationLog::query()->create([
            'type' => $type,
            'channel' => 'database',
            'recipient_role' => $role,
            'recipient_user_id' => $userId,
            'student_id' => $student->id,
            'snapshot_period' => $warning->snapshot_period,
            'subject' => mb_substr(sprintf('Early warning (%s): %s, %s%s', $warning->category, $student->name, $classLabel, $subject ? ' — '.$subject : ''), 0, 180),
            'body' => sprintf(
                "%s is predicted to reach risk %.0f %s (now %.0f). Drivers: %s. Please review and plan support%s.",
                $student->name, $warning->projected_risk, $horizonText, $warning->current_risk,
                $driverText === '' ? 'general decline' : $driverText,
                $subject ? " in {$subject}" : '',
            ),
            'meta' => [
                'source' => 'early_warning',
                'early_warning_id' => $warning->id,
                'horizon_months' => $warning->horizon_months,
                'category' => $warning->category,
                'drivers' => $warning->drivers,
                'teacher_name' => $name,
                'subject' => $subject,
            ],
            'generated_at' => now(),
        ]);

        return true;
    }
}

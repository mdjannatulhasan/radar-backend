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

    // notify(), recipients and query helpers are added in Task 4.
    /** @return array<int, array> */
    public function notify(EarlyWarning $warning): array
    {
        $warning->forceFill(['notified_at' => now()])->save();

        return [];
    }
}

<?php

namespace App\Services\Pps;

use App\Models\Pps\PerformanceSnapshot;

class ForecastService
{
    public function forecastForStudent(int $studentId, string $period): array
    {
        $history = PerformanceSnapshot::query()
            ->where('student_id', $studentId)
            ->where('snapshot_period', '<=', $period)
            ->orderBy('snapshot_period')
            ->limit(6)
            ->get([
                'snapshot_period',
                'overall_score',
                'academic_score',
                'attendance_score',
                'behavior_score',
                'risk_score',
                'snapshot_data',
            ]);

        if ($history->isEmpty()) {
            return [
                'next_period' => $this->nextPeriod($period),
                'projected_overall' => null,
                'projected_risk' => null,
                'direction' => 'stable',
                'summary' => 'Not enough history is available for a forecast.',
            ];
        }

        $projectedOverall = $this->project($history->pluck('overall_score')->map(fn ($score) => (float) $score)->all());
        $projectedRisk = $this->project($history->pluck('risk_score')->map(fn ($score) => (float) $score)->all());
        $currentOverall = (float) $history->last()->overall_score;
        $delta = $projectedOverall - $currentOverall;
        $spread = max(2.0, min(8.0, abs($delta) * 0.6 + 2.0));

        $direction = match (true) {
            $delta >= 5 => 'improving',
            $delta <= -8 => 'rapid_decline',
            $delta <= -3 => 'declining',
            default => 'stable',
        };

        $riskZone = match (true) {
            $projectedRisk >= 60 => 'urgent',
            $projectedRisk >= 40 => 'warning',
            $projectedRisk >= 20 => 'watch',
            default => 'none',
        };

        return [
            'next_period' => $this->nextPeriod($period),
            'projected_overall' => round($projectedOverall, 1),
            'projected_range' => [
                'low' => round(max(0.0, $projectedOverall - $spread), 1),
                'high' => round(min(100.0, $projectedOverall + $spread), 1),
            ],
            'projected_risk' => round($projectedRisk, 1),
            'direction' => $direction,
            'risk_zone' => $riskZone,
            'subject_forecasts' => $this->subjectForecasts($history),
            'concern_flags' => $this->concernFlags($direction, $projectedRisk, $history),
            'summary' => $this->summaryFor($direction, $projectedOverall, $projectedRisk),
        ];
    }

    public function project(array $values): float
    {
        $count = count($values);
        if ($count === 0) {
            return 0.0;
        }

        if ($count === 1) {
            return (float) $values[0];
        }

        $xMean = (array_sum(range(1, $count)) / $count);
        $yMean = (array_sum($values) / $count);

        $numerator = 0.0;
        $denominator = 0.0;

        foreach ($values as $index => $value) {
            $x = $index + 1;
            $numerator += ($x - $xMean) * ($value - $yMean);
            $denominator += ($x - $xMean) ** 2;
        }

        $slope = $denominator > 0 ? $numerator / $denominator : 0.0;
        $intercept = $yMean - ($slope * $xMean);
        $nextX = $count + 1;

        return max(0.0, min(100.0, $intercept + ($slope * $nextX)));
    }

    private function summaryFor(string $direction, float $overall, float $risk): string
    {
        return match ($direction) {
            'improving' => "If current patterns hold, the next period should improve to roughly {$overall} with controlled risk.",
            'rapid_decline' => "If no intervention happens, the next period is likely to fall near {$overall} with risk around {$risk}.",
            'declining' => "The trend suggests further slippage next period unless attendance, engagement, or weak subjects are addressed.",
            default => "The current trend is broadly stable, with the next period likely to stay near {$overall}.",
        };
    }

    private function nextPeriod(string $period): string
    {
        $date = \Carbon\Carbon::createFromFormat('Y-m', $period)->addMonth();

        return $date->format('Y-m');
    }

    private function subjectForecasts($history): array
    {
        $subjects = [];

        foreach ($history as $snapshot) {
            foreach (($snapshot->snapshot_data['subjects'] ?? []) as $subject => $data) {
                $subjects[$subject] ??= [];
                $subjects[$subject][] = (float) ($data['avg'] ?? 0);
            }
        }

        return collect($subjects)
            ->map(fn (array $scores, string $subject) => [
                'subject' => $subject,
                'projected_score' => round($this->project($scores), 1),
            ])
            ->sortBy('projected_score')
            ->values()
            ->all();
    }

    private function concernFlags(string $direction, float $projectedRisk, $history): array
    {
        $flags = [];
        $latest = $history->last();

        if ($direction === 'rapid_decline') {
            $flags[] = 'overall_decline';
        }

        if ($projectedRisk >= 60) {
            $flags[] = 'urgent_risk_zone';
        }

        if (($latest?->attendance_score ?? 100) < 75) {
            $flags[] = 'attendance_drag';
        }

        return $flags;
    }
}

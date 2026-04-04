<?php

namespace App\Services\Pps;

use App\Models\Pps\Assessment;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class TrendAnalyzerService
{
    public function calcTrend(float $current, array $history): string
    {
        if ($history === []) {
            return 'stable';
        }

        $previousAverage = array_sum($history) / count($history);
        $change = $current - $previousAverage;

        return match (true) {
            $change >= 5 => 'up',
            $change <= -15 => 'rapid_down',
            $change <= -5 => 'down',
            default => 'stable',
        };
    }

    public function calcSubjectTrend(int $studentId, string $subject, string $currentPeriod): array
    {
        $periods = $this->getLastPeriods($currentPeriod, 6);

        return Assessment::query()
            ->where('student_id', $studentId)
            ->where('subject', $subject)
            ->whereIn(DB::raw("strftime('%Y-%m', exam_date)"), $periods)
            ->groupBy(DB::raw("strftime('%Y-%m', exam_date)"))
            ->selectRaw("strftime('%Y-%m', exam_date) as period, AVG(percentage) as avg_pct")
            ->orderBy('period')
            ->get()
            ->map(fn (Assessment $assessment) => [
                'period' => $assessment->period,
                'score' => round((float) $assessment->avg_pct, 1),
            ])
            ->toArray();
    }

    public function getLastPeriods(string $currentPeriod, int $count): array
    {
        $periods = [];
        $date = Carbon::createFromFormat('Y-m', $currentPeriod)->startOfMonth();

        for ($index = 0; $index < $count; $index++) {
            $periods[] = $date->copy()->subMonths($index)->format('Y-m');
        }

        return array_reverse($periods);
    }
}


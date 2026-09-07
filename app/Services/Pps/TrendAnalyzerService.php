<?php

namespace App\Services\Pps;

use App\Models\Pps\ComputedScore;
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

    public function calcSubjectTrend(int $studentId, string $subjectName, string $currentPeriod): array
    {
        $periods = $this->getLastPeriods($currentPeriod, 6);

        return ComputedScore::query()
            ->join('pps_exams', 'pps_exams.id', '=', 'pps_computed_scores.exam_id')
            ->join('subjects', 'subjects.id', '=', 'pps_computed_scores.subject_id')
            ->where('pps_computed_scores.student_id', $studentId)
            ->where('subjects.full_name', $subjectName)
            ->whereIn(DB::raw("to_char(pps_exams.exam_date, 'YYYY-MM')"), $periods)
            ->groupBy(DB::raw("to_char(pps_exams.exam_date, 'YYYY-MM')"))
            ->selectRaw("to_char(pps_exams.exam_date, 'YYYY-MM') as period, AVG(pps_computed_scores.percentage) as avg_pct")
            ->orderBy('period')
            ->get()
            ->map(fn ($row) => [
                'period' => $row->period,
                'score'  => round((float) $row->avg_pct, 1),
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

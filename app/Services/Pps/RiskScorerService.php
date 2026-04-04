<?php

namespace App\Services\Pps;

use App\Models\Pps\BehaviorCard;
use App\Models\Pps\ClassroomRating;
use App\Models\Pps\PerformanceSnapshot;
use App\Models\Pps\SchoolPpsConfig;

class RiskScorerService
{
    public function calculate(
        int $studentId,
        array $scores,
        int $year,
        int $month,
        SchoolPpsConfig $config
    ): float {
        $risk = 0.0;

        $risk += $this->attendanceRisk($scores['attendance_score'], $config);
        $risk += $this->academicRisk($scores['academic_score']);
        $risk += $this->behaviorRisk($studentId, $year, $month);
        $risk += $this->trendRisk($studentId, $scores['academic_score'], $year, $month);
        $risk += $this->behavioralFlagRisk($studentId, $year, $month);
        $risk += $this->combinedDropRisk($scores, $studentId, $year, $month);

        return min(100.0, round($risk, 2));
    }

    public function calculateFromScores(array $scores, SchoolPpsConfig $config): float
    {
        $risk = 0.0;
        $risk += $this->attendanceRisk((float) $scores['attendance_score'], $config);
        $risk += $this->academicRisk((float) $scores['academic_score']);
        $risk += max(0.0, min(25.0, (70.0 - (float) $scores['behavior_score']) * 0.5));
        $risk += max(0.0, min(20.0, (65.0 - (float) $scores['participation_score']) * 0.4));
        $risk += max(0.0, min(10.0, (55.0 - (float) $scores['extracurricular_score']) * 0.2));

        return min(100.0, round($risk, 2));
    }

    private function attendanceRisk(float $score, SchoolPpsConfig $config): float
    {
        return match (true) {
            $score < $config->threshold_attendance_urgent => 30.0,
            $score < $config->threshold_attendance_warning => 15.0,
            $score < $config->threshold_attendance_watch => 5.0,
            default => 0.0,
        };
    }

    private function academicRisk(float $score): float
    {
        return match (true) {
            $score < 33 => 35.0,
            $score < 45 => 25.0,
            $score < 55 => 15.0,
            $score < 65 => 5.0,
            default => 0.0,
        };
    }

    private function behaviorRisk(int $studentId, int $year, int $month): float
    {
        $cards = BehaviorCard::query()
            ->where('student_id', $studentId)
            ->whereYear('issued_at', $year)
            ->whereMonth('issued_at', $month)
            ->selectRaw("
                SUM(CASE WHEN card_type = 'red' THEN 1 ELSE 0 END) as reds,
                SUM(CASE WHEN card_type = 'yellow' THEN 1 ELSE 0 END) as yellows,
                SUM(CASE WHEN is_integrity_violation = 1 THEN 1 ELSE 0 END) as integrity
            ")
            ->first();

        $risk = 0.0;
        $risk += (int) ($cards?->reds ?? 0) * 25.0;
        $risk += (int) ($cards?->yellows ?? 0) * 5.0;
        $risk += (int) ($cards?->integrity ?? 0) * 15.0;

        return min(50.0, $risk);
    }

    private function trendRisk(int $studentId, float $currentAcademic, int $year, int $month): float
    {
        $period = sprintf('%04d-%02d', $year, $month);

        $history = PerformanceSnapshot::query()
            ->where('student_id', $studentId)
            ->where('snapshot_period', '<', $period)
            ->orderByDesc('snapshot_period')
            ->limit(2)
            ->pluck('academic_score')
            ->toArray();

        if (count($history) < 2) {
            return 0.0;
        }

        $previousAverage = array_sum($history) / count($history);
        $drop = $previousAverage - $currentAcademic;

        return match (true) {
            $drop >= 25 => 20.0,
            $drop >= 15 => 15.0,
            $drop >= 10 => 8.0,
            default => 0.0,
        };
    }

    private function behavioralFlagRisk(int $studentId, int $year, int $month): float
    {
        $flags = ClassroomRating::query()
            ->where('student_id', $studentId)
            ->whereYear('rating_period', $year)
            ->whereMonth('rating_period', $month)
            ->whereNotNull('behavioral_flag')
            ->count();

        return $flags > 0 ? 10.0 : 0.0;
    }

    private function combinedDropRisk(array $scores, int $studentId, int $year, int $month): float
    {
        $period = sprintf('%04d-%02d', $year, $month);

        $previous = PerformanceSnapshot::query()
            ->where('student_id', $studentId)
            ->where('snapshot_period', '<', $period)
            ->orderByDesc('snapshot_period')
            ->first();

        if (! $previous) {
            return 0.0;
        }

        $droppedCategories = 0;
        if (($previous->academic_score - $scores['academic_score']) > 5) {
            $droppedCategories++;
        }
        if (($previous->attendance_score - $scores['attendance_score']) > 5) {
            $droppedCategories++;
        }
        if (($previous->behavior_score - $scores['behavior_score']) > 5) {
            $droppedCategories++;
        }

        return match (true) {
            $droppedCategories >= 3 => 25.0,
            $droppedCategories === 2 => 10.0,
            default => 0.0,
        };
    }
}

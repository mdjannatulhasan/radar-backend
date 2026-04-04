<?php

namespace App\Services\Pps;

use App\Models\Pps\PerformanceSnapshot;
use App\Models\Pps\SchoolPpsConfig;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class WhatIfAnalyzerService
{
    public function __construct(
        private readonly RiskScorerService $riskScorer,
    ) {
    }

    public function analyze(int $studentId, string $period, array $hypotheticals): array
    {
        $snapshot = PerformanceSnapshot::query()
            ->where('student_id', $studentId)
            ->forPeriod($period)
            ->first();

        if (! $snapshot) {
            throw new ModelNotFoundException("No snapshot found for student {$studentId} in {$period}.");
        }

        $config = SchoolPpsConfig::current();
        $results = [];

        foreach ($hypotheticals as $scenario) {
            $modifiedScores = $this->applyHypothetical($snapshot, $scenario);
            $projectedOverall = $this->recalculateOverall($modifiedScores, $config);
            $projectedRisk = $this->riskScorer->calculateFromScores($modifiedScores, $config);

            $results[] = [
                'scenario' => $scenario,
                'current_overall' => round((float) $snapshot->overall_score, 1),
                'projected_overall' => round($projectedOverall, 1),
                'change' => round($projectedOverall - $snapshot->overall_score, 1),
                'current_risk' => round((float) $snapshot->risk_score, 1),
                'projected_risk' => round($projectedRisk, 1),
                'projected_alert' => $this->determineAlertLevel($projectedRisk, $config),
            ];
        }

        return $results;
    }

    public function defaultScenarios(?PerformanceSnapshot $snapshot): array
    {
        if (! $snapshot) {
            return [];
        }

        return [
            ['type' => 'attendance', 'new_value' => min(100, $snapshot->attendance_score + 10)],
            ['type' => 'academic', 'new_value' => min(100, $snapshot->academic_score + 8)],
            ['type' => 'participation', 'new_value' => min(100, $snapshot->participation_score + 12)],
        ];
    }

    private function applyHypothetical(PerformanceSnapshot $snapshot, array $scenario): array
    {
        $scores = [
            'academic_score' => (float) $snapshot->academic_score,
            'attendance_score' => (float) $snapshot->attendance_score,
            'behavior_score' => (float) $snapshot->behavior_score,
            'participation_score' => (float) $snapshot->participation_score,
            'extracurricular_score' => (float) $snapshot->extracurricular_score,
        ];

        $key = "{$scenario['type']}_score";
        if (array_key_exists($key, $scores)) {
            $scores[$key] = max(0.0, min(100.0, (float) $scenario['new_value']));
        }

        return $scores;
    }

    private function recalculateOverall(array $scores, SchoolPpsConfig $config): float
    {
        return
            ($scores['academic_score'] * $config->weight_academic) +
            ($scores['attendance_score'] * $config->weight_attendance) +
            ($scores['behavior_score'] * $config->weight_behavior) +
            ($scores['participation_score'] * $config->weight_participation) +
            ($scores['extracurricular_score'] * $config->weight_extracurricular);
    }

    private function determineAlertLevel(float $riskScore, SchoolPpsConfig $config): string
    {
        return match (true) {
            $riskScore >= $config->threshold_risk_urgent => 'urgent',
            $riskScore >= $config->threshold_risk_warning => 'warning',
            $riskScore >= $config->threshold_risk_watch => 'watch',
            default => 'none',
        };
    }
}

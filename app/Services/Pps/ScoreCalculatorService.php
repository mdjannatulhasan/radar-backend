<?php

namespace App\Services\Pps;

use App\Models\Pps\Assessment;
use App\Models\Pps\AttendanceRecord;
use App\Models\Pps\BehaviorCard;
use App\Models\Pps\ClassroomRating;
use App\Models\Pps\Extracurricular;
use App\Models\Pps\PerformanceSnapshot;
use App\Models\Pps\SchoolPpsConfig;
use Carbon\Carbon;

class ScoreCalculatorService
{
    public function __construct(
        private readonly RiskScorerService $riskScorer,
        private readonly TrendAnalyzerService $trendAnalyzer,
        private readonly AlertTriggerService $alertTrigger,
    ) {
    }

    public function calculateForStudent(int $studentId, string $period): PerformanceSnapshot
    {
        [$year, $month] = array_map('intval', explode('-', $period));
        $config = SchoolPpsConfig::current();

        $scores = [
            'academic_score' => $this->calcAcademic($studentId, $year, $month),
            'attendance_score' => $this->calcAttendance($studentId, $year, $month),
            'behavior_score' => $this->calcBehavior($studentId, $year, $month),
            'participation_score' => $this->calcParticipation($studentId, $year, $month),
            'extracurricular_score' => $this->calcExtracurricular($studentId, $year, $month),
        ];

        $overall = round(
            ($scores['academic_score'] * $config->weight_academic) +
            ($scores['attendance_score'] * $config->weight_attendance) +
            ($scores['behavior_score'] * $config->weight_behavior) +
            ($scores['participation_score'] * $config->weight_participation) +
            ($scores['extracurricular_score'] * $config->weight_extracurricular),
            2
        );

        $history = PerformanceSnapshot::query()
            ->where('student_id', $studentId)
            ->where('snapshot_period', '<', $period)
            ->orderByDesc('snapshot_period')
            ->limit(3)
            ->pluck('overall_score')
            ->toArray();

        $riskScore = $this->riskScorer->calculate($studentId, $scores, $year, $month, $config);
        $alertLevel = $this->determineAlertLevel($riskScore, $config);
        $trend = $this->trendAnalyzer->calcTrend($overall, $history);

        $snapshot = PerformanceSnapshot::query()->updateOrCreate(
            ['student_id' => $studentId, 'snapshot_period' => $period],
            [
                ...$scores,
                'overall_score' => $overall,
                'risk_score' => $riskScore,
                'alert_level' => $alertLevel,
                'trend_direction' => $trend,
                'snapshot_data' => $this->buildDetailData($studentId, $year, $month),
                'calculated_at' => now(),
            ]
        );

        $this->alertTrigger->evaluate($studentId, $period);

        return $snapshot;
    }

    private function calcAcademic(int $studentId, int $year, int $month): float
    {
        $anchor = Carbon::create($year, $month, 1)->startOfMonth();
        $periodStart = $anchor->copy()->subMonths(2)->startOfMonth();
        $periodEnd = $anchor->copy()->endOfMonth();

        $result = Assessment::query()
            ->where('student_id', $studentId)
            ->whereBetween('exam_date', [$periodStart, $periodEnd])
            ->selectRaw('AVG(percentage) as avg_pct, COUNT(*) as total')
            ->first();

        return ($result?->total ?? 0) > 0 ? round((float) $result->avg_pct, 2) : 70.0;
    }

    private function calcAttendance(int $studentId, int $year, int $month): float
    {
        $result = AttendanceRecord::query()
            ->where('student_id', $studentId)
            ->whereYear('date', $year)
            ->whereMonth('date', $month)
            ->whereNull('period')
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN status IN ('present', 'late') THEN 1 ELSE 0 END) as attended
            ")
            ->first();

        if (! $result || ! $result->total) {
            return 100.0;
        }

        return round(($result->attended / $result->total) * 100, 2);
    }

    private function calcBehavior(int $studentId, int $year, int $month): float
    {
        $cards = BehaviorCard::query()
            ->where('student_id', $studentId)
            ->whereYear('issued_at', $year)
            ->whereMonth('issued_at', $month)
            ->selectRaw("
                SUM(CASE WHEN card_type = 'green' THEN 1 ELSE 0 END) as greens,
                SUM(CASE WHEN card_type = 'yellow' THEN 1 ELSE 0 END) as yellows,
                SUM(CASE WHEN card_type = 'red' THEN 1 ELSE 0 END) as reds
            ")
            ->first();

        $score = 85.0;
        $score += (int) ($cards?->greens ?? 0) * 5;
        $score -= (int) ($cards?->yellows ?? 0) * 10;
        $score -= (int) ($cards?->reds ?? 0) * 25;

        return max(0.0, min(100.0, $score));
    }

    private function calcParticipation(int $studentId, int $year, int $month): float
    {
        $result = ClassroomRating::query()
            ->where('student_id', $studentId)
            ->whereYear('rating_period', $year)
            ->whereMonth('rating_period', $month)
            ->selectRaw("
                AVG(
                    (COALESCE(participation, 3) + COALESCE(attentiveness, 3) +
                    COALESCE(group_work, 3) + COALESCE(creativity, 3)) / 4.0
                ) as avg_rating,
                COUNT(*) as total
            ")
            ->first();

        if (! $result || ! $result->total) {
            return 60.0;
        }

        return round(($result->avg_rating / 5.0) * 100, 2);
    }

    private function calcExtracurricular(int $studentId, int $year, int $month): float
    {
        $anchor = Carbon::create($year, $month, 1)->startOfMonth();
        $periodStart = $anchor->copy()->subMonths(5)->startOfMonth();
        $periodEnd = $anchor->copy()->endOfMonth();

        $activities = Extracurricular::query()
            ->where('student_id', $studentId)
            ->whereBetween('event_date', [$periodStart, $periodEnd])
            ->selectRaw('SUM(achievement_level) as level_sum, COUNT(*) as total')
            ->first();

        if (! $activities || ! $activities->total) {
            return 50.0;
        }

        $score = 50.0 + ($activities->total * 5) + (($activities->level_sum ?? 0) * 2);

        return min(100.0, round($score, 2));
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

    private function buildDetailData(int $studentId, int $year, int $month): array
    {
        $periodStart = Carbon::create($year, $month, 1)->startOfMonth();
        $periodEnd = Carbon::create($year, $month, 1)->endOfMonth();
        $currentPeriod = sprintf('%04d-%02d', $year, $month);

        $subjects = Assessment::query()
            ->where('student_id', $studentId)
            ->whereBetween('exam_date', [$periodStart, $periodEnd])
            ->groupBy('subject')
            ->selectRaw('subject, AVG(percentage) as avg, COUNT(*) as total')
            ->get()
            ->mapWithKeys(fn (Assessment $assessment) => [
                $assessment->subject => [
                    'avg' => round((float) $assessment->avg, 1),
                    'count' => (int) $assessment->total,
                    'trend' => $this->trendAnalyzer->calcSubjectTrend($studentId, $assessment->subject, $currentPeriod),
                ],
            ])
            ->toArray();

        $attendance = AttendanceRecord::query()
            ->where('student_id', $studentId)
            ->whereYear('date', $year)
            ->whereMonth('date', $month)
            ->whereNull('period')
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent,
                SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late
            ")
            ->first();

        $cards = BehaviorCard::query()
            ->where('student_id', $studentId)
            ->whereYear('issued_at', $year)
            ->whereMonth('issued_at', $month)
            ->selectRaw("
                SUM(CASE WHEN card_type = 'green' THEN 1 ELSE 0 END) as green,
                SUM(CASE WHEN card_type = 'yellow' THEN 1 ELSE 0 END) as yellow,
                SUM(CASE WHEN card_type = 'red' THEN 1 ELSE 0 END) as red
            ")
            ->first();

        return [
            'subjects' => $subjects,
            'attendance' => [
                'total' => (int) ($attendance?->total ?? 0),
                'absent' => (int) ($attendance?->absent ?? 0),
                'late' => (int) ($attendance?->late ?? 0),
            ],
            'cards' => [
                'green' => (int) ($cards?->green ?? 0),
                'yellow' => (int) ($cards?->yellow ?? 0),
                'red' => (int) ($cards?->red ?? 0),
            ],
            'calculated_at' => now()->toISOString(),
        ];
    }
}

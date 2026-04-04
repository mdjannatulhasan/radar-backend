<?php

namespace Tests\Unit\Pps;

use App\Models\Pps\BehaviorCard;
use App\Models\Pps\ClassroomRating;
use App\Models\Pps\PerformanceSnapshot;
use App\Models\Pps\SchoolPpsConfig;
use App\Models\Student;
use App\Services\Pps\RiskScorerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RiskScorerServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_risk_score_caps_at_one_hundred_for_severe_decline(): void
    {
        $config = SchoolPpsConfig::current();
        $student = Student::query()->create([
            'student_code' => 'PPS-020',
            'name' => 'Tariq Hasan',
            'class_name' => '9',
            'section' => 'A',
            'roll_number' => 20,
        ]);

        PerformanceSnapshot::query()->create([
            'student_id' => $student->id,
            'snapshot_period' => '2026-02',
            'academic_score' => 84,
            'attendance_score' => 94,
            'behavior_score' => 84,
            'participation_score' => 80,
            'extracurricular_score' => 68,
            'overall_score' => 84,
            'risk_score' => 10,
            'alert_level' => 'none',
            'trend_direction' => 'stable',
        ]);

        PerformanceSnapshot::query()->create([
            'student_id' => $student->id,
            'snapshot_period' => '2026-03',
            'academic_score' => 82,
            'attendance_score' => 92,
            'behavior_score' => 80,
            'participation_score' => 76,
            'extracurricular_score' => 66,
            'overall_score' => 81,
            'risk_score' => 15,
            'alert_level' => 'watch',
            'trend_direction' => 'down',
        ]);

        BehaviorCard::query()->create([
            'student_id' => $student->id,
            'card_type' => 'red',
            'reason' => 'Major disciplinary incident',
            'is_integrity_violation' => true,
            'issued_at' => '2026-04-10 09:00:00',
        ]);

        ClassroomRating::query()->create([
            'student_id' => $student->id,
            'subject' => 'Mathematics',
            'rating_period' => '2026-04-12',
            'behavioral_flag' => 'Withdrawn in class',
        ]);

        $service = app(RiskScorerService::class);

        $risk = $service->calculate(
            $student->id,
            [
                'academic_score' => 52,
                'attendance_score' => 68,
                'behavior_score' => 55,
                'participation_score' => 50,
                'extracurricular_score' => 58,
            ],
            2026,
            4,
            $config
        );

        $this->assertSame(100.0, $risk);
    }
}


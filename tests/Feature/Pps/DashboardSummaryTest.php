<?php

namespace Tests\Feature\Pps;

use App\Models\Pps\PerformanceSnapshot;
use App\Models\Pps\PpsAlert;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DashboardSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_summary_returns_phase_one_payload(): void
    {
        $principal = User::query()->create([
            'name' => 'Principal',
            'email' => 'principal@example.test',
            'role' => 'principal',
            'password' => Hash::make('password'),
        ]);

        $studentA = Student::query()->create([
            'student_code' => 'PPS-001',
            'name' => 'Rafi Islam',
            'class_name' => '8',
            'section' => 'A',
            'roll_number' => 1,
        ]);

        $studentB = Student::query()->create([
            'student_code' => 'PPS-002',
            'name' => 'Sadia Rahman',
            'class_name' => '8',
            'section' => 'A',
            'roll_number' => 2,
        ]);

        PerformanceSnapshot::query()->create([
            'student_id' => $studentA->id,
            'snapshot_period' => '2026-04',
            'academic_score' => 51,
            'attendance_score' => 68,
            'behavior_score' => 62,
            'participation_score' => 55,
            'extracurricular_score' => 60,
            'overall_score' => 58,
            'risk_score' => 78,
            'alert_level' => 'urgent',
            'trend_direction' => 'rapid_down',
            'snapshot_data' => [],
            'calculated_at' => now(),
        ]);

        PerformanceSnapshot::query()->create([
            'student_id' => $studentB->id,
            'snapshot_period' => '2026-04',
            'academic_score' => 80,
            'attendance_score' => 94,
            'behavior_score' => 88,
            'participation_score' => 76,
            'extracurricular_score' => 70,
            'overall_score' => 82,
            'risk_score' => 12,
            'alert_level' => 'none',
            'trend_direction' => 'up',
            'snapshot_data' => [],
            'calculated_at' => now(),
        ]);

        PpsAlert::query()->create([
            'student_id' => $studentA->id,
            'snapshot_period' => '2026-04',
            'alert_level' => 'urgent',
            'trigger_reasons' => [
                ['type' => 'combined_drop', 'detail' => 'Multiple indicators fell together.', 'value' => 3],
            ],
        ]);

        $response = $this->signInPps($principal)->getJson('/api/v1/pps/dashboard/summary?period=2026-04');

        $response
            ->assertOk()
            ->assertJsonPath('period', '2026-04')
            ->assertJsonPath('summary.total_students', 2)
            ->assertJsonPath('summary.urgent_count', 1)
            ->assertJsonPath('summary.good_count', 1)
            ->assertJsonCount(1, 'urgent_students')
            ->assertJsonCount(1, 'active_alerts')
            ->assertJsonCount(1, 'class_overview');
    }

    public function test_alert_can_be_resolved_from_api(): void
    {
        $teacher = User::query()->create([
            'name' => 'Teacher',
            'email' => 'teacher@example.test',
            'role' => 'teacher',
            'password' => Hash::make('password'),
        ]);

        $student = Student::query()->create([
            'student_code' => 'PPS-003',
            'name' => 'Mariam Akter',
            'class_name' => '7',
            'section' => 'B',
            'roll_number' => 3,
        ]);

        $alert = PpsAlert::query()->create([
            'student_id' => $student->id,
            'snapshot_period' => '2026-04',
            'alert_level' => 'warning',
            'trigger_reasons' => [
                ['type' => 'attendance', 'detail' => 'Attendance dropped.', 'value' => 72],
            ],
        ]);

        $this->signInPps($teacher)->patchJson("/api/v1/pps/alerts/{$alert->id}/resolve", [
            'resolution_note' => 'Parent contacted.',
            'resolution_action' => 'parent_meeting',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Alert resolved.');

        $this->assertNotNull($alert->fresh()?->resolved_at);
        $this->assertSame('parent_meeting', $alert->fresh()?->resolution_action);
    }

    public function test_dashboard_summary_requires_authentication_and_sets_security_headers(): void
    {
        $principal = User::query()->create([
            'name' => 'Principal Secure',
            'email' => 'principal-secure@example.test',
            'role' => 'principal',
            'password' => Hash::make('password'),
        ]);

        $this->getJson('/api/v1/pps/dashboard/summary')
            ->assertUnauthorized();

        $this->signInPps($principal)
            ->getJson('/api/v1/pps/dashboard/summary')
            ->assertOk()
            ->assertHeader('Cache-Control', 'must-revalidate, no-cache, no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY');
    }
}

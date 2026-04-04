<?php

namespace Tests\Feature\Pps;

use App\Models\Pps\Assessment;
use App\Models\Pps\ClassroomRating;
use App\Models\Pps\Extracurricular;
use App\Models\Pps\PerformanceSnapshot;
use App\Models\Pps\PpsAlert;
use App\Models\Pps\SchoolPpsConfig;
use App\Models\Pps\TeacherAssignment;
use App\Models\Student;
use App\Models\User;
use App\Services\Pps\ScoreCalculatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PhaseExpansionTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_profile_endpoint_returns_phase_two_payload(): void
    {
        $teacher = User::query()->create([
            'name' => 'Teacher User',
            'email' => 'teacher@example.test',
            'role' => 'teacher',
            'password' => Hash::make('password'),
        ]);

        $student = Student::query()->create([
            'student_code' => 'PPS-201',
            'name' => 'Rafi Islam',
            'class_name' => '8',
            'section' => 'A',
            'roll_number' => 7,
            'guardian_email' => 'guardian@example.test',
        ]);

        PerformanceSnapshot::query()->create([
            'student_id' => $student->id,
            'snapshot_period' => '2026-04',
            'academic_score' => 52,
            'attendance_score' => 68,
            'behavior_score' => 61,
            'participation_score' => 59,
            'extracurricular_score' => 72,
            'overall_score' => 61.8,
            'risk_score' => 55,
            'alert_level' => 'warning',
            'trend_direction' => 'down',
            'snapshot_data' => [
                'subjects' => [
                    'Mathematics' => ['avg' => 41, 'count' => 2, 'trend' => []],
                    'English' => ['avg' => 63, 'count' => 2, 'trend' => []],
                ],
                'attendance' => ['total' => 22, 'absent' => 4, 'late' => 2],
            ],
            'calculated_at' => now(),
        ]);

        ClassroomRating::query()->create([
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'subject' => 'Mathematics',
            'rating_period' => '2026-04-07',
            'participation' => 2,
            'attentiveness' => 3,
            'group_work' => 3,
            'creativity' => 2,
            'free_comment' => 'Needs more structured prompting.',
        ]);

        Extracurricular::query()->create([
            'student_id' => $student->id,
            'activity_name' => 'Science Club',
            'category' => 'club',
            'achievement' => 'Monthly showcase',
            'achievement_level' => 2,
            'event_date' => '2026-04-12',
        ]);

        PpsAlert::query()->create([
            'student_id' => $student->id,
            'snapshot_period' => '2026-04',
            'alert_level' => 'warning',
            'trigger_reasons' => [
                ['type' => 'combined_drop', 'detail' => 'Attendance and academic scores both fell.', 'value' => 2],
            ],
        ]);

        Assessment::query()->create([
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'subject' => 'Mathematics',
            'assessment_type' => 'class_test',
            'term' => '2026-term-1',
            'marks_obtained' => 41,
            'total_marks' => 100,
            'percentage' => 41,
            'exam_date' => '2026-04-11',
        ]);

        TeacherAssignment::query()->create([
            'teacher_id' => $teacher->id,
            'class_name' => '8',
            'section' => 'A',
            'subject' => 'Mathematics',
            'is_class_teacher' => false,
        ]);

        $response = $this->signInPps($teacher)->getJson("/api/v1/pps/students/{$student->id}?period=2026-04");

        $response
            ->assertOk()
            ->assertJsonPath('student.name', 'Rafi Islam')
            ->assertJsonPath('current_snapshot.alert_level', 'warning')
            ->assertJsonCount(1, 'active_alerts')
            ->assertJsonCount(1, 'teacher_comments')
            ->assertJsonCount(3, 'what_if_preview');
    }

    public function test_parent_report_endpoint_returns_simplified_payload(): void
    {
        $student = Student::query()->create([
            'student_code' => 'PPS-202',
            'name' => 'Nabila Sultana',
            'class_name' => '7',
            'section' => 'B',
            'roll_number' => 3,
            'guardian_email' => 'guardian@example.test',
        ]);

        PerformanceSnapshot::query()->create([
            'student_id' => $student->id,
            'snapshot_period' => '2026-04',
            'academic_score' => 76,
            'attendance_score' => 90,
            'behavior_score' => 82,
            'participation_score' => 74,
            'extracurricular_score' => 79,
            'overall_score' => 79.4,
            'risk_score' => 18,
            'alert_level' => 'none',
            'trend_direction' => 'up',
            'snapshot_data' => [
                'subjects' => [
                    'Mathematics' => ['avg' => 72, 'count' => 2, 'trend' => []],
                    'English' => ['avg' => 80, 'count' => 2, 'trend' => []],
                ],
                'attendance' => ['total' => 22, 'absent' => 2, 'late' => 1],
            ],
            'calculated_at' => now(),
        ]);

        $guardian = User::query()->create([
            'name' => 'Guardian User',
            'email' => 'guardian@example.test',
            'role' => 'guardian',
            'password' => Hash::make('password'),
        ]);

        $response = $this
            ->signInPps($guardian)
            ->getJson("/api/v1/pps/parents/my-children/{$student->id}/report?period=2026-04");

        $response
            ->assertOk()
            ->assertJsonPath('student.name', 'Nabila Sultana')
            ->assertJsonPath('attendance_days.present', 20)
            ->assertJsonPath('scores.Academic', 4)
            ->assertJsonStructure(['report_link', 'subject_notes', 'parent_advice']);
    }

    public function test_class_analytics_endpoint_returns_summary_and_ranking(): void
    {
        $teacher = User::query()->create([
            'name' => 'Math Teacher',
            'email' => 'math@example.test',
            'role' => 'teacher',
            'password' => Hash::make('password'),
        ]);

        $studentA = Student::query()->create([
            'student_code' => 'PPS-203',
            'name' => 'Student A',
            'class_name' => '9',
            'section' => 'A',
            'roll_number' => 1,
        ]);

        $studentB = Student::query()->create([
            'student_code' => 'PPS-204',
            'name' => 'Student B',
            'class_name' => '9',
            'section' => 'A',
            'roll_number' => 2,
        ]);

        foreach ([[$studentA, 71, 78], [$studentB, 64, 69]] as [$student, $academic, $overall]) {
            PerformanceSnapshot::query()->create([
                'student_id' => $student->id,
                'snapshot_period' => '2026-04',
                'academic_score' => $academic,
                'attendance_score' => 88,
                'behavior_score' => 80,
                'participation_score' => 74,
                'extracurricular_score' => 70,
                'overall_score' => $overall,
                'risk_score' => 24,
                'alert_level' => 'watch',
                'trend_direction' => 'stable',
                'snapshot_data' => [],
                'calculated_at' => now(),
            ]);

            Assessment::query()->create([
                'student_id' => $student->id,
                'teacher_id' => $teacher->id,
                'subject' => 'Mathematics',
                'assessment_type' => 'class_test',
                'term' => '2026-term-1',
                'marks_obtained' => $academic,
                'total_marks' => 100,
                'percentage' => $academic,
                'exam_date' => '2026-04-10',
            ]);
        }

        TeacherAssignment::query()->create([
            'teacher_id' => $teacher->id,
            'class_name' => '9',
            'section' => 'A',
            'subject' => 'Mathematics',
            'is_class_teacher' => true,
        ]);

        $principal = User::query()->create([
            'name' => 'Principal User',
            'email' => 'principal@example.test',
            'role' => 'principal',
            'password' => Hash::make('password'),
        ]);

        $response = $this->signInPps($principal)->getJson('/api/v1/pps/classes/9/A/analytics?period=2026-04');

        $response
            ->assertOk()
            ->assertJsonPath('summary.total', 2)
            ->assertJsonCount(1, 'subject_performance')
            ->assertJsonCount(2, 'student_ranking');

        $this->signInPps($teacher)
            ->getJson('/api/v1/pps/classes/9/A/analytics?period=2026-04')
            ->assertOk()
            ->assertJsonPath('viewer_scope.is_class_teacher', true);
    }

    public function test_student_list_and_alert_list_include_real_snapshot_deltas(): void
    {
        $principal = User::query()->create([
            'name' => 'Principal User',
            'email' => 'principal-list@example.test',
            'role' => 'principal',
            'password' => Hash::make('password'),
        ]);

        $student = Student::query()->create([
            'student_code' => 'PPS-205',
            'name' => 'Anika Sarker',
            'class_name' => '10',
            'section' => 'B',
            'roll_number' => 4,
            'guardian_name' => 'Dr. M. Sarker',
            'guardian_phone' => '+8801712345678',
        ]);

        PerformanceSnapshot::query()->create([
            'student_id' => $student->id,
            'snapshot_period' => '2026-03',
            'academic_score' => 71,
            'attendance_score' => 82,
            'behavior_score' => 74,
            'participation_score' => 72,
            'extracurricular_score' => 68,
            'overall_score' => 78.4,
            'risk_score' => 31,
            'alert_level' => 'watch',
            'trend_direction' => 'stable',
            'snapshot_data' => [],
            'calculated_at' => now(),
        ]);

        PerformanceSnapshot::query()->create([
            'student_id' => $student->id,
            'snapshot_period' => '2026-04',
            'academic_score' => 58,
            'attendance_score' => 72,
            'behavior_score' => 69,
            'participation_score' => 64,
            'extracurricular_score' => 63,
            'overall_score' => 68.2,
            'risk_score' => 76,
            'alert_level' => 'urgent',
            'trend_direction' => 'rapid_down',
            'snapshot_data' => [],
            'calculated_at' => now(),
        ]);

        PpsAlert::query()->create([
            'student_id' => $student->id,
            'snapshot_period' => '2026-04',
            'alert_level' => 'urgent',
            'trigger_reasons' => [
                ['type' => 'combined_drop', 'detail' => 'Academic and attendance indicators both fell.', 'value' => 2],
            ],
        ]);

        $studentsResponse = $this->signInPps($principal)->getJson('/api/v1/pps/students?period=2026-04');

        $studentsResponse
            ->assertOk()
            ->assertJsonPath('data.0.student.name', 'Anika Sarker')
            ->assertJsonPath('data.0.trend_delta', -10.2);

        $alertsResponse = $this->signInPps($principal)->getJson('/api/v1/pps/alerts');

        $alertsResponse
            ->assertOk()
            ->assertJsonPath('data.0.student.guardian_name', 'Dr. M. Sarker')
            ->assertJsonPath('data.0.snapshot.risk_score', 76)
            ->assertJsonPath('data.0.snapshot.trend_delta', -10.2);
    }

    public function test_teacher_cannot_access_principal_dashboard(): void
    {
        $teacher = User::query()->create([
            'name' => 'Teacher',
            'email' => 'teacher-role@example.test',
            'role' => 'teacher',
            'password' => Hash::make('password'),
        ]);

        $this->signInPps($teacher)
            ->getJson('/api/v1/pps/dashboard/summary')
            ->assertForbidden();
    }

    public function test_score_recalculation_creates_alert_when_thresholds_are_crossed(): void
    {
        SchoolPpsConfig::current();

        $student = Student::query()->create([
            'student_code' => 'PPS-205',
            'name' => 'Risk Student',
            'class_name' => '8',
            'section' => 'B',
        ]);

        PerformanceSnapshot::query()->create([
            'student_id' => $student->id,
            'snapshot_period' => '2026-03',
            'academic_score' => 82,
            'attendance_score' => 94,
            'behavior_score' => 84,
            'participation_score' => 80,
            'extracurricular_score' => 74,
            'overall_score' => 83,
            'risk_score' => 10,
            'alert_level' => 'none',
            'trend_direction' => 'stable',
            'snapshot_data' => [],
            'calculated_at' => now(),
        ]);

        \App\Models\Pps\Assessment::query()->create([
            'student_id' => $student->id,
            'subject' => 'Mathematics',
            'assessment_type' => 'class_test',
            'term' => '2026-term-1',
            'marks_obtained' => 28,
            'total_marks' => 100,
            'percentage' => 28,
            'exam_date' => '2026-04-12',
        ]);

        foreach (range(1, 10) as $day) {
            \App\Models\Pps\AttendanceRecord::query()->create([
                'student_id' => $student->id,
                'date' => sprintf('2026-04-%02d', $day),
                'status' => $day <= 6 ? 'absent' : 'present',
            ]);
        }

        app(ScoreCalculatorService::class)->calculateForStudent($student->id, '2026-04');

        $this->assertDatabaseCount('pps_alerts', 1);
        $this->assertDatabaseHas('pps_alerts', [
            'student_id' => $student->id,
        ]);
    }

    public function test_report_generation_supports_csv_and_pdf_formats(): void
    {
        $principal = User::query()->create([
            'name' => 'Principal',
            'email' => 'principal-reports@example.test',
            'role' => 'principal',
            'password' => Hash::make('password'),
        ]);

        $student = Student::query()->create([
            'student_code' => 'PPS-206',
            'name' => 'Report Student',
            'class_name' => '9',
            'section' => 'C',
            'roll_number' => 4,
        ]);

        PerformanceSnapshot::query()->create([
            'student_id' => $student->id,
            'snapshot_period' => '2026-04',
            'academic_score' => 74,
            'attendance_score' => 88,
            'behavior_score' => 83,
            'participation_score' => 78,
            'extracurricular_score' => 69,
            'overall_score' => 78,
            'risk_score' => 22,
            'alert_level' => 'watch',
            'trend_direction' => 'stable',
            'snapshot_data' => [],
            'calculated_at' => now(),
        ]);

        $this->signInPps($principal)
            ->get('/api/v1/pps/reports/generate/student_card?student_id='.$student->id.'&period=2026-04&format=csv')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $this->signInPps($principal)
            ->get('/api/v1/pps/reports/generate/student_card?student_id='.$student->id.'&period=2026-04&format=pdf')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_student_context_endpoint_respects_limited_teacher_visibility(): void
    {
        $teacher = User::query()->create([
            'name' => 'Teacher User',
            'email' => 'teacher-context@example.test',
            'role' => 'teacher',
            'password' => Hash::make('password'),
        ]);

        $student = Student::query()->create([
            'student_code' => 'PPS-207',
            'name' => 'Context Student',
            'class_name' => '8',
            'section' => 'A',
            'guardian_email' => 'guardian-context@example.test',
            'family_status' => 'single parent',
            'economic_status' => 'scholarship supported',
            'health_notes' => 'Seasonal asthma',
            'special_needs' => ['dyslexia_support'],
            'confidential_context' => 'Teacher should not see this text.',
        ]);

        TeacherAssignment::query()->create([
            'teacher_id' => $teacher->id,
            'class_name' => '8',
            'section' => 'A',
            'subject' => 'Mathematics',
            'is_class_teacher' => false,
        ]);

        $response = $this->signInPps($teacher)
            ->getJson("/api/v1/pps/students/{$student->id}/context");

        $response
            ->assertOk()
            ->assertJsonPath('context.restricted', true)
            ->assertJsonMissingPath('context.confidential_context')
            ->assertJsonPath('context.special_circumstance', true);
    }

    public function test_teacher_student_list_is_limited_to_assigned_classes(): void
    {
        $teacher = User::query()->create([
            'name' => 'Scoped Teacher',
            'email' => 'scoped-teacher@example.test',
            'role' => 'teacher',
            'password' => Hash::make('password'),
        ]);

        $visibleStudent = Student::query()->create([
            'student_code' => 'PPS-301',
            'name' => 'Visible Student',
            'class_name' => '10',
            'section' => 'A',
        ]);

        $hiddenStudent = Student::query()->create([
            'student_code' => 'PPS-302',
            'name' => 'Hidden Student',
            'class_name' => '10',
            'section' => 'B',
        ]);

        TeacherAssignment::query()->create([
            'teacher_id' => $teacher->id,
            'class_name' => '10',
            'section' => 'A',
            'subject' => 'Mathematics',
        ]);

        foreach ([$visibleStudent, $hiddenStudent] as $student) {
            PerformanceSnapshot::query()->create([
                'student_id' => $student->id,
                'snapshot_period' => '2026-04',
                'academic_score' => 70,
                'attendance_score' => 85,
                'behavior_score' => 80,
                'participation_score' => 75,
                'extracurricular_score' => 68,
                'overall_score' => 75,
                'risk_score' => 22,
                'alert_level' => 'watch',
                'trend_direction' => 'stable',
                'snapshot_data' => [],
                'calculated_at' => now(),
            ]);
        }

        $this->signInPps($teacher)
            ->getJson('/api/v1/pps/students?period=2026-04')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.student.name', 'Visible Student');
    }

    public function test_notification_run_generates_logs_for_principal(): void
    {
        $principal = User::query()->create([
            'name' => 'Principal Notifications',
            'email' => 'principal-notify@example.test',
            'role' => 'principal',
            'password' => Hash::make('password'),
        ]);

        $guardian = User::query()->create([
            'name' => 'Guardian Notifications',
            'email' => 'guardian-notify@example.test',
            'role' => 'guardian',
            'password' => Hash::make('password'),
        ]);

        $student = Student::query()->create([
            'student_code' => 'PPS-208',
            'name' => 'Notify Student',
            'class_name' => '9',
            'section' => 'A',
            'guardian_email' => $guardian->email,
        ]);

        PerformanceSnapshot::query()->create([
            'student_id' => $student->id,
            'snapshot_period' => '2026-04',
            'academic_score' => 50,
            'attendance_score' => 68,
            'behavior_score' => 71,
            'participation_score' => 63,
            'extracurricular_score' => 65,
            'overall_score' => 61,
            'risk_score' => 52,
            'alert_level' => 'warning',
            'trend_direction' => 'down',
            'snapshot_data' => [],
            'calculated_at' => now(),
        ]);

        PpsAlert::query()->create([
            'student_id' => $student->id,
            'snapshot_period' => '2026-04',
            'alert_level' => 'warning',
            'trigger_reasons' => [
                ['type' => 'combined_drop', 'detail' => 'Academic and attendance both fell.', 'value' => 2],
            ],
            'notified_to' => [
                ['role' => 'principal', 'channel' => 'database'],
                ['role' => 'guardian', 'channel' => 'email'],
            ],
        ]);

        $this->signInPps($principal)
            ->postJson('/api/v1/pps/notifications/run/alerts', ['period' => '2026-04'])
            ->assertOk()
            ->assertJsonPath('created', 2);

        $this->assertDatabaseCount('pps_notification_logs', 2);
        $this->assertDatabaseHas('pps_notification_logs', [
            'type' => 'alert_notification',
            'recipient_role' => 'principal',
            'student_id' => $student->id,
        ]);
    }

    public function test_full_data_export_report_returns_csv(): void
    {
        $principal = User::query()->create([
            'name' => 'Principal Export',
            'email' => 'principal-export@example.test',
            'role' => 'principal',
            'password' => Hash::make('password'),
        ]);

        $student = Student::query()->create([
            'student_code' => 'PPS-209',
            'name' => 'Export Student',
            'class_name' => '10',
            'section' => 'B',
            'guardian_email' => 'guardian-export@example.test',
            'family_status' => 'stable',
            'private_tuition_subjects' => [['subject' => 'Mathematics']],
        ]);

        PerformanceSnapshot::query()->create([
            'student_id' => $student->id,
            'snapshot_period' => '2026-04',
            'academic_score' => 81,
            'attendance_score' => 90,
            'behavior_score' => 86,
            'participation_score' => 78,
            'extracurricular_score' => 72,
            'overall_score' => 82,
            'risk_score' => 15,
            'alert_level' => 'none',
            'trend_direction' => 'up',
            'snapshot_data' => [],
            'calculated_at' => now(),
        ]);

        $this->signInPps($principal)
            ->get('/api/v1/pps/reports/generate/full_data_export?period=2026-04&format=csv')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    }
}

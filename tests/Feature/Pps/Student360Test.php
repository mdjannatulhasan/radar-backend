<?php

namespace Tests\Feature\Pps;

use App\Models\Pps\PerformanceSnapshot;
use App\Models\Pps\PpsAlert;
use App\Models\Pps\PrivateTuition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class Student360Test extends TestCase
{
    use RefreshDatabase;

    public function test_private_tuition_row_links_student_subject_and_teacher(): void
    {
        $teacherUser = $this->createUser([
            'name' => 'Math Teacher', 'email' => 'mt@example.test', 'role' => 'teacher',
            'password' => Hash::make('password'),
        ]);
        $teacher = $this->makeTeacher($teacherUser);
        $student = $this->makeStudent([
            'student_code' => 'PPS-360-1', 'name' => 'Halima Demo',
            'class_name' => '8', 'section' => 'A', 'roll_number' => 14,
        ]);

        $tuition = PrivateTuition::create([
            'student_id' => $student->id,
            'subject_id' => $this->subject('Mathematics')->id,
            'subject_name' => 'Mathematics',
            'teacher_id' => $teacher->id,
            'hours_per_week' => 3,
            'started_on' => '2026-03-01',
        ]);

        $this->assertSame('Math Teacher', $tuition->teacher->full_name);
        $this->assertSame('Mathematics', $tuition->subject->full_name);
        $this->assertSame($student->id, $tuition->student->id);
        $this->assertNull($tuition->ended_on);
    }

    public function test_marks_grid_groups_by_exam_and_limits_years(): void
    {
        $student = $this->makeStudent([
            'student_code' => 'PPS-360-2', 'name' => 'Grid Student',
            'class_name' => '8', 'section' => 'A', 'roll_number' => 1,
        ]);
        $peer = $this->makeStudent([
            'student_code' => 'PPS-360-3', 'name' => 'Peer Student',
            'class_name' => '8', 'section' => 'A', 'roll_number' => 2,
        ]);

        // 2024 and 2026 exams, Mathematics; the peer scores higher in 2026.
        $this->recordExamResult($student, 'Mathematics', '2024-06-15', 70);
        $this->recordExamResult($student, 'Mathematics', '2026-06-15', 40);
        $this->recordExamResult($peer, 'Mathematics', '2026-06-15', 80);

        $service = app(\App\Services\Pps\Student360Service::class);

        $all = $service->buildMarksGrid($student->fresh(), 3);
        $this->assertSame([2026, 2024], $all['available_years']);
        $this->assertCount(2, $all['columns']);
        $this->assertSame(2024, $all['columns'][0]['academic_year']);

        $row = $all['rows'][0];
        $this->assertSame('Mathematics', $row['subject']);
        $cell2026 = $row['cells'][(string) $all['columns'][1]['exam_id']];
        $this->assertSame(40.0, $cell2026['pct']);
        $this->assertSame(60.0, $cell2026['class_avg']);
        $this->assertSame(2, $cell2026['rank']);
        $this->assertSame(2, $cell2026['cohort']);
        $this->assertSame('down', $row['trend']);
        $this->assertSame(-30.0, $row['delta']);

        $one = $service->buildMarksGrid($student->fresh(), 1);
        $this->assertCount(1, $one['columns']);
        $this->assertSame(2026, $one['columns'][0]['academic_year']);
    }

    public function test_build_returns_full_payload_with_tuition_flag(): void
    {
        $principal = $this->createUser([
            'name' => 'Principal', 'email' => 'principal@example.test', 'role' => 'principal',
            'password' => Hash::make('password'),
        ]);
        $teacherUser = $this->createUser([
            'name' => 'Math Teacher', 'email' => 'mt2@example.test', 'role' => 'teacher',
            'password' => Hash::make('password'),
        ]);
        $classTeacherUser = $this->createUser([
            'name' => 'Class Teacher', 'email' => 'ct@example.test', 'role' => 'teacher',
            'password' => Hash::make('password'),
        ]);
        $student = $this->makeStudent([
            'student_code' => 'PPS-360-4', 'name' => 'Flag Student',
            'class_name' => '8', 'section' => 'A', 'roll_number' => 5,
            'guardian_name' => 'Guardian One', 'guardian_phone' => '+8801000000000',
            'private_tuition_subjects' => [['subject' => 'English', 'hours_per_week' => 2, 'tutor_name' => 'Outside Sir']],
        ]);
        $peer = $this->makeStudent([
            'student_code' => 'PPS-360-5', 'name' => 'Peer Two',
            'class_name' => '8', 'section' => 'A', 'roll_number' => 6,
        ]);

        $this->assignTeacher($teacherUser, '8', 'A', 'Mathematics');
        $this->assignTeacher($classTeacherUser, '8', 'A', null, true);

        $this->recordExamResult($student, 'Mathematics', '2026-06-15', 35);
        $this->recordExamResult($peer, 'Mathematics', '2026-06-15', 75);

        PerformanceSnapshot::query()->create([
            'student_id' => $student->id, 'snapshot_period' => '2026-08',
            'academic_score' => 60, 'attendance_score' => 70, 'behavior_score' => 60,
            'participation_score' => 60, 'extracurricular_score' => 60, 'overall_score' => 62,
            'risk_score' => 30, 'alert_level' => 'watch', 'trend_direction' => 'stable',
            'snapshot_data' => ['subjects' => [], 'attendance' => []], 'calculated_at' => now(),
        ]);
        PerformanceSnapshot::query()->create([
            'student_id' => $student->id, 'snapshot_period' => '2026-09',
            'academic_score' => 45, 'attendance_score' => 26, 'behavior_score' => 37,
            'participation_score' => 35, 'extracurricular_score' => 46, 'overall_score' => 38.6,
            'risk_score' => 71.8, 'alert_level' => 'urgent', 'trend_direction' => 'rapid_down',
            'snapshot_data' => ['subjects' => [], 'attendance' => []], 'calculated_at' => now(),
        ]);
        PpsAlert::query()->create([
            'student_id' => $student->id, 'snapshot_period' => '2026-09', 'alert_level' => 'urgent',
            'trigger_reasons' => [['type' => 'academic_fail_zone', 'detail' => 'Academic score dropped below 40% this period.', 'value' => 45]],
        ]);
        PrivateTuition::create([
            'student_id' => $student->id,
            'subject_id' => $this->subject('Mathematics')->id,
            'subject_name' => 'Mathematics',
            'teacher_id' => $this->makeTeacher($teacherUser)->id,
            'hours_per_week' => 3,
        ]);

        $payload = app(\App\Services\Pps\Student360Service::class)
            ->build($student->fresh(), $principal, '2026-09', 3);

        $this->assertSame('Flag Student', $payload['student']['name']);
        $this->assertSame('8', $payload['student']['class_name']);
        $this->assertSame(38.6, $payload['snapshot']['overall_score']);
        $this->assertSame(-23.4, $payload['snapshot']['overall_delta']);
        $this->assertSame('2026-08', $payload['snapshot']['previous_period']);

        $this->assertNotEmpty($payload['why']);
        $this->assertLessThanOrEqual(3, count($payload['why']));
        $this->assertSame('Academic score dropped below 40% this period.', $payload['why'][0]['text']);

        $mathRow = collect($payload['marks_grid']['rows'])->firstWhere('subject', 'Mathematics');
        $this->assertNotNull($mathRow['tuition']);
        $this->assertTrue($mathRow['tuition']['is_school_teacher']);
        $this->assertTrue($mathRow['tuition']['teaches_this_class']);
        $this->assertTrue($mathRow['tuition_flag']);

        $this->assertCount(2, $payload['tuitions']);
        $this->assertSame('declared', collect($payload['tuitions'])->firstWhere('subject', 'English')['source']);

        $this->assertSame('Class Teacher', $payload['people']['class_teacher']['name']);
        $this->assertSame('Math Teacher', $payload['people']['subject_teachers'][0]['name']);
        $this->assertSame('Guardian One', $payload['people']['guardian']['name']);

        $this->assertSame('academic', $payload['signals']['scores'][0]['key']);
        $this->assertSame(-15.0, $payload['signals']['scores'][0]['delta']);
        $this->assertArrayHasKey('wellbeing', $payload['signals']);
        $this->assertIsArray($payload['timeline']);
        $this->assertArrayHasKey('projected_overall', $payload['forecast']);
        $this->assertSame([], $payload['notifications']);
    }

    public function test_principal_can_fetch_360_and_notify_teachers(): void
    {
        $principal = $this->createUser([
            'name' => 'Principal', 'email' => 'principal2@example.test', 'role' => 'principal',
            'password' => Hash::make('password'),
        ]);
        $mathUser = $this->createUser([
            'name' => 'Math Teacher', 'email' => 'mt3@example.test', 'role' => 'teacher',
            'password' => Hash::make('password'),
        ]);
        $classUser = $this->createUser([
            'name' => 'Class Teacher', 'email' => 'ct3@example.test', 'role' => 'teacher',
            'password' => Hash::make('password'),
        ]);
        $student = $this->makeStudent([
            'student_code' => 'PPS-360-6', 'name' => 'Notify Student',
            'class_name' => '8', 'section' => 'A', 'roll_number' => 9,
        ]);
        $this->assignTeacher($mathUser, '8', 'A', 'Mathematics');
        $this->assignTeacher($classUser, '8', 'A', null, true);
        $this->recordExamResult($student, 'Mathematics', '2026-06-15', 30);

        $this->signInPps($principal)
            ->getJson("/api/v1/pps/students/{$student->id}/360?years=2")
            ->assertOk()
            ->assertJsonPath('student.name', 'Notify Student')
            ->assertJsonPath('years', 2)
            ->assertJsonCount(1, 'marks_grid.columns');

        $mathId = $this->subject('Mathematics')->id;

        $response = $this->signInPps($principal)->postJson("/api/v1/pps/students/{$student->id}/notify-teachers", [
            'subject_ids' => [$mathId],
            'include_class_teacher' => true,
            'message' => 'Please start a weekly remedial plan.',
        ]);

        $response->assertCreated()->assertJsonCount(2, 'sent');

        $this->assertDatabaseHas('pps_notification_logs', [
            'type' => 'student_360_teacher_alert',
            'recipient_role' => 'subject_teacher',
            'recipient_user_id' => $mathUser->id,
            'student_id' => $student->id,
            'body' => 'Please start a weekly remedial plan.',
        ]);
        $this->assertDatabaseHas('pps_notification_logs', [
            'type' => 'student_360_teacher_alert',
            'recipient_role' => 'class_teacher',
            'recipient_user_id' => $classUser->id,
        ]);

        $this->signInPps($principal)
            ->getJson("/api/v1/pps/students/{$student->id}/360")
            ->assertOk()
            ->assertJsonCount(2, 'notifications');

        // Nothing selected → 422.
        $this->signInPps($principal)
            ->postJson("/api/v1/pps/students/{$student->id}/notify-teachers", ['subject_ids' => [], 'include_class_teacher' => false])
            ->assertStatus(422);

        // Teachers cannot trigger notifications.
        $this->signInPps($mathUser)
            ->postJson("/api/v1/pps/students/{$student->id}/notify-teachers", ['subject_ids' => [$mathId]])
            ->assertForbidden();
    }
}

<?php

namespace Tests\Feature\Pps;

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
}

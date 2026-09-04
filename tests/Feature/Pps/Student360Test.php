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
}

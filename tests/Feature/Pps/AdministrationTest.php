<?php

namespace Tests\Feature\Pps;

use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdministrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_can_manage_admin_catalog_and_students(): void
    {
        $superadmin = User::query()->create([
            'name' => 'Super Admin',
            'email' => 'superadmin@example.test',
            'role' => 'superadmin',
            'password' => Hash::make('password'),
        ]);

        $teacher = User::query()->create([
            'name' => 'Teacher',
            'email' => 'teacher@example.test',
            'role' => 'teacher',
            'password' => Hash::make('password'),
        ]);

        $session = $this->signInPps($superadmin);

        $departmentId = $session->postJson('/api/v1/pps/admin/departments', [
            'name' => 'Science',
            'code' => 'SCI',
        ])->assertCreated()->json('department.id');

        $session->postJson('/api/v1/pps/admin/class-sections', [
            'class_name' => '10',
            'section' => 'A',
            'department_id' => $departmentId,
            'capacity' => 45,
        ])->assertCreated();

        $subjectId = $session->postJson('/api/v1/pps/admin/subjects', [
            'name' => 'Mathematics',
            'code' => 'MTH',
            'department_id' => $departmentId,
        ])->assertCreated()->json('subject.id');

        $session->postJson('/api/v1/pps/admin/exams', [
            'title' => 'Half Yearly Mathematics',
            'code' => '10A-MTH-HY',
            'assessment_type' => 'mid_term',
            'term' => '2026-T1',
            'total_marks' => 100,
            'exam_date' => '2026-04-20',
            'class_name' => '10',
            'section' => 'A',
            'department_id' => $departmentId,
            'subject_id' => $subjectId,
        ])->assertCreated();

        $session->postJson('/api/v1/pps/admin/teacher-assignments', [
            'teacher_id' => $teacher->id,
            'class_name' => '10',
            'section' => 'A',
            'subject' => 'Mathematics',
            'is_class_teacher' => true,
        ])->assertCreated();

        $session->postJson('/api/v1/pps/admin/students', [
            'student_code' => 'RADAR-001',
            'name' => 'Rafi Islam',
            'class_name' => '10',
            'section' => 'A',
            'roll_number' => 1,
        ])->assertCreated();

        $session->postJson('/api/v1/pps/admin/bulk/students', [
            'rows' => [
                [
                    'student_code' => 'RADAR-002',
                    'name' => 'Nabila Rahman',
                    'class_name' => '10',
                    'section' => 'A',
                    'roll_number' => 2,
                ],
            ],
        ])->assertCreated()->assertJsonPath('created', 1);

        $session->getJson('/api/v1/pps/admin/overview')
            ->assertOk()
            ->assertJsonPath('summary.departments', 1)
            ->assertJsonPath('summary.class_sections', 1)
            ->assertJsonPath('summary.subjects', 1)
            ->assertJsonPath('summary.exams', 1)
            ->assertJsonPath('summary.students', 2)
            ->assertJsonPath('summary.teacher_assignments', 1);
    }

    public function test_teacher_bulk_import_accepts_student_codes_only_for_assigned_scope(): void
    {
        $teacher = User::query()->create([
            'name' => 'Teacher',
            'email' => 'teacher@example.test',
            'role' => 'teacher',
            'password' => Hash::make('password'),
        ]);

        $allowedStudent = Student::query()->create([
            'student_code' => 'RADAR-101',
            'name' => 'Allowed Student',
            'class_name' => '8',
            'section' => 'A',
            'roll_number' => 1,
        ]);

        $blockedStudent = Student::query()->create([
            'student_code' => 'RADAR-102',
            'name' => 'Blocked Student',
            'class_name' => '9',
            'section' => 'B',
            'roll_number' => 2,
        ]);

        $this->app['db']->table('pps_teacher_assignments')->insert([
            'teacher_id' => $teacher->id,
            'class_name' => '8',
            'section' => 'A',
            'subject' => 'Mathematics',
            'is_class_teacher' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $session = $this->signInPps($teacher);

        $session->postJson('/api/v1/pps/assessments/bulk', [
            'rows' => [
                [
                    'student_code' => $allowedStudent->student_code,
                    'subject' => 'Mathematics',
                    'assessment_type' => 'class_test',
                    'term' => '2026-T1',
                    'marks_obtained' => 75,
                    'total_marks' => 100,
                    'exam_date' => '2026-04-12',
                ],
                [
                    'student_code' => $blockedStudent->student_code,
                    'subject' => 'Mathematics',
                    'assessment_type' => 'class_test',
                    'term' => '2026-T1',
                    'marks_obtained' => 65,
                    'total_marks' => 100,
                    'exam_date' => '2026-04-12',
                ],
            ],
        ])->assertCreated()
            ->assertJsonPath('imported', 1)
            ->assertJsonPath('failed', 1);

        $session->postJson('/api/v1/pps/attendance/bulk', [
            'rows' => [
                [
                    'student_code' => $allowedStudent->student_code,
                    'date' => '2026-04-12',
                    'status' => 'present',
                ],
                [
                    'student_code' => $blockedStudent->student_code,
                    'date' => '2026-04-12',
                    'status' => 'absent',
                ],
            ],
        ])->assertCreated()
            ->assertJsonPath('marked', 1)
            ->assertJsonPath('failed', 1);
    }
}

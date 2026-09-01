<?php

namespace Tests\Feature\Pps;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdministrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Walk the admin catalog end to end on the current schema.
     *
     * This test used to POST the single-tenant shapes: a `departments` row, a
     * class section identified by the strings `class_name` + `section`, a
     * subject with `name`/`code`, an exam carrying its own class, section and
     * subject. None of those exist. Departments and streams were duplicates of
     * one another and became `class_levels.group`; a class section is now
     * `class_levels` x `section_names`; an exam's class x subject scope moved
     * out of the exam row into `pps_exam_class_map`.
     *
     * The catalog it walks is therefore the real one, in dependency order:
     * section name -> class level -> class section -> subject -> exam ->
     * teacher -> assignment -> student.
     */
    public function test_superadmin_can_manage_admin_catalog_and_students(): void
    {
        $superadmin = $this->createUser([
            'name' => 'Super Admin',
            'email' => 'superadmin@example.test',
            'role' => 'superadmin',
            'password' => Hash::make('password'),
        ]);

        $session = $this->signInPps($superadmin);

        // Levels, versions and the academic year are school-wide vocabulary
        // with no admin endpoint of their own; the catalog below hangs off
        // them. The year matters: placing a student in a class writes an
        // enrollment against the current one, and the endpoint refuses with a
        // 422 rather than guessing if none is marked current.
        $levelId = $this->level('School')->id;
        $versionId = $this->version('Bangla')->id;
        $this->academicYear();

        // Departments are gone, deliberately, and say so rather than pretending
        // to still manage something.
        $session->postJson('/api/v1/pps/admin/departments', [
            'name' => 'Science',
            'code' => 'SCI',
        ])->assertStatus(410);

        $sectionNameId = $session->postJson('/api/v1/pps/admin/sections', [
            'name' => 'A',
        ])->assertCreated()->json('section.id');

        // `group` is what the old department row carried.
        $classLevelId = $session->postJson('/api/v1/pps/admin/classes', [
            'level_id' => $levelId,
            'version_id' => $versionId,
            'name' => '10',
            'group' => 'science',
            'numeric_order' => 11,
        ])->assertCreated()->json('class.id');

        $sectionId = $session->postJson('/api/v1/pps/admin/class-sections', [
            'class_level_id' => $classLevelId,
            'section_name_id' => $sectionNameId,
            'capacity' => 45,
        ])->assertCreated()->json('class_section.id');

        $subjectId = $session->postJson('/api/v1/pps/admin/subjects', [
            'full_name' => 'Mathematics',
            'short_name' => 'MTH',
            'level_id' => $levelId,
            'version_id' => $versionId,
        ])->assertCreated()->json('subject.id');

        $session->postJson('/api/v1/pps/admin/exams', [
            'title' => 'Half Yearly 2026',
            'exam_type_id' => $this->examType('mid_term', 'Mid Term')->id,
            'academic_year' => 2026,
            'term' => 1,
            'exam_date' => '2026-04-20',
            'scope' => 'class',
        ])->assertCreated();

        // teacher_id points at `teachers`, not at `users`: most staff have no
        // login, and a login is not evidence of being staff.
        $teacherId = $session->postJson('/api/v1/pps/admin/teachers', [
            'name' => 'Mariam Rahman',
            'email' => 'mariam.rahman@example.test',
            'short_code' => 'MR',
        ])->assertCreated()->json('teacher.id');

        $session->postJson('/api/v1/pps/admin/teacher-assignments', [
            'teacher_id' => $teacherId,
            'section_id' => $sectionId,
            'subject_id' => $subjectId,
            'is_class_teacher' => true,
        ])->assertCreated();

        // A student's class is the section they are enrolled in; there is no
        // students.class_name to set.
        $session->postJson('/api/v1/pps/admin/students', [
            'student_code' => 'RADAR-001',
            'name' => 'Rafi Islam',
            'section_id' => $sectionId,
            'roll_number' => 1,
        ])->assertCreated();

        $session->postJson('/api/v1/pps/admin/bulk/students', [
            'rows' => [
                [
                    'student_code' => 'RADAR-002',
                    'name' => 'Nabila Rahman',
                    'section_id' => $sectionId,
                    'roll_number' => 2,
                ],
            ],
        ])->assertCreated()->assertJsonPath('created', 1);

        $session->getJson('/api/v1/pps/admin/overview')
            ->assertOk()
            // "departments" is now the count of distinct class groups.
            ->assertJsonPath('summary.departments', 1)
            ->assertJsonPath('summary.classes', 1)
            ->assertJsonPath('summary.class_sections', 1)
            ->assertJsonPath('summary.sections', 1)
            ->assertJsonPath('summary.subjects', 1)
            ->assertJsonPath('summary.exams', 1)
            ->assertJsonPath('summary.students', 2)
            ->assertJsonPath('summary.teachers', 1)
            ->assertJsonPath('summary.teacher_assignments', 1)
            // The taxonomy reaches the payload, not just the counts.
            ->assertJsonPath('teachers.0.full_name', 'Mariam Rahman')
            ->assertJsonPath('class_sections.0.class_level.name', '10')
            ->assertJsonPath('class_sections.0.section_name.name', 'A');
    }

    /**
     * A teacher may bulk-mark attendance only for students in a class they are
     * assigned to.
     *
     * This used to assert the same rule twice, once over
     * `POST /assessments/bulk` and once over `POST /attendance/bulk`. The
     * former was deleted with the rest of the Assessment surface in the
     * Assessment -> Marks refactor and has no successor a teacher can call:
     * `POST /admin/bulk/marks` is gated on bulk_import.manage (admin and
     * superadmin only) and is a 501 stub. Both facts are pinned below rather
     * than dropped silently, so the day either changes this test notices.
     */
    public function test_teacher_bulk_import_accepts_student_codes_only_for_assigned_scope(): void
    {
        $teacher = $this->createUser([
            'name' => 'Teacher',
            'email' => 'teacher@example.test',
            'role' => 'teacher',
            'password' => Hash::make('password'),
        ]);

        $allowedStudent = $this->makeStudent([
            'student_code' => 'RADAR-101',
            'name' => 'Allowed Student',
            'class_name' => '8',
            'section' => 'A',
            'roll_number' => 1,
        ]);

        $blockedStudent = $this->makeStudent([
            'student_code' => 'RADAR-102',
            'name' => 'Blocked Student',
            'class_name' => '9',
            'section' => 'B',
            'roll_number' => 2,
        ]);

        $this->assignTeacher($teacher, '8', 'A', 'Mathematics');

        $session = $this->signInPps($teacher);

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

        $this->assertDatabaseHas('pps_attendance', [
            'student_id' => $allowedStudent->id,
            'date' => '2026-04-12',
            'status' => 'present',
        ]);

        $this->assertDatabaseMissing('pps_attendance', [
            'student_id' => $blockedStudent->id,
        ]);
    }

    /** Bulk marks import is not a route a teacher can reach. */
    public function test_teacher_cannot_bulk_import_marks(): void
    {
        $teacher = $this->createUser([
            'name' => 'Teacher Two',
            'email' => 'teacher2@example.test',
            'role' => 'teacher',
            'password' => Hash::make('password'),
        ]);

        $this->signInPps($teacher)
            ->postJson('/api/v1/pps/admin/bulk/marks', ['rows' => []])
            ->assertForbidden();
    }

    /** …and is still a stub for the roles that can. */
    public function test_bulk_marks_import_is_not_implemented_yet(): void
    {
        $admin = $this->createUser([
            'name' => 'Admin',
            'email' => 'admin@example.test',
            'role' => 'admin',
            'password' => Hash::make('password'),
        ]);

        $this->signInPps($admin)
            ->postJson('/api/v1/pps/admin/bulk/marks', ['rows' => []])
            ->assertStatus(501);
    }
}

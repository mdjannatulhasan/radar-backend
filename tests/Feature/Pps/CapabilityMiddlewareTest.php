<?php

namespace Tests\Feature\Pps;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Verifies that pps.can middleware enforces ModuleCapabilities::MAP correctly.
 *
 * Each dataset row: [role, route, method, expectedStatus]
 * 200/201 = allowed, 403 = denied, 401 = not authenticated.
 */
class CapabilityMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    // ── Role factories ────────────────────────────────────────────────────────

    private function user(string $role): User
    {
        return User::query()->create([
            'name'     => ucfirst($role),
            'email'    => "{$role}@example.test",
            'role'     => $role,
            'password' => Hash::make('password'),
        ]);
    }

    // ── Unauthenticated ───────────────────────────────────────────────────────

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/pps/dashboard/summary')->assertUnauthorized();
        $this->getJson('/api/v1/pps/students')->assertUnauthorized();
        $this->getJson('/api/v1/pps/marks/term')->assertUnauthorized();
    }

    // ── dashboard.view ────────────────────────────────────────────────────────

    public function test_dashboard_view_allowed_for_admin_and_principal(): void
    {
        foreach (['admin', 'principal', 'superadmin'] as $role) {
            $this->signInPps($this->user($role))
                ->getJson('/api/v1/pps/dashboard/summary')
                ->assertStatus(200, "role={$role} should see dashboard");
        }
    }

    public function test_dashboard_view_denied_for_teacher_and_counselor(): void
    {
        foreach (['teacher', 'counselor', 'welfare_officer', 'guardian'] as $role) {
            $this->signInPps($this->user($role))
                ->getJson('/api/v1/pps/dashboard/summary')
                ->assertForbidden("role={$role} must not see dashboard");
        }
    }

    // ── students.view ─────────────────────────────────────────────────────────

    public function test_students_view_allowed_for_expected_roles(): void
    {
        foreach (['admin', 'principal', 'superadmin', 'teacher', 'counselor', 'welfare_officer'] as $role) {
            $this->signInPps($this->user($role))
                ->getJson('/api/v1/pps/students')
                ->assertStatus(200, "role={$role} should view students");
        }
    }

    public function test_students_view_denied_for_guardian(): void
    {
        $this->signInPps($this->user('guardian'))
            ->getJson('/api/v1/pps/students')
            ->assertForbidden();
    }

    // ── students.context_view ─────────────────────────────────────────────────

    public function test_student_context_view_capability_granted_to_guardian(): void
    {
        // The capability middleware allows guardian through (policy does row-level check separately).
        // We verify via ModuleCapabilities directly — the HTTP endpoint also invokes StudentPolicy
        // which requires isGuardianOf(), so we cannot use assertStatus(200) without full fixture setup.
        $this->assertTrue(
            \App\Support\ModuleCapabilities::roleHas('guardian', 'students', 'context_view'),
            'guardian must have students.context_view capability'
        );
        $this->assertFalse(
            \App\Support\ModuleCapabilities::roleHas('welfare_officer', 'students', 'context_view'),
            'welfare_officer must not have students.context_view capability'
        );
    }

    public function test_student_context_view_denied_for_welfare_officer(): void
    {
        $student = \App\Models\Student::query()->create([
            'student_code' => 'S002', 'name' => 'Test2', 'class_name' => '10', 'section' => 'A', 'roll_number' => 2,
        ]);

        $this->signInPps($this->user('welfare_officer'))
            ->getJson("/api/v1/pps/students/{$student->id}/context")
            ->assertForbidden();
    }

    // ── marks.read / marks.write ──────────────────────────────────────────────

    public function test_marks_read_allowed_for_teacher_and_principal(): void
    {
        // 422 = validation failed (missing exam_id/subject_id) — proves request passed auth middleware.
        // We assert "not 403" to confirm capability check passed.
        foreach (['teacher', 'admin', 'principal', 'superadmin'] as $role) {
            $this->signInPps($this->user($role))
                ->getJson('/api/v1/pps/marks/term')
                ->assertStatus(422, "role={$role} should pass marks.read capability (422 = reached controller)");
        }
    }

    public function test_marks_read_denied_for_guardian_and_counselor(): void
    {
        foreach (['guardian', 'counselor', 'welfare_officer'] as $role) {
            $this->signInPps($this->user($role))
                ->getJson('/api/v1/pps/marks/term')
                ->assertForbidden("role={$role} must not read marks");
        }
    }

    public function test_principal_cannot_write_marks(): void
    {
        // Principal has marks.read but NOT marks.write
        $this->signInPps($this->user('principal'))
            ->postJson('/api/v1/pps/marks/term', [])
            ->assertForbidden();
    }

    public function test_teacher_can_write_marks(): void
    {
        $this->signInPps($this->user('teacher'))
            ->postJson('/api/v1/pps/marks/term', [])
            ->assertStatus(422); // reaches controller, fails validation not auth
    }

    // ── results ───────────────────────────────────────────────────────────────

    public function test_principal_cannot_compute_results(): void
    {
        $this->signInPps($this->user('principal'))
            ->postJson('/api/v1/pps/results/compute', [])
            ->assertForbidden();
    }

    public function test_teacher_can_compute_results(): void
    {
        $this->signInPps($this->user('teacher'))
            ->postJson('/api/v1/pps/results/compute', [])
            ->assertStatus(422);
    }

    // ── admin_panel.view / manage ─────────────────────────────────────────────

    public function test_admin_panel_view_denied_for_non_admin(): void
    {
        foreach (['principal', 'teacher', 'counselor', 'welfare_officer', 'guardian'] as $role) {
            $this->signInPps($this->user($role))
                ->getJson('/api/v1/pps/admin/overview')
                ->assertForbidden("role={$role} must not see admin panel");
        }
    }

    public function test_admin_panel_manage_denied_for_principal(): void
    {
        $this->signInPps($this->user('principal'))
            ->postJson('/api/v1/pps/admin/departments', ['name' => 'X', 'code' => 'X'])
            ->assertForbidden();
    }

    // ── teacher_assignments.manage ────────────────────────────────────────────

    public function test_teacher_cannot_manage_teacher_assignments(): void
    {
        $this->signInPps($this->user('teacher'))
            ->postJson('/api/v1/pps/admin/teacher-assignments', [])
            ->assertForbidden();
    }

    // ── bulk_import.manage ────────────────────────────────────────────────────

    public function test_teacher_cannot_bulk_import(): void
    {
        $this->signInPps($this->user('teacher'))
            ->postJson('/api/v1/pps/admin/bulk/students', [])
            ->assertForbidden();
    }

    // ── assessments.manage ────────────────────────────────────────────────────

    public function test_teacher_can_access_assessments(): void
    {
        $this->signInPps($this->user('teacher'))
            ->getJson('/api/v1/pps/assessments')
            ->assertStatus(200);
    }

    public function test_principal_cannot_manage_assessments(): void
    {
        $this->signInPps($this->user('principal'))
            ->getJson('/api/v1/pps/assessments')
            ->assertForbidden();
    }

    // ── attendance.manage ─────────────────────────────────────────────────────

    public function test_teacher_can_access_attendance(): void
    {
        $this->signInPps($this->user('teacher'))
            ->getJson('/api/v1/pps/attendance')
            ->assertStatus(200);
    }

    public function test_counselor_cannot_access_attendance(): void
    {
        $this->signInPps($this->user('counselor'))
            ->getJson('/api/v1/pps/attendance')
            ->assertForbidden();
    }

    // ── counseling.manage / psychometric ─────────────────────────────────────

    public function test_counselor_can_store_counseling_session(): void
    {
        $this->signInPps($this->user('counselor'))
            ->postJson('/api/v1/pps/counseling-sessions', [])
            ->assertStatus(422);
    }

    public function test_teacher_cannot_store_counseling_session(): void
    {
        $this->signInPps($this->user('teacher'))
            ->postJson('/api/v1/pps/counseling-sessions', [])
            ->assertForbidden();
    }

    public function test_counselor_can_store_psychometric(): void
    {
        $this->signInPps($this->user('counselor'))
            ->postJson('/api/v1/pps/psychometric', [])
            ->assertStatus(422);
    }

    public function test_welfare_officer_cannot_store_psychometric(): void
    {
        $this->signInPps($this->user('welfare_officer'))
            ->postJson('/api/v1/pps/psychometric', [])
            ->assertForbidden();
    }

    // ── welfare ───────────────────────────────────────────────────────────────

    public function test_welfare_officer_can_view_welfare(): void
    {
        $this->signInPps($this->user('welfare_officer'))
            ->getJson('/api/v1/pps/welfare/students')
            ->assertStatus(200);
    }

    public function test_teacher_cannot_view_welfare(): void
    {
        $this->signInPps($this->user('teacher'))
            ->getJson('/api/v1/pps/welfare/students')
            ->assertForbidden();
    }

    public function test_principal_cannot_manage_welfare(): void
    {
        $student = \App\Models\Student::query()->create([
            'student_code' => 'S003', 'name' => 'Test3', 'class_name' => '10', 'section' => 'A', 'roll_number' => 3,
        ]);

        $this->signInPps($this->user('principal'))
            ->postJson("/api/v1/pps/welfare/students/{$student->id}/interventions", [])
            ->assertForbidden();
    }

    // ── notices.view (all roles) ──────────────────────────────────────────────

    public function test_all_roles_can_view_notices(): void
    {
        foreach (['superadmin', 'admin', 'principal', 'teacher', 'counselor', 'welfare_officer', 'guardian'] as $role) {
            $this->signInPps($this->user($role))
                ->getJson('/api/v1/pps/notices')
                ->assertStatus(200, "role={$role} should view notices");
        }
    }

    public function test_teacher_cannot_manage_notices(): void
    {
        $this->signInPps($this->user('teacher'))
            ->postJson('/api/v1/pps/notices', [])
            ->assertForbidden();
    }

    // ── users.manage (superadmin only) ────────────────────────────────────────

    public function test_admin_cannot_manage_users(): void
    {
        $this->signInPps($this->user('admin'))
            ->getJson('/api/v1/pps/admin/users')
            ->assertForbidden();
    }

    public function test_superadmin_can_list_users(): void
    {
        $this->signInPps($this->user('superadmin'))
            ->getJson('/api/v1/pps/admin/users')
            ->assertStatus(200);
    }

    // ── parents ───────────────────────────────────────────────────────────────

    public function test_guardian_can_access_parent_portal(): void
    {
        $this->signInPps($this->user('guardian'))
            ->getJson('/api/v1/pps/parents/my-children')
            ->assertStatus(200);
    }

    public function test_teacher_cannot_access_parent_portal(): void
    {
        $this->signInPps($this->user('teacher'))
            ->getJson('/api/v1/pps/parents/my-children')
            ->assertForbidden();
    }

    // ── settings ─────────────────────────────────────────────────────────────

    public function test_principal_can_view_settings(): void
    {
        $this->signInPps($this->user('principal'))
            ->getJson('/api/v1/pps/settings')
            ->assertStatus(200);
    }

    public function test_teacher_cannot_view_settings(): void
    {
        $this->signInPps($this->user('teacher'))
            ->getJson('/api/v1/pps/settings')
            ->assertForbidden();
    }

    // ── reports ───────────────────────────────────────────────────────────────

    public function test_welfare_officer_can_view_reports(): void
    {
        // 422 = missing required 'period' query param — proves request passed auth middleware.
        $this->signInPps($this->user('welfare_officer'))
            ->getJson('/api/v1/pps/reports/custom')
            ->assertStatus(422);
    }

    public function test_teacher_cannot_view_reports(): void
    {
        $this->signInPps($this->user('teacher'))
            ->getJson('/api/v1/pps/reports/custom')
            ->assertForbidden();
    }

    // ── notifications.run ─────────────────────────────────────────────────────

    public function test_teacher_cannot_run_notifications(): void
    {
        $this->signInPps($this->user('teacher'))
            ->postJson('/api/v1/pps/notifications/run/sms')
            ->assertForbidden();
    }

    public function test_principal_can_run_notifications(): void
    {
        $this->signInPps($this->user('principal'))
            ->postJson('/api/v1/pps/notifications/run/sms')
            ->assertStatus(422);
    }

    // ── teacher_effectiveness.view ────────────────────────────────────────────

    public function test_teacher_cannot_view_teacher_effectiveness(): void
    {
        $this->signInPps($this->user('teacher'))
            ->getJson('/api/v1/pps/teachers/effectiveness')
            ->assertForbidden();
    }

    public function test_principal_can_view_teacher_effectiveness(): void
    {
        $this->signInPps($this->user('principal'))
            ->getJson('/api/v1/pps/teachers/effectiveness')
            ->assertStatus(200);
    }
}

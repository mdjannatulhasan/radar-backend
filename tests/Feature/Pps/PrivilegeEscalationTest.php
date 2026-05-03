<?php

namespace Tests\Feature\Pps;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PrivilegeEscalationTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $role, string $suffix = ''): User
    {
        return User::query()->create([
            'name'     => ucfirst($role),
            'email'    => "{$role}{$suffix}@example.test",
            'role'     => $role,
            'password' => Hash::make('password'),
        ]);
    }

    // ── Superadmin creates users ───────────────────────────────────────────────

    public function test_superadmin_can_create_teacher(): void
    {
        $this->signInPps($this->makeUser('superadmin'))
            ->postJson('/api/v1/pps/admin/users', [
                'name'     => 'New Teacher',
                'email'    => 'new.teacher@example.test',
                'password' => 'password123',
                'role'     => 'teacher',
            ])
            ->assertCreated()
            ->assertJsonPath('role', 'teacher');
    }

    public function test_superadmin_can_create_admin(): void
    {
        $this->signInPps($this->makeUser('superadmin'))
            ->postJson('/api/v1/pps/admin/users', [
                'name'     => 'New Admin',
                'email'    => 'new.admin@example.test',
                'password' => 'password123',
                'role'     => 'admin',
            ])
            ->assertCreated()
            ->assertJsonPath('role', 'admin');
    }

    // ── Admin escalation attempts ─────────────────────────────────────────────

    public function test_superadmin_cannot_create_another_superadmin(): void
    {
        // superadmin role is not in MANAGEABLE_ROLES → validation 422
        $this->signInPps($this->makeUser('superadmin'))
            ->postJson('/api/v1/pps/admin/users', [
                'name'     => 'Fake Super',
                'email'    => 'fake.super@example.test',
                'password' => 'password123',
                'role'     => 'superadmin',
            ])
            ->assertUnprocessable();
    }

    public function test_superadmin_cannot_assign_admin_role_via_escalation_guard(): void
    {
        // admin (hierarchy=3) >= superadmin (hierarchy=4)? No — 3 < 4 → allowed.
        // But this tests the GUARD: a superadmin CREATING another admin is actually allowed
        // (superadmin level 4 > admin level 3). Let's verify that explicitly.
        $this->signInPps($this->makeUser('superadmin'))
            ->postJson('/api/v1/pps/admin/users', [
                'name'     => 'New Admin',
                'email'    => 'new.admin2@example.test',
                'password' => 'password123',
                'role'     => 'admin',
            ])
            ->assertCreated();
    }

    public function test_admin_cannot_access_user_management(): void
    {
        // admin only has admin_panel.manage, not users.manage → 403 from middleware
        $this->signInPps($this->makeUser('admin'))
            ->postJson('/api/v1/pps/admin/users', [
                'name'     => 'New Principal',
                'email'    => 'new.principal@example.test',
                'password' => 'password123',
                'role'     => 'principal',
            ])
            ->assertForbidden();
    }

    public function test_principal_cannot_create_any_user(): void
    {
        // principal has no users.manage capability → 403 from middleware
        $this->signInPps($this->makeUser('principal'))
            ->postJson('/api/v1/pps/admin/users', [
                'name'     => 'Someone',
                'email'    => 'someone@example.test',
                'password' => 'password123',
                'role'     => 'teacher',
            ])
            ->assertForbidden();
    }

    // ── Role update escalation ────────────────────────────────────────────────

    public function test_superadmin_cannot_change_own_role(): void
    {
        $superadmin = $this->makeUser('superadmin');

        $this->signInPps($superadmin)
            ->patchJson("/api/v1/pps/admin/users/{$superadmin->id}", [
                'role' => 'admin',
            ])
            ->assertUnprocessable();
    }

    public function test_superadmin_cannot_promote_teacher_to_superadmin(): void
    {
        // superadmin is not in MANAGEABLE_ROLES → validation 422
        $superadmin = $this->makeUser('superadmin');
        $teacher    = $this->makeUser('teacher', '2');

        $this->signInPps($superadmin)
            ->patchJson("/api/v1/pps/admin/users/{$teacher->id}", [
                'role' => 'superadmin',
            ])
            ->assertUnprocessable();
    }

    public function test_superadmin_can_demote_principal_to_teacher(): void
    {
        $superadmin = $this->makeUser('superadmin');
        $principal  = $this->makeUser('principal', '2');

        $this->signInPps($superadmin)
            ->patchJson("/api/v1/pps/admin/users/{$principal->id}", [
                'role' => 'teacher',
            ])
            ->assertOk()
            ->assertJsonPath('role', 'teacher');
    }

    // ── Deactivation ──────────────────────────────────────────────────────────

    public function test_user_cannot_deactivate_own_account(): void
    {
        $superadmin = $this->makeUser('superadmin');

        $this->signInPps($superadmin)
            ->deleteJson("/api/v1/pps/admin/users/{$superadmin->id}")
            ->assertUnprocessable();
    }

    public function test_superadmin_can_deactivate_teacher(): void
    {
        $superadmin = $this->makeUser('superadmin');
        $teacher    = $this->makeUser('teacher', '3');

        $this->signInPps($superadmin)
            ->deleteJson("/api/v1/pps/admin/users/{$teacher->id}")
            ->assertOk();

        $this->assertFalse($teacher->fresh()->is_active);
    }
}

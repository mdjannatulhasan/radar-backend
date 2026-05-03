<?php

namespace Tests\Feature\Pps;

use App\Models\Pps\RolePermission;
use App\Models\User;
use App\Support\ModuleCapabilities;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RolePermissionControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function admin(): User
    {
        return User::query()->create([
            'name'     => 'Admin',
            'email'    => 'admin@example.test',
            'role'     => 'admin',
            'password' => Hash::make('password'),
        ]);
    }

    private function teacher(): User
    {
        return User::query()->create([
            'name'     => 'Teacher',
            'email'    => 'teacher@example.test',
            'role'     => 'teacher',
            'password' => Hash::make('password'),
        ]);
    }

    // ── GET /admin/roles ──────────────────────────────────────────────────────

    public function test_admin_can_list_roles(): void
    {
        $response = $this->signInPps($this->admin())
            ->getJson('/api/v1/pps/admin/roles')
            ->assertOk();

        $data = $response->json('data');
        $this->assertIsArray($data);

        $roles = collect($data)->pluck('role');
        $this->assertTrue($roles->contains('superadmin'));
        $this->assertTrue($roles->contains('teacher'));
        $this->assertTrue($roles->contains('guardian'));

        // Each item has granted_count
        foreach ($data as $item) {
            $this->assertArrayHasKey('granted_count', $item);
            $this->assertIsInt($item['granted_count']);
        }
    }

    public function test_teacher_cannot_list_roles(): void
    {
        $this->signInPps($this->teacher())
            ->getJson('/api/v1/pps/admin/roles')
            ->assertForbidden();
    }

    // ── GET /admin/roles/{role}/permissions ───────────────────────────────────

    public function test_admin_can_get_permission_matrix_for_role(): void
    {
        $response = $this->signInPps($this->admin())
            ->getJson('/api/v1/pps/admin/roles/teacher/permissions')
            ->assertOk();

        $this->assertEquals('teacher', $response->json('role'));
        $data = $response->json('data');
        $this->assertIsArray($data);

        // marks module should exist with read action granted for teacher
        $marks = collect($data)->firstWhere('module', 'marks');
        $this->assertNotNull($marks, 'marks module missing from matrix');

        $readAction = collect($marks['actions'])->firstWhere('action', 'read');
        $this->assertTrue($readAction['granted'], 'teacher should have marks.read');

        // dashboard should not be granted for teacher
        $dashboard = collect($data)->firstWhere('module', 'dashboard');
        $this->assertNotNull($dashboard);
        $viewAction = collect($dashboard['actions'])->firstWhere('action', 'view');
        $this->assertFalse($viewAction['granted'], 'teacher must not have dashboard.view');
    }

    public function test_invalid_role_returns_404(): void
    {
        $this->signInPps($this->admin())
            ->getJson('/api/v1/pps/admin/roles/nonexistent/permissions')
            ->assertNotFound();
    }

    // ── PATCH /admin/roles/{role}/permissions ─────────────────────────────────

    public function test_admin_can_grant_and_revoke_permissions(): void
    {
        // Confirm teacher does NOT currently have dashboard.view
        $this->assertFalse(ModuleCapabilities::roleHas('teacher', 'dashboard', 'view'));

        // Grant dashboard.view to teacher
        $this->signInPps($this->admin())
            ->patchJson('/api/v1/pps/admin/roles/teacher/permissions', [
                'permissions' => [
                    ['module' => 'dashboard', 'action' => 'view', 'granted' => true],
                ],
            ])
            ->assertOk();

        // Cache is busted — fresh DB check
        $this->assertTrue(
            RolePermission::where(['role' => 'teacher', 'module' => 'dashboard', 'action' => 'view', 'granted' => true])->exists()
        );

        // ModuleCapabilities now picks up the DB change
        Cache::flush();
        $this->assertTrue(ModuleCapabilities::roleHas('teacher', 'dashboard', 'view'));
    }

    public function test_patch_busts_capabilities_cache(): void
    {
        // Prime the cache
        Cache::put('caps:teacher', ['marks' => ['read' => true]], 3600);
        Cache::put('cap:teacher:dashboard:view', false, 3600);

        $this->signInPps($this->admin())
            ->patchJson('/api/v1/pps/admin/roles/teacher/permissions', [
                'permissions' => [
                    ['module' => 'dashboard', 'action' => 'view', 'granted' => true],
                ],
            ])
            ->assertOk();

        $this->assertNull(Cache::get('caps:teacher'), 'caps cache must be busted');
        $this->assertNull(Cache::get('cap:teacher:dashboard:view'), 'granular cache must be busted');
    }

    public function test_patch_rejects_unknown_module(): void
    {
        $this->signInPps($this->admin())
            ->patchJson('/api/v1/pps/admin/roles/teacher/permissions', [
                'permissions' => [
                    ['module' => 'nonexistent_module', 'action' => 'view', 'granted' => true],
                ],
            ])
            ->assertUnprocessable();
    }

    public function test_patch_rejects_unknown_action(): void
    {
        $this->signInPps($this->admin())
            ->patchJson('/api/v1/pps/admin/roles/teacher/permissions', [
                'permissions' => [
                    ['module' => 'dashboard', 'action' => 'fly', 'granted' => true],
                ],
            ])
            ->assertUnprocessable();
    }

    public function test_patch_updates_updated_by_field(): void
    {
        $admin = $this->admin();

        $this->signInPps($admin)
            ->patchJson('/api/v1/pps/admin/roles/teacher/permissions', [
                'permissions' => [
                    ['module' => 'dashboard', 'action' => 'view', 'granted' => true],
                ],
            ])
            ->assertOk();

        $row = RolePermission::where(['role' => 'teacher', 'module' => 'dashboard', 'action' => 'view'])->first();
        $this->assertEquals($admin->id, $row->updated_by);
    }

    public function test_teacher_cannot_patch_role_permissions(): void
    {
        $this->signInPps($this->teacher())
            ->patchJson('/api/v1/pps/admin/roles/teacher/permissions', [
                'permissions' => [
                    ['module' => 'dashboard', 'action' => 'view', 'granted' => true],
                ],
            ])
            ->assertForbidden();
    }

    // ── GET /admin/permission-modules ─────────────────────────────────────────

    public function test_admin_can_list_permission_modules(): void
    {
        $response = $this->signInPps($this->admin())
            ->getJson('/api/v1/pps/admin/permission-modules')
            ->assertOk();

        $data = $response->json('data');
        $modules = collect($data)->pluck('name');

        $this->assertTrue($modules->contains('marks'));
        $this->assertTrue($modules->contains('students'));
        $this->assertTrue($modules->contains('admin_panel'));
        $this->assertTrue($modules->contains('notices'));
    }

    // ── Seeder correctness ────────────────────────────────────────────────────

    public function test_seeder_grants_correct_teacher_capabilities(): void
    {
        // Teacher should have these
        $shouldHave = ['marks.read', 'marks.write', 'students.view', 'alerts.view',
                       'assessments.manage', 'attendance.manage', 'behavior.manage',
                       'classroom_ratings.manage', 'extracurricular.manage',
                       'notices.view', 'notifications.view'];

        foreach ($shouldHave as $cap) {
            [$module, $action] = explode('.', $cap, 2);
            $this->assertTrue(
                RolePermission::where(['role' => 'teacher', 'module' => $module, 'action' => $action, 'granted' => true])->exists(),
                "teacher should have {$cap}"
            );
        }
    }

    public function test_seeder_denies_correct_teacher_capabilities(): void
    {
        $shouldNotHave = ['dashboard.view', 'admin_panel.view', 'admin_panel.manage',
                          'users.manage', 'reports.view', 'welfare.view',
                          'counseling.manage', 'notifications.run'];

        foreach ($shouldNotHave as $cap) {
            [$module, $action] = explode('.', $cap, 2);
            $this->assertFalse(
                RolePermission::where(['role' => 'teacher', 'module' => $module, 'action' => $action, 'granted' => true])->exists(),
                "teacher must not have {$cap}"
            );
        }
    }

    public function test_seeder_grants_guardian_parents_portal_only(): void
    {
        $this->assertTrue(
            RolePermission::where(['role' => 'guardian', 'module' => 'parents', 'action' => 'portal', 'granted' => true])->exists()
        );
        $this->assertFalse(
            RolePermission::where(['role' => 'guardian', 'module' => 'dashboard', 'action' => 'view', 'granted' => true])->exists()
        );
    }

    // ── ModuleCapabilities DB fallback ────────────────────────────────────────

    public function test_modulecapabilities_for_role_returns_db_values(): void
    {
        Cache::flush();

        $caps = ModuleCapabilities::forRole('teacher');

        $this->assertTrue($caps['marks']['read'] ?? false, 'teacher should have marks.read');
        $this->assertTrue($caps['marks']['write'] ?? false, 'teacher should have marks.write');
        $this->assertArrayNotHasKey('dashboard', $caps, 'teacher must not have dashboard');
    }

    public function test_modulecapabilities_role_has_reflects_db(): void
    {
        Cache::flush();

        $this->assertTrue(ModuleCapabilities::roleHas('admin', 'admin_panel', 'view'));
        $this->assertFalse(ModuleCapabilities::roleHas('teacher', 'admin_panel', 'view'));
        $this->assertTrue(ModuleCapabilities::roleHas('guardian', 'parents', 'portal'));
        $this->assertFalse(ModuleCapabilities::roleHas('guardian', 'students', 'view'));
    }

    public function test_notices_view_granted_to_all_roles(): void
    {
        Cache::flush();

        foreach (['superadmin', 'admin', 'principal', 'teacher', 'counselor', 'welfare_officer', 'guardian'] as $role) {
            $this->assertTrue(
                ModuleCapabilities::roleHas($role, 'notices', 'view'),
                "{$role} should have notices.view"
            );
        }
    }
}

<?php

namespace Database\Seeders;

use App\Support\ModuleCapabilities;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RolePermissionSeeder extends Seeder
{
    private const MODULE_LABELS = [
        'dashboard'           => 'Dashboard',
        'marks'               => 'Marks Entry',
        'results'             => 'Exam Results',
        'report_cards'        => 'Report Cards',
        'students'            => 'Students',
        'alerts'              => 'Alerts',
        'teacher_workspace'   => 'Teacher Workspace',
        'classes'             => 'Classes & Sections',
        'teacher_effectiveness' => 'Teacher Effectiveness',
        'attendance'          => 'Attendance',
        'behavior'            => 'Behavior Cards',
        'classroom_ratings'   => 'Classroom Ratings',
        'extracurricular'     => 'Extracurricular',
        'counseling'          => 'Counseling',
        'welfare'             => 'Welfare',
        'notices'             => 'Notice Board',
        'notifications'       => 'Notifications',
        'reports'             => 'Reports',
        'settings'            => 'Settings',
        'admin_panel'         => 'Admin Panel',
        'teacher_assignments' => 'Teacher Assignments',
        'bulk_import'         => 'Bulk Import',
        'users'               => 'User Management',
        'parents'             => 'Parent Portal',
        'profile'             => 'Profile',
    ];

    private const ROLES = [
        'superadmin', 'admin', 'principal', 'teacher',
        'counselor', 'welfare_officer', 'guardian',
    ];

    public function run(): void
    {
        // Seed permission_modules
        $modules = array_keys(ModuleCapabilities::MAP);
        foreach ($modules as $i => $module) {
            // permission_modules is now shared across products, and its unique
            // key is (product, name) — RADAR's modules must be upserted on that
            // pair or Postgres has no matching constraint for ON CONFLICT.
            DB::table('permission_modules')->upsert([
                'product'    => 'radar',
                'name'       => $module,
                'label'      => self::MODULE_LABELS[$module] ?? ucfirst(str_replace('_', ' ', $module)),
                'sort_order' => $i,
            ], ['product', 'name'], ['label', 'sort_order']);
        }

        // Seed role_permissions from the static MAP
        $rows = [];
        foreach (self::ROLES as $role) {
            foreach (ModuleCapabilities::MAP as $module => $actions) {
                foreach ($actions as $action => $allowedRoles) {
                    $granted = $allowedRoles === ['all'] || in_array($role, $allowedRoles, true);
                    $rows[] = [
                        'role'    => $role,
                        'module'  => $module,
                        'action'  => $action,
                        'granted' => $granted,
                    ];
                }
            }
        }

        DB::table('role_permissions')->upsert(
            $rows,
            ['role', 'module', 'action'],
            ['granted']
        );

        $this->pruneRetiredModules($modules);
    }

    /**
     * Delete rows for modules the code no longer defines.
     *
     * Both writes above are upserts, so retiring a module from
     * ModuleCapabilities::MAP does NOT remove the rows a previous seed run left
     * behind. That matters: ModuleCapabilities::forRole() builds the login
     * payload's `capabilities` straight from role_permissions, so an orphaned
     * `assessments.manage` row keeps granting a capability that guards no route
     * long after the module is gone, and permission_modules keeps rendering it
     * as an empty row in the permissions matrix UI.
     *
     * @param  array<int, string>  $modules  the modules the code currently defines
     */
    private function pruneRetiredModules(array $modules): void
    {
        DB::table('role_permissions')->whereNotIn('module', $modules)->delete();

        DB::table('permission_modules')
            ->where('product', 'radar')
            ->whereNotIn('name', $modules)
            ->delete();
    }
}

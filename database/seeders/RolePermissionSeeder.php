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
        'assessments'         => 'Assessments',
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
            DB::table('permission_modules')->upsert([
                'name'       => $module,
                'label'      => self::MODULE_LABELS[$module] ?? ucfirst(str_replace('_', ' ', $module)),
                'sort_order' => $i,
            ], ['name'], ['label', 'sort_order']);
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
    }
}

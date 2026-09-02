<?php

namespace App\Support;

use App\Models\Pps\RolePermission;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

final class ModuleCapabilities
{
    // Valid modules and actions are defined in code. Only role→module→action mapping lives in DB.
    // UI can toggle existing capabilities — it cannot invent new module/action names.
    const MAP = [
        'dashboard'           => ['view'           => ['admin', 'principal', 'superadmin']],
        'marks'               => ['read'            => ['admin', 'principal', 'superadmin', 'teacher'],
                                   'write'          => ['admin', 'superadmin', 'teacher']],
        'results'             => ['read'            => ['admin', 'principal', 'superadmin', 'teacher'],
                                   'compute'        => ['admin', 'principal', 'superadmin', 'teacher']],
        'report_cards'        => ['read'            => ['admin', 'principal', 'superadmin', 'teacher']],
        'students'            => ['view'            => ['admin', 'principal', 'superadmin', 'teacher', 'counselor', 'welfare_officer'],
                                   'manage'         => ['admin', 'superadmin'],
                                   'context_view'   => ['admin', 'principal', 'superadmin', 'teacher', 'counselor', 'guardian'],
                                   'context_update' => ['admin', 'superadmin', 'counselor', 'guardian'],
                                   'what_if'        => ['admin', 'principal', 'superadmin', 'teacher'],
                                   'counseling'     => ['admin', 'principal', 'superadmin', 'counselor']],
        'alerts'              => ['view'            => ['admin', 'principal', 'superadmin', 'teacher'],
                                   'resolve'        => ['admin', 'principal', 'superadmin', 'teacher']],
        'teacher_workspace'   => ['view'            => ['teacher']],
        'classes'             => ['view'            => ['admin', 'principal', 'superadmin', 'teacher']],
        'teacher_effectiveness' => ['view'          => ['admin', 'principal', 'superadmin']],
        'attendance'          => ['manage'          => ['admin', 'superadmin', 'teacher']],
        'behavior'            => ['manage'          => ['admin', 'superadmin', 'teacher']],
        'classroom_ratings'   => ['manage'          => ['admin', 'superadmin', 'teacher']],
        'extracurricular'     => ['manage'          => ['admin', 'superadmin', 'teacher']],
        'counseling'          => ['view'            => ['admin', 'principal', 'superadmin', 'counselor'],
                                   'manage'         => ['counselor', 'principal', 'admin', 'superadmin'],
                                   'psychometric'   => ['admin', 'superadmin', 'principal', 'counselor']],
        'welfare'             => ['view'            => ['admin', 'principal', 'superadmin', 'welfare_officer'],
                                   'manage'         => ['welfare_officer']],
        'notices'             => ['view'            => ['all'],
                                   'manage'         => ['admin', 'principal', 'superadmin']],
        'notifications'       => ['view'            => ['admin', 'principal', 'superadmin', 'teacher'],
                                   'run'            => ['admin', 'principal', 'superadmin']],
        'reports'             => ['view'            => ['admin', 'principal', 'superadmin', 'welfare_officer']],
        'settings'            => ['view'            => ['admin', 'principal', 'superadmin'],
                                   'update'         => ['admin', 'principal', 'superadmin']],
        'admin_panel'         => ['view'            => ['admin', 'principal', 'superadmin'],
                                   'manage'         => ['admin', 'superadmin']],
        'teacher_assignments' => ['manage'          => ['admin', 'principal', 'superadmin']],
        'bulk_import'         => ['manage'          => ['admin', 'superadmin']],
        'users'               => ['manage'          => ['superadmin']],
        'parents'             => ['portal'          => ['guardian'],
                                   'report'         => ['guardian', 'admin', 'principal', 'superadmin'],
                                   'print'          => ['guardian'],
                                   'view_any'       => ['admin', 'principal', 'superadmin', 'teacher']],
        'profile'             => ['view'            => ['all']],
    ];

    public static function forRole(string $role): array
    {
        // Try DB first; fall back to static MAP if table doesn't exist yet (e.g. pre-migration).
        try {
            return Cache::remember("caps:{$role}", 3600, function () use ($role): array {
                $rows = RolePermission::where('role', $role)
                    ->where('granted', true)
                    ->get(['module', 'action']);

                if ($rows->isEmpty()) {
                    return self::fromStaticMap($role);
                }

                $caps = [];
                foreach ($rows as $row) {
                    $caps[$row->module][$row->action] = true;
                }
                return $caps;
            });
        } catch (\Throwable) {
            return self::fromStaticMap($role);
        }
    }

    public static function roleHas(string $role, string $module, string $action): bool
    {
        try {
            return (bool) Cache::remember("cap:{$role}:{$module}:{$action}", 3600, function () use ($role, $module, $action): bool {
                $exists = RolePermission::where([
                    'role'    => $role,
                    'module'  => $module,
                    'action'  => $action,
                    'granted' => true,
                ])->exists();

                if (!$exists) {
                    // Fall back to static MAP for any role not yet in DB
                    return self::staticRoleHas($role, $module, $action);
                }
                return true;
            });
        } catch (\Throwable) {
            return self::staticRoleHas($role, $module, $action);
        }
    }

    public static function bustCache(string $role): void
    {
        // Never let a cache problem fail the write that already succeeded.
        // Under tenancy every Cache call is routed through ->tags() by
        // stancl's CacheManager, so a store that cannot tag (database, file)
        // throws BadMethodCallException here — after the permission rows were
        // committed. That surfaced as "saved, then 500", and left the UI
        // reporting failure on a successful save.
        //
        // roleHas() already swallows the same exception on the read side, so
        // a non-taggable store degrades to the static MAP rather than erroring.
        try {
            Cache::forget("caps:{$role}");
            foreach (self::MAP as $module => $actions) {
                foreach (array_keys($actions) as $action) {
                    Cache::forget("cap:{$role}:{$module}:{$action}");
                }
            }
        } catch (\Throwable $e) {
            Log::warning('capability_cache_bust_failed', [
                'role' => $role,
                'store' => config('cache.default'),
                'message' => $e->getMessage(),
            ]);
        }
    }

    private static function fromStaticMap(string $role): array
    {
        $caps = [];
        foreach (self::MAP as $module => $actions) {
            foreach ($actions as $action => $roles) {
                if ($roles === ['all'] || in_array($role, $roles, true)) {
                    $caps[$module][$action] = true;
                }
            }
        }
        return $caps;
    }

    private static function staticRoleHas(string $role, string $module, string $action): bool
    {
        $roles = self::MAP[$module][$action] ?? [];
        return $roles === ['all'] || in_array($role, $roles, true);
    }
}

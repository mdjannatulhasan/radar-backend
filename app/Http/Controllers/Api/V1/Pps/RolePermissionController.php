<?php

namespace App\Http\Controllers\Api\V1\Pps;

use App\Http\Controllers\Controller;
use App\Models\Pps\RolePermission;
use App\Support\ModuleCapabilities;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class RolePermissionController extends Controller
{
    private const ROLES = [
        'superadmin', 'admin', 'principal', 'teacher',
        'counselor', 'welfare_officer', 'guardian',
    ];

    /**
     * GET /v1/admin/roles
     * List all roles with granted-permission counts.
     */
    public function index(): JsonResponse
    {
        $counts = RolePermission::where('granted', true)
            ->selectRaw('role, count(*) as granted_count')
            ->groupBy('role')
            ->pluck('granted_count', 'role');

        $roles = collect(self::ROLES)->map(fn (string $role) => [
            'role'          => $role,
            'granted_count' => $counts[$role] ?? 0,
        ]);

        return response()->json(['data' => $roles]);
    }

    /**
     * GET /v1/admin/roles/{role}/permissions
     * Full matrix of module × action for a role.
     */
    public function show(string $role): JsonResponse
    {
        abort_unless(in_array($role, self::ROLES, true), 404);

        $granted = RolePermission::where('role', $role)
            ->where('granted', true)
            ->get(['module', 'action'])
            ->groupBy('module')
            ->map(fn ($rows) => $rows->pluck('action')->values());

        $modules = DB::table('permission_modules')->orderBy('sort_order')->get(['name', 'label']);

        $matrix = $modules->map(function ($mod) use ($granted) {
            $actions = array_keys(ModuleCapabilities::MAP[$mod->name] ?? []);
            $grantedActions = $granted[$mod->name] ?? collect();
            return [
                'module'  => $mod->name,
                'label'   => $mod->label,
                'actions' => collect($actions)->map(fn (string $action) => [
                    'action'  => $action,
                    'granted' => $grantedActions->contains($action),
                ])->values(),
            ];
        });

        return response()->json(['role' => $role, 'data' => $matrix]);
    }

    /**
     * PATCH /v1/admin/roles/{role}/permissions
     * Bulk-update permissions for a role, then bust cache.
     */
    public function update(Request $request, string $role): JsonResponse
    {
        abort_unless(in_array($role, self::ROLES, true), 404);

        $validModules  = array_keys(ModuleCapabilities::MAP);
        $validActions  = collect(ModuleCapabilities::MAP)
            ->flatMap(fn ($actions) => array_keys($actions))
            ->unique()
            ->values()
            ->all();

        $data = $request->validate([
            'permissions'                => ['required', 'array'],
            'permissions.*.module'       => ['required', 'string', Rule::in($validModules)],
            'permissions.*.action'       => ['required', 'string', Rule::in($validActions)],
            'permissions.*.granted'      => ['required', 'boolean'],
        ]);

        $callerId = $request->user()->id;
        $now = now();

        $rows = collect($data['permissions'])->map(fn (array $p) => [
            'role'       => $role,
            'module'     => $p['module'],
            'action'     => $p['action'],
            'granted'    => $p['granted'],
            'updated_by' => $callerId,
            'updated_at' => $now,
        ])->all();

        DB::table('role_permissions')->upsert(
            $rows,
            ['role', 'module', 'action'],
            ['granted', 'updated_by', 'updated_at']
        );

        ModuleCapabilities::bustCache($role);

        return response()->json(['message' => "Permissions updated for role: {$role}"]);
    }

    /**
     * GET /v1/admin/permission-modules
     * List valid modules (seeded from code, read-only for UI).
     */
    public function modules(): JsonResponse
    {
        $modules = DB::table('permission_modules')->orderBy('sort_order')->get(['name', 'label']);
        return response()->json(['data' => $modules]);
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Pps;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ModuleCapabilities;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class UserManagementController extends Controller
{
    private const MANAGEABLE_ROLES = [
        'teacher', 'counselor', 'welfare_officer', 'principal', 'admin', 'guardian',
    ];

    private const ROLE_HIERARCHY = [
        'guardian'      => 0,
        'teacher'       => 1,
        'counselor'     => 1,
        'welfare_officer' => 1,
        'principal'     => 2,
        'admin'         => 3,
        'superadmin'    => 4,
    ];

    /**
     * GET /v1/admin/users
     * List all users, optionally filtered by role.
     */
    public function index(Request $request): JsonResponse
    {
        $query = User::query()->select(['id', 'name', 'email', 'role', 'is_active', 'created_at']);

        if ($request->filled('role')) {
            $query->where('role', $request->string('role')->lower());
        }

        if ($request->filled('search')) {
            $term = $request->string('search');
            $query->where(function ($q) use ($term): void {
                $q->where('name', 'like', "%{$term}%")
                  ->orWhere('email', 'like', "%{$term}%");
            });
        }

        return response()->json($query->orderBy('role')->orderBy('name')->paginate(30));
    }

    /**
     * POST /v1/admin/users
     * Create a new user.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:100'],
            'role'     => ['required', 'string', Rule::in(self::MANAGEABLE_ROLES)],
        ]);

        $this->guardPrivilegeEscalation($request->user(), $data['role']);

        $user = User::query()->create([
            'name'      => $data['name'],
            'email'     => $data['email'],
            'password'  => Hash::make($data['password']),
            'role'      => $data['role'],
            'is_active' => true,
        ]);

        return response()->json(
            $user->only(['id', 'name', 'email', 'role', 'is_active', 'created_at']),
            Response::HTTP_CREATED,
        );
    }

    /**
     * PATCH /v1/admin/users/{user}
     * Update name, email, role, or is_active. Password reset optional.
     */
    public function update(Request $request, User $user): JsonResponse
    {
        if ($user->id === $request->user()?->id && $request->has('role')) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Cannot change your own role.');
        }

        $data = $request->validate([
            'name'      => ['sometimes', 'string', 'max:255'],
            'email'     => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'role'      => ['sometimes', 'string', Rule::in(self::MANAGEABLE_ROLES)],
            'is_active' => ['sometimes', 'boolean'],
            'password'  => ['sometimes', 'nullable', 'string', 'min:8', 'max:100'],
        ]);

        if (isset($data['role'])) {
            $this->guardPrivilegeEscalation($request->user(), $data['role']);
            // Bust old role cache so the user's previous role caps aren't stale
            ModuleCapabilities::bustCache($user->role);
        }

        if (isset($data['password']) && $data['password'] !== null) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        $user->update($data);

        return response()->json(
            $user->fresh()?->only(['id', 'name', 'email', 'role', 'is_active', 'created_at']),
        );
    }

    /**
     * DELETE /v1/admin/users/{user}
     * Deactivates rather than hard-deletes to preserve data integrity.
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($user->id === $request->user()?->id) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Cannot delete your own account.');
        }

        $user->update(['is_active' => false]);

        return response()->json(['message' => 'User deactivated.']);
    }

    private function guardPrivilegeEscalation(User $caller, string $newRole): void
    {
        $callerLevel = self::ROLE_HIERARCHY[$caller->role] ?? 0;
        $targetLevel = self::ROLE_HIERARCHY[$newRole] ?? 0;

        if ($targetLevel >= $callerLevel) {
            abort(Response::HTTP_FORBIDDEN, 'Cannot assign a role equal to or above your own.');
        }
    }
}

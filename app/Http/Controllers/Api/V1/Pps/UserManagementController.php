<?php

namespace App\Http\Controllers\Api\V1\Pps;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\PpsPermissions;
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

    /**
     * GET /v1/admin/users
     * List all users, optionally filtered by role.
     */
    public function index(Request $request): JsonResponse
    {
        $this->requireUserManage($request);

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

        $users = $query->orderBy('role')->orderBy('name')->paginate(30);

        return response()->json($users);
    }

    /**
     * POST /v1/admin/users
     * Create a new user. Superadmin only.
     */
    public function store(Request $request): JsonResponse
    {
        $this->requireUserManage($request);

        $data = $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:100'],
            'role'     => ['required', 'string', Rule::in(self::MANAGEABLE_ROLES)],
        ]);

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
        $this->requireUserManage($request);

        // Prevent modifying own superadmin account role/active status accidentally
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
     * Hard-delete only if user has no linked data; otherwise deactivate.
     * Superadmin cannot delete themselves.
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->requireUserManage($request);

        if ($user->id === $request->user()?->id) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Cannot delete your own account.');
        }

        // Soft approach: deactivate rather than hard-delete to preserve data integrity
        $user->update(['is_active' => false]);

        return response()->json(['message' => 'User deactivated.']);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function requireUserManage(Request $request): void
    {
        if (! $request->user()?->hasPermission(PpsPermissions::USER_MANAGE)) {
            abort(Response::HTTP_FORBIDDEN, 'User management requires superadmin role.');
        }
    }
}

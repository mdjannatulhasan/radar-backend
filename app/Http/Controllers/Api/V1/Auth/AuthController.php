<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\Pps\TeacherAssignment;
use App\Models\User;
use App\Support\PpsPermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('email', strtolower($credentials['email']))->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => 'The provided credentials are invalid.',
            ]);
        }

        $abilities = $user->permissions();
        $deviceName = $this->resolveDeviceName($request);
        $expiresAt = now()->addMinutes((int) config('sanctum.expiration', 480));

        $user->tokens()->where('name', $deviceName)->delete();

        $token = $user->createToken($deviceName, $abilities, $expiresAt);

        return response()->json([
            'token' => $token->plainTextToken,
            'expires_at' => $expiresAt->toIso8601String(),
            'user' => $this->userPayload($user),
        ], Response::HTTP_CREATED);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'user' => $this->userPayload($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();
        $bearerToken = $request->bearerToken();

        if ($token) {
            $token->delete();
        }

        if ($bearerToken) {
            PersonalAccessToken::findToken($bearerToken)?->delete();
        }

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }

    private function userPayload(User $user): array
    {
        $teacherScope = null;

        if ($user->hasAnyRole('teacher')) {
            $teacherScope = $user->teacherAssignments()
                ->get(['class_name', 'section', 'subject', 'is_class_teacher'])
                ->groupBy(fn (TeacherAssignment $assignment) => "{$assignment->class_name}-{$assignment->section}")
                ->map(function ($assignments) {
                    /** @var \Illuminate\Support\Collection<int, TeacherAssignment> $assignments */
                    $first = $assignments->first();

                    return [
                        'class_name' => $first?->class_name,
                        'section' => $first?->section,
                        'subjects' => $assignments->pluck('subject')->filter()->unique()->values()->all(),
                        'is_class_teacher' => (bool) $assignments->contains(fn (TeacherAssignment $assignment) => $assignment->is_class_teacher),
                    ];
                })
                ->values()
                ->all();
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'role_label' => PpsPermissions::roleLabel($user->role),
            'permissions' => $user->permissions(),
            'home_path' => PpsPermissions::homePathForRole($user->role),
            'teacher_scope' => $teacherScope,
        ];
    }

    private function resolveDeviceName(Request $request): string
    {
        $userAgent = trim((string) $request->userAgent());
        $fingerprint = substr(hash('sha256', $request->ip().'|'.$userAgent), 0, 12);

        return 'pps-web-'.$fingerprint;
    }
}

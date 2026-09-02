<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use SmsCore\Models\Admin;
use Symfony\Component\HttpFoundation\Response;

/**
 * Login for platform super admins — the people who provision schools and sell
 * them products. Deliberately a separate controller from the school-side
 * AuthController: different table, different guard, different host, and
 * nothing about a platform admin belongs in a school's token payload.
 *
 * Every route here is central-only (see the `central` middleware on the group
 * in routes/api.php), so tenancy is never initialized when these run and the
 * tokens minted below land in public.personal_access_tokens.
 */
class PlatformAuthController extends Controller
{
    /** The single ability a platform token carries. */
    public const ABILITY = 'platform:admin';

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $admin = Admin::query()->where('email', strtolower(trim($credentials['email'])))->first();

        if (! $admin || ! Hash::check($credentials['password'], $admin->password)) {
            // Same message and shape the school login uses, so neither flow
            // reveals whether an address exists on the other side.
            throw ValidationException::withMessages([
                'email' => 'The provided credentials are invalid.',
            ]);
        }

        $deviceName = $this->resolveDeviceName($request);
        $expiresAt = now()->addMinutes((int) config('sanctum.expiration', 480));

        $admin->tokens()->where('name', $deviceName)->delete();

        $token = $admin->createToken($deviceName, [self::ABILITY], $expiresAt);

        return response()->json([
            'token' => $token->plainTextToken,
            'expires_at' => $expiresAt->toIso8601String(),
            'admin' => $this->adminPayload($admin),
        ], Response::HTTP_CREATED);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var Admin $admin */
        $admin = $request->user('admin');

        return response()->json(['admin' => $this->adminPayload($admin)]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user('admin')?->currentAccessToken()?->delete();

        if ($bearer = $request->bearerToken()) {
            PersonalAccessToken::findToken($bearer)?->delete();
        }

        return response()->json(['message' => 'Logged out successfully.']);
    }

    private function adminPayload(Admin $admin): array
    {
        return [
            'id' => $admin->id,
            'name' => $admin->name,
            'email' => $admin->email,
        ];
    }

    private function resolveDeviceName(Request $request): string
    {
        $fingerprint = substr(
            hash('sha256', $request->ip().'|'.trim((string) $request->userAgent())),
            0,
            12
        );

        return 'platform-web-'.$fingerprint;
    }
}

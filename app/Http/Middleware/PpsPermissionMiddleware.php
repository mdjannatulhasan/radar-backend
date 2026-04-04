<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PpsPermissionMiddleware
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();
        $required = collect($permissions)
            ->map(fn (string $permission) => trim($permission))
            ->filter()
            ->values();

        if (! $user) {
            abort(Response::HTTP_UNAUTHORIZED, 'Authentication is required for PPS routes.');
        }

        foreach ($required as $permission) {
            if (! method_exists($user, 'hasPermission') || ! $user->hasPermission($permission)) {
                abort(Response::HTTP_FORBIDDEN, 'You do not have permission to access this PPS resource.');
            }

            $token = method_exists($user, 'currentAccessToken') ? $user->currentAccessToken() : null;

            if ($token !== null && ! $user->tokenCan($permission)) {
                abort(Response::HTTP_FORBIDDEN, 'The supplied token cannot access this PPS resource.');
            }
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PpsRoleMiddleware
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();
        $roles = collect($roles)
            ->map(fn (string $role) => strtolower(trim($role)))
            ->filter()
            ->values()
            ->all();

        if (! $user) {
            abort(Response::HTTP_UNAUTHORIZED, 'Authentication is required for PPS routes.');
        }

        if ($roles !== [] && (! method_exists($user, 'hasAnyRole') || ! $user->hasAnyRole($roles))) {
            abort(Response::HTTP_FORBIDDEN, 'You do not have permission to access this PPS resource.');
        }

        return $next($request);
    }
}

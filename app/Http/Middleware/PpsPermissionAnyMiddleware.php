<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PpsPermissionAnyMiddleware
{
    /**
     * Passes if the user has ANY ONE of the listed permissions (OR logic).
     * Usage: ->middleware('pps.permission_any:perm.a,perm.b')
     */
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(Response::HTTP_UNAUTHORIZED, 'Authentication is required for PPS routes.');
        }

        $hasAny = collect($permissions)
            ->map(fn (string $p) => trim($p))
            ->filter()
            ->some(fn (string $p) => method_exists($user, 'hasPermission') && $user->hasPermission($p));

        if (! $hasAny) {
            abort(Response::HTTP_FORBIDDEN, 'You do not have permission to access this PPS resource.');
        }

        return $next($request);
    }
}

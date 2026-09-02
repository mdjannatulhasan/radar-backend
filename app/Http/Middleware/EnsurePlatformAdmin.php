<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use SmsCore\Models\Admin;
use Symfony\Component\HttpFoundation\Response;

/**
 * Belt and braces on top of `auth:admin`.
 *
 * Sanctum's guard is tokenable-type blind: given a bearer token it finds, it
 * returns whatever model the token hangs off, without checking that the model
 * matches the guard's configured provider. Host separation plus schema-scoped
 * token storage already make a tenant user's token unfindable on a central
 * host (their tokens live in tenant_<slug>.personal_access_tokens; the platform
 * routes only run with search_path=public), but that is an emergent property of
 * two other mechanisms rather than a stated rule.
 *
 * This states the rule: a platform route serves an Admin or nobody.
 */
class EnsurePlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user('admin') instanceof Admin) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return $next($request);
    }
}

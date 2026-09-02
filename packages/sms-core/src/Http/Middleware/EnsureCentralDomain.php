<?php

declare(strict_types=1);

namespace SmsCore\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The mirror image of EnsureProductEnabled's "No tenant resolved." branch.
 *
 * EnsureProductEnabled rejects a tenant route when tenancy did NOT initialize
 * (the request arrived on a central host). This rejects a *platform* route
 * when the request did not arrive on a central host.
 *
 * Together they make the two surfaces disjoint by host:
 *   cpscs.radar.test  -> /api/v1/pps/*      yes, /api/v1/platform/*  no (404)
 *   radar.test        -> /api/v1/platform/* yes, /api/v1/pps/*       no (400)
 *
 * It judges the Host header rather than reading tenant(), which is a
 * deliberate choice and the opposite of what the first draft did. tenant() is
 * mutable global state; on any runtime that reuses a process across requests
 * (Octane, a queue worker, a test case that makes two calls) a tenant left
 * over from an earlier request would make a perfectly central request look
 * tenanted. The Host header is the request's own fact and cannot be stale.
 *
 * The predicate below is intentionally the same shape as the private
 * isCentralHost() in InitializeTenancyBySubdomain. Duplicating four lines of
 * config lookup is the lesser evil here: the alternative was to widen that
 * class's API or to trust mutable state, and this middleware must stay
 * readable on its own because it is the only thing standing between a school's
 * subdomain and the platform console.
 *
 * 404, not 403: on a school's subdomain the platform console does not exist at
 * all. Saying "forbidden" would confirm the route is real, and this runs before
 * authentication, so an unauthenticated caller must learn nothing.
 */
class EnsureCentralDomain
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isCentralHost($request->getHost())) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        // A central request must run in the central context. Normally tenancy
        // never started; this only bites when the process was reused after a
        // tenant request, in which case the search_path would still point at
        // that tenant's schema and Sanctum would look for admin tokens there.
        if (tenant()) {
            tenancy()->end();
        }

        return $next($request);
    }

    private function isCentralHost(string $host): bool
    {
        if (in_array($host, config('tenancy.central_domains', []), true)) {
            return true;
        }

        // Bare hostname (localhost) or a raw IP is never a subdomain.
        $parts = explode('.', $host);

        return count($parts) === 1
            || count(array_filter($parts, 'is_numeric')) === count($parts);
    }
}

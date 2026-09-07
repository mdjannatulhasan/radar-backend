<?php

declare(strict_types=1);

namespace SmsCore\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use SmsCore\Models\Domain;
use SmsCore\Models\Tenant;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the tenant from the request host, then swaps the Postgres
 * search_path to that tenant's schema.
 *
 * Resolution order:
 *   1. Exact host match in the central `domains` table (custom domains).
 *   2. First label of the host as a tenants.slug (cpscs.radar.app -> cpscs).
 *   3. Central domain or bare host -> no tenancy, request continues central.
 *
 * MUST be registered before auth:sanctum. personal_access_tokens lives in the
 * tenant schema, so a token from tenant A is invisible while tenant B is
 * active — isolation comes for free rather than from a forgotten where clause.
 */
class InitializeTenancyBySubdomain
{
    public function handle(Request $request, Closure $next): Response
    {
        $host = $request->getHost();

        if ($this->isCentralHost($host)) {
            return $next($request);
        }

        $tenant = $this->resolve($host);

        if (! $tenant) {
            return response()->json(['message' => 'Unknown tenant.'], 404);
        }

        if (! $tenant->isProvisioned() && ! app()->runningUnitTests()) {
            return response()->json(['message' => 'Tenant is not ready.'], 503);
        }

        tenancy()->initialize($tenant);

        return $next($request);
    }

    private function isCentralHost(string $host): bool
    {
        $central = config('tenancy.central_domains', []);

        if (in_array($host, $central, true)) {
            return true;
        }

        // Bare hostname (localhost) or a raw IP is never a subdomain.
        $parts = explode('.', $host);

        return count($parts) === 1
            || count(array_filter($parts, 'is_numeric')) === count($parts);
    }

    private function resolve(string $host): ?Tenant
    {
        $domain = Domain::where('domain', $host)->first();

        if ($domain) {
            return Tenant::find($domain->tenant_id);
        }

        $central = config('tenancy.central_domains', []);

        // Only treat the first label as a slug when the host actually sits
        // under one of our central domains — otherwise an attacker-controlled
        // third-party domain could aim at a tenant schema.
        //
        // The '.' prefix is load-bearing: a bare suffix check would accept
        // cpscs.attackerradar.test as a subdomain of radar.test, because that
        // string does literally end with "radar.test".
        $suffixes = array_map(static fn (string $d): string => '.'.$d, $central);

        if (! Str::endsWith($host, $suffixes)) {
            return null;
        }

        $slug = explode('.', $host)[0];

        return Tenant::where('slug', $slug)->first();
    }
}

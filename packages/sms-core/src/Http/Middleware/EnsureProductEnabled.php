<?php

declare(strict_types=1);

namespace SmsCore\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates a whole product for the current tenant: `middleware('product:radar')`.
 *
 * This is the subscription layer. Whether a given USER may see a given screen
 * inside an enabled product is a separate question, answered by
 * roles.enabled_features and the pps.can capability middleware.
 */
class EnsureProductEnabled
{
    public function handle(Request $request, Closure $next, string $product): Response
    {
        $tenant = tenant();

        if (! $tenant) {
            return response()->json(['message' => 'No tenant resolved.'], 400);
        }

        if (! $tenant->hasProduct($product)) {
            return response()->json(
                ['message' => 'This product is not enabled for your school.'],
                403
            );
        }

        return $next($request);
    }
}

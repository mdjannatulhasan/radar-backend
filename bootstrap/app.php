<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR |
                Request::HEADER_X_FORWARDED_HOST |
                Request::HEADER_X_FORWARDED_PORT |
                Request::HEADER_X_FORWARDED_PROTO |
                Request::HEADER_X_FORWARDED_AWS_ELB
        );
        $middleware->alias([
            'tenant' => \SmsCore\Http\Middleware\InitializeTenancyBySubdomain::class,
            'product' => \SmsCore\Http\Middleware\EnsureProductEnabled::class,
            'pps.role' => \App\Http\Middleware\PpsRoleMiddleware::class,
            'pps.permission' => \App\Http\Middleware\PpsPermissionMiddleware::class,
            'pps.permission_any' => \App\Http\Middleware\PpsPermissionAnyMiddleware::class,
            'pps.security' => \App\Http\Middleware\PpsSecurityMiddleware::class,
            'pps.can' => \App\Http\Middleware\PpsCapabilityMiddleware::class,
        ]);

        // Tenancy MUST run before authentication.
        //
        // Laravel sorts a route's middleware by this list, and anything absent
        // from it is pushed behind everything present. `tenant` and `product`
        // were absent, so `auth:sanctum` — which implements
        // AuthenticatesRequests — ran first, on the central `public` schema.
        // Sanctum then looked the bearer token up in
        // public.personal_access_tokens while the token had been minted into
        // tenant_<slug>.personal_access_tokens by /auth/login (which has no
        // auth middleware, so tenancy did initialize there). Every
        // authenticated request on a tenant subdomain came back 401, and the
        // test suite could not see it because Sanctum::actingAs() bypasses the
        // token lookup entirely.
        //
        // This is Laravel's default priority list with the two tenancy
        // middleware inserted ahead of AuthenticatesRequests. It is spelled out
        // in full rather than patched, because the ordering is the point.
        $middleware->priority([
            \Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests::class,
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \SmsCore\Http\Middleware\InitializeTenancyBySubdomain::class,
            \SmsCore\Http\Middleware\EnsureProductEnabled::class,
            \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            \Illuminate\Routing\Middleware\ThrottleRequests::class,
            \Illuminate\Routing\Middleware\ThrottleRequestsWithRedis::class,
            \Illuminate\Contracts\Session\Middleware\AuthenticatesSessions::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \Illuminate\Auth\Middleware\Authorize::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

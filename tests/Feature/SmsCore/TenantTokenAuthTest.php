<?php

declare(strict_types=1);

namespace Tests\Feature\SmsCore;

use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use SmsCore\Http\Middleware\EnsureProductEnabled;
use SmsCore\Http\Middleware\InitializeTenancyBySubdomain;
use Tests\TestCase;

/**
 * A real Sanctum token, minted by the login endpoint, must work on the tenant's
 * own subdomain.
 *
 * This is the one thing the rest of the suite could not see. Every other
 * authenticated test signs in with Sanctum::actingAs(), which injects the user
 * straight into the guard and never looks a token up in the database — so a
 * broken token lookup passes every test and fails every real request.
 *
 * It was broken. Laravel orders a route's middleware by the priority list, and
 * anything missing from that list is pushed behind everything on it. `tenant`
 * and `product` were missing, so `auth:sanctum` ran first, on the central
 * `public` schema, while /auth/login (which has no auth middleware, and so did
 * initialize tenancy) had written the token into `tenant_<slug>`. Sanctum
 * looked in the wrong schema and every authenticated request on a tenant
 * subdomain came back 401.
 *
 * The fix is the explicit priority list in bootstrap/app.php.
 *
 * Note which of the two tests below actually guards it. The round-trip test
 * documents the behaviour but CANNOT reproduce the bug in-process: Tests\TestCase
 * calls tenancy()->initialize() in setUp(), so the pgsql search_path is already
 * pointing at the tenant schema before any middleware runs, and Sanctum finds
 * the token wherever it looks. The ordering test is the one that fails when the
 * priority list is removed, and it is the reason this file exists.
 */
class TenantTokenAuthTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'TokenRoundTrip!2026';

    /**
     * Documents the round trip. Does not, on its own, catch the ordering bug —
     * see the class docblock; test_tenancy_and_the_product_gate_run_before_authentication
     * is the guard.
     */
    public function test_a_token_minted_by_login_authenticates_on_the_tenant_subdomain(): void
    {
        $user = $this->createUser([
            'name' => 'Token Round Trip',
            'email' => 'token@example.test',
            'role' => 'superadmin',
            'password' => Hash::make(self::PASSWORD),
        ]);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertCreated()->json('token');

        $this->assertIsString($token);

        // The token was written into the tenant's schema by the request above;
        // this one has to resolve the tenant before Sanctum reads it back.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.email', 'token@example.test');

        // …and on a RADAR route behind the capability middleware, which is
        // where the 401 actually bit users.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/pps/students')
            ->assertOk();
    }

    public function test_tenancy_and_the_product_gate_run_before_authentication(): void
    {
        $kernel = app(Kernel::class);

        $priority = (fn () => $this->middlewarePriority)->call($kernel);

        $tenant = array_search(InitializeTenancyBySubdomain::class, $priority, true);
        $product = array_search(EnsureProductEnabled::class, $priority, true);
        $auth = array_search(AuthenticatesRequests::class, $priority, true);

        $this->assertIsInt($tenant, 'Tenancy middleware must appear in the priority list.');
        $this->assertIsInt($product, 'Product gate must appear in the priority list.');
        $this->assertIsInt($auth, 'AuthenticatesRequests must appear in the priority list.');

        $this->assertLessThan($auth, $tenant, 'Tenancy must initialize before authentication.');
        $this->assertLessThan($auth, $product, 'The product gate must run before authentication.');
        $this->assertLessThan($product, $tenant, 'The product gate needs a resolved tenant.');
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\SmsCore;

use App\Http\Controllers\Api\V1\Platform\PlatformAuthController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use SmsCore\Models\Admin;
use SmsCore\Models\School;
use SmsCore\Models\Tenant;
use SmsCore\Models\TenantProduct;
use SmsCore\Models\User;
use Tests\TestCase;

/**
 * The platform console: who may reach it, and what it can do.
 *
 * Shaped after ProductGateTest — no ambient tenant ($tenantSlug = null), each
 * test builds the tenants it needs, and tearDown drops the schemas those
 * creations really made.
 *
 * The separation this file exists to pin down is that a platform super admin
 * and a school user are different principals with non-interchangeable tokens,
 * enforced on three independent axes: host, token storage schema, and
 * tokenable type.
 */
class PlatformConsoleTest extends TestCase
{
    use RefreshDatabase;

    protected ?string $tenantSlug = null;

    protected $connectionsToTransact = ['pgsql', 'central'];

    private const PASSWORD = 'PlatformConsole!2026';

    protected function tearDown(): void
    {
        foreach (['cpscs', 'newschool', 'throwaway'] as $slug) {
            DB::connection('central')->statement("DROP SCHEMA IF EXISTS tenant_{$slug} CASCADE");
        }

        parent::tearDown();
    }

    private function admin(): Admin
    {
        return Admin::create([
            'name' => 'Platform Super Admin',
            'email' => 'platform@radar.local',
            'password' => Hash::make(self::PASSWORD),
        ]);
    }

    private function readyTenant(string $slug, string $status = 'active'): Tenant
    {
        $tenant = Tenant::create([
            'id' => $slug,
            'name' => strtoupper($slug),
            'slug' => $slug,
            'provisioning_status' => 'ready',
        ]);

        TenantProduct::create(['tenant_id' => $slug, 'product' => 'radar', 'status' => $status]);

        return $tenant;
    }

    /**
     * A real school user, inside the tenant's own schema.
     *
     * users.school_id is NOT NULL, so the school has to exist first — these
     * tests run with $tenantSlug = null and therefore get none of
     * TaxonomyFixtures' ambient scaffolding.
     *
     * Leaves tenancy ended, because the caller is about to make a request to a
     * central host.
     */
    private function schoolUser(Tenant $tenant): User
    {
        tenancy()->initialize($tenant);

        $school = School::create(['name' => 'CPSCS', 'slug' => 'cpscs']);

        $user = User::create([
            'name' => 'School Superadmin',
            'email' => 'superadmin@radar.local',
            'role' => 'superadmin',
            'password' => Hash::make(self::PASSWORD),
            'school_id' => $school->id,
        ]);

        tenancy()->end();

        return $user;
    }

    /** Log in for real and return the bearer token. */
    private function platformToken(): string
    {
        $this->admin();

        return $this->postJson('http://radar.test/api/v1/platform/auth/login', [
            'email' => 'platform@radar.local',
            'password' => self::PASSWORD,
        ])->assertCreated()->json('token');
    }

    /**
     * Put a platform admin on the admin guard without a token round trip.
     *
     * The console tests below are about what the console does, not about token
     * plumbing, and a real bearer token cannot be replayed in-process here:
     * RefreshDatabase opens one transaction on `pgsql` and another on
     * `central`, Admin::createToken() writes the token through `central`, and
     * Sanctum's findToken() reads it back through `pgsql` — which cannot see
     * another connection's uncommitted rows. Under a real request there are no
     * transactions and both names resolve to the same public table, so this is
     * a harness artifact rather than a product one. The token path is covered
     * separately by the isolation tests above (which assert where the row
     * lands) and end-to-end over HTTP.
     */
    private function actingAsAdmin(): static
    {
        Sanctum::actingAs($this->admin(), ['*'], 'admin');

        return $this;
    }

    // ---------------------------------------------------------------- login

    public function test_platform_login_succeeds_on_a_central_host(): void
    {
        $this->admin();

        $this->postJson('http://radar.test/api/v1/platform/auth/login', [
            'email' => 'platform@radar.local',
            'password' => self::PASSWORD,
        ])
            ->assertCreated()
            ->assertJsonPath('admin.email', 'platform@radar.local')
            ->assertJsonStructure(['token', 'expires_at', 'admin' => ['id', 'name', 'email']]);
    }

    public function test_platform_login_is_rejected_on_a_tenant_host(): void
    {
        $this->admin();
        $this->readyTenant('cpscs');

        // 404, not 403: on a school's subdomain the console does not exist.
        $this->postJson('http://cpscs.radar.test/api/v1/platform/auth/login', [
            'email' => 'platform@radar.local',
            'password' => self::PASSWORD,
        ])->assertNotFound();
    }

    public function test_a_school_users_credentials_are_rejected_by_the_platform_guard(): void
    {
        $this->admin();

        // A real school superadmin, sitting in the tenant schema.
        $this->schoolUser($this->readyTenant('cpscs'));

        $this->postJson('http://radar.test/api/v1/platform/auth/login', [
            'email' => 'superadmin@radar.local',
            'password' => self::PASSWORD,
        ])->assertStatus(422);
    }

    public function test_a_platform_admins_credentials_are_rejected_by_the_school_login(): void
    {
        $this->admin();
        $this->readyTenant('cpscs');

        $this->postJson('http://cpscs.radar.test/api/v1/auth/login', [
            'email' => 'platform@radar.local',
            'password' => self::PASSWORD,
        ])->assertStatus(422);
    }

    // ------------------------------------------------------- token crossing

    /**
     * The whole isolation story rests on where this row lands. A platform token
     * must exist in public.personal_access_tokens and nowhere else — in
     * particular not in any school's schema, which is the table Sanctum reads
     * when a request arrives on that school's subdomain.
     */
    public function test_a_platform_token_is_minted_into_the_central_schema(): void
    {
        $tenant = $this->readyTenant('cpscs');
        $this->schoolUser($tenant);

        $token = $this->platformToken();
        $this->assertNotEmpty($token);

        $this->assertSame(
            1,
            (int) DB::connection('central')->selectOne(
                "select count(*) c from public.personal_access_tokens where tokenable_type like '%Admin'"
            )->c,
            'The platform token belongs in the central schema.',
        );

        // Read the tenant side over the tenant's own connection — `central` is
        // a separate transaction and could not see these rows either way.
        tenancy()->initialize($tenant);

        $this->assertSame(
            0,
            (int) DB::connection('pgsql')->selectOne(
                "select count(*) c from personal_access_tokens where tokenable_type like '%Admin'"
            )->c,
            'No platform token may exist inside a school schema.',
        );

        tenancy()->end();
    }

    public function test_a_platform_token_cannot_reach_the_radar_api(): void
    {
        $token = $this->platformToken();
        $this->readyTenant('cpscs');

        // On the school's subdomain Sanctum reads
        // tenant_cpscs.personal_access_tokens, where this token is not.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('http://cpscs.radar.test/api/v1/pps/students')
            ->assertUnauthorized();
    }

    public function test_a_platform_token_on_a_central_host_still_cannot_reach_the_radar_api(): void
    {
        $token = $this->platformToken();

        // Here the token IS findable (search_path is public), so the thing that
        // stops it is the product gate having no tenant at all.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('http://radar.test/api/v1/pps/students')
            ->assertStatus(400)
            ->assertJsonPath('message', 'No tenant resolved.');
    }

    public function test_a_school_token_cannot_reach_the_platform_api(): void
    {
        $tenant = $this->readyTenant('cpscs');
        $user = $this->schoolUser($tenant);

        tenancy()->initialize($tenant);
        $token = $user->createToken('spec')->plainTextToken;
        tenancy()->end();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('http://radar.test/api/v1/platform/tenants')
            ->assertUnauthorized();
    }

    /**
     * Sanctum's guard returns whatever model a token hangs off without checking
     * it against the guard's provider. Host and schema separation mean a school
     * user's token is not findable here in practice — so this drives the type
     * check directly, by putting a User on the admin guard.
     */
    public function test_the_platform_guard_refuses_a_non_admin_principal(): void
    {
        $user = $this->schoolUser($this->readyTenant('cpscs'));

        Sanctum::actingAs($user, ['*'], 'admin');

        $this->getJson('http://radar.test/api/v1/platform/tenants')
            ->assertUnauthorized();
    }

    public function test_tenants_cannot_be_enumerated_without_authentication(): void
    {
        $this->readyTenant('cpscs');

        $this->getJson('http://radar.test/api/v1/platform/tenants')
            ->assertUnauthorized()
            ->assertJsonMissing(['slug' => 'cpscs']);
    }

    // -------------------------------------------------------------- console

    public function test_an_authenticated_admin_lists_tenants_with_their_products(): void
    {
        $this->actingAsAdmin();
        $this->readyTenant('cpscs', 'trial');

        $this->getJson('http://radar.test/api/v1/platform/tenants')
            ->assertOk()
            ->assertJsonPath('tenants.0.slug', 'cpscs')
            ->assertJsonPath('tenants.0.provisioning_status', 'ready')
            ->assertJsonPath('tenants.0.products.0.product', 'radar')
            ->assertJsonPath('tenants.0.products.0.status', 'trial')
            ->assertJsonPath('tenants.0.products.0.enabled', true)
            ->assertJsonStructure(['tenants' => [['slug', 'name', 'provisioning_status', 'migrated_at', 'products']], 'products', 'statuses']);
    }

    public function test_an_admin_can_provision_a_tenant(): void
    {
        $this->actingAsAdmin();

        $this->postJson('http://radar.test/api/v1/platform/tenants', [
                'slug' => 'newschool',
                'name' => 'New School',
                'products' => ['radar'],
                'status' => 'active',
            ])
            ->assertCreated()
            ->assertJsonPath('tenant.slug', 'newschool')
            ->assertJsonPath('tenant.provisioning_status', 'ready')
            ->assertJsonPath('tenant.products.0.product', 'radar');

        // The schema really exists and really carries the tenant tables.
        //
        // Queried over `pgsql`, not `central`: the migrations ran on the tenant
        // connection, which under RefreshDatabase is a different transaction
        // from central's, and Postgres keeps DDL transactional — so central
        // cannot see these tables until commit, which never happens in a test.
        $this->assertGreaterThan(
            0,
            (int) DB::connection('pgsql')->selectOne(
                "select count(*) c from pg_tables where schemaname = 'tenant_newschool' and tablename = 'students'"
            )->c,
            'Provisioning must migrate the new tenant schema, not just create it.',
        );
    }

    public function test_provisioning_rejects_a_duplicate_slug(): void
    {
        $this->actingAsAdmin();
        $this->readyTenant('cpscs');

        $this->postJson('http://radar.test/api/v1/platform/tenants', [
                'slug' => 'cpscs',
                'name' => 'Impostor',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('slug');
    }

    public function test_an_admin_can_change_a_products_status_and_dates(): void
    {
        $this->actingAsAdmin();
        $this->readyTenant('cpscs');

        $this->patchJson('http://radar.test/api/v1/platform/tenants/cpscs/products/radar', [
                'status' => 'trial',
                'plan' => 'pilot',
                'trial_ends_at' => now()->addDays(14)->toIso8601String(),
            ])
            ->assertOk()
            ->assertJsonPath('product.status', 'trial')
            ->assertJsonPath('product.plan', 'pilot')
            ->assertJsonPath('product.enabled', true);

        $this->assertDatabaseHas('tenant_products', [
            'tenant_id' => 'cpscs',
            'product' => 'radar',
            'status' => 'trial',
            'plan' => 'pilot',
        ], 'central');
    }

    public function test_an_unknown_status_is_rejected(): void
    {
        $this->actingAsAdmin();
        $this->readyTenant('cpscs');

        $this->patchJson('http://radar.test/api/v1/platform/tenants/cpscs/products/radar', [
                'status' => 'free-forever',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    // ------------------------------------------------ the console moves the gate

    /**
     * The point of the whole feature: what the console writes is what
     * EnsureProductEnabled reads. Follows ProductGateTest's shape, but drives
     * the change through the API rather than the model.
     */
    public function test_suspending_a_product_403s_that_tenants_radar_routes(): void
    {
        $this->actingAsAdmin();
        $this->readyTenant('cpscs');

        // Enabled: the gate passes and the request dies on credentials instead.
        $this->postJson('http://cpscs.radar.test/api/v1/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'wrong',
        ])->assertStatus(422);

        $this->patchJson('http://radar.test/api/v1/platform/tenants/cpscs/products/radar', [
                'status' => 'suspended',
            ])
            ->assertOk()
            ->assertJsonPath('product.enabled', false);

        $this->postJson('http://cpscs.radar.test/api/v1/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'wrong',
        ])
            ->assertForbidden()
            ->assertJsonPath('message', 'This product is not enabled for your school.');

        // …and reactivating reopens it.
        $this->patchJson('http://radar.test/api/v1/platform/tenants/cpscs/products/radar', [
                'status' => 'active',
            ])
            ->assertOk()
            ->assertJsonPath('product.enabled', true);

        $this->postJson('http://cpscs.radar.test/api/v1/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'wrong',
        ])->assertStatus(422);
    }

    public function test_a_platform_token_carries_only_the_platform_ability(): void
    {
        $token = $this->platformToken();

        // Read back over the central connection, not PersonalAccessToken's
        // default one: RefreshDatabase wraps `pgsql` and `central` in two
        // separate transactions, so a row written through one is invisible to
        // the other for the duration of the test.
        $row = DB::connection('central')->selectOne(
            'select abilities from public.personal_access_tokens where token = ?',
            [hash('sha256', explode('|', $token, 2)[1])],
        );

        $this->assertNotNull($row, 'The platform token should exist in the central schema.');
        $this->assertSame([PlatformAuthController::ABILITY], json_decode($row->abilities, true));
    }
}

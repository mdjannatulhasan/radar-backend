<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;
use SmsCore\Models\Tenant;
use SmsCore\Models\TenantProduct;

abstract class TestCase extends BaseTestCase
{
    /**
     * Tests that exercise RADAR endpoints need a resolved tenant. Setting
     * $tenantSlug provisions it, enables RADAR for it, initialises tenancy,
     * and rewrites the default request host so every $this->getJson('/api/...')
     * lands on that tenant's subdomain. Set it to null to opt out — every
     * test that provisions its own tenant(s) directly (everything under
     * tests/Feature/SmsCore) must do this, or it collides with the tenant
     * this class creates.
     *
     * FINDING (see the 1.6/1.7 handoff notes): tenancy()->initialize() below
     * does NOT swap the Postgres search_path, cache prefix, filesystem paths,
     * or queue connection, even though config/tenancy.php lists
     * DatabaseTenancyBootstrapper etc. under `bootstrappers`. Those only run
     * via Stancl\Tenancy\Listeners\BootstrapTenancy, which stancl's own
     * TenancyServiceProvider deliberately leaves unregistered — that wiring
     * normally lives in an app-published TenancyServiceProvider, which this
     * platform doesn't have (SmsCoreServiceProvider only wires TenantCreated
     * -> CreateDatabase, not TenancyInitialized/TenancyEnded -> bootstrap()).
     * So today, initializing tenancy only sets the tenant context that the
     * tenant()/tenant('slug') helpers read; every query still runs against
     * the `pgsql` connection's normal search_path (`public`), where all of
     * RADAR's existing tables already live. That is exactly why the
     * "tenant schema is empty" problem the plan anticipated does not yet
     * bite here — there's no per-tenant connection switch to fall foul of
     * it. Wiring up that listener belongs with Phase 2, alongside the
     * tenant-schema migrations it would actually need to switch into; doing
     * it now would just swap every RADAR query onto an empty schema with
     * nothing in it, which is strictly worse. Do not "fix" this by adding
     * that listener without also giving tenants real schemas to point at.
     */
    protected ?string $tenantSlug = 'cpscs';

    protected ?Tenant $tenant = null;

    protected $connectionsToTransact = ['pgsql', 'central'];

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->tenantSlug === null) {
            return;
        }

        // Provisioning a tenant does real writes (a `tenants`/
        // `tenant_products` row, plus a physical `CREATE SCHEMA`) that are
        // only ever cleaned up because RefreshDatabase wraps
        // $connectionsToTransact in a transaction it rolls back after the
        // test. A TestCase subclass that doesn't `use RefreshDatabase` (e.g.
        // tests/Feature/ExampleTest.php) has no such transaction, so this
        // would otherwise commit a `cpscs` tenant and a `tenant_cpscs`
        // schema into the real database, permanently, on every run.
        //
        // 'db.transactions' is NOT a usable signal here — DatabaseServiceProvider
        // binds it unconditionally, RefreshDatabase or not. Check for the
        // trait itself instead.
        if (! in_array(RefreshDatabase::class, class_uses_recursive(static::class), true)) {
            return;
        }

        $this->tenant = Tenant::create([
            'id' => $this->tenantSlug,
            'name' => 'Test School',
            'slug' => $this->tenantSlug,
            'provisioning_status' => 'ready',
        ]);

        TenantProduct::create([
            'tenant_id' => $this->tenantSlug,
            'product' => 'radar',
            'status' => 'active',
        ]);

        $this->withServerVariables([
            'HTTP_HOST' => $this->tenantSlug.'.'.config('tenancy.central_domains')[0],
        ]);

        tenancy()->initialize($this->tenant);
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    protected function signInPps(User $user): static
    {
        Sanctum::actingAs($user, $user->permissions());

        return $this;
    }
}

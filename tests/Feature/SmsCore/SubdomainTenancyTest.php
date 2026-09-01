<?php

declare(strict_types=1);

namespace Tests\Feature\SmsCore;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use SmsCore\Http\Middleware\InitializeTenancyBySubdomain;
use SmsCore\Models\Domain;
use SmsCore\Models\Tenant;
use Tests\TestCase;

class SubdomainTenancyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * RefreshDatabase only wraps config('database.default') ('pgsql') in a
     * transaction by default. Tenant/Domain rows live on the `central`
     * connection, so without adding it here, rows inserted in one test
     * method leak into the next. `pgsql` is also where stancl's
     * PostgreSQLSchemaManager issues `CREATE SCHEMA`, so keeping it in this
     * list means the schema itself is rolled back with everything else.
     *
     * @var array<int, string>
     */
    protected $connectionsToTransact = ['pgsql', 'central'];

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(InitializeTenancyBySubdomain::class)
            ->get('/_tenancy-probe', fn () => response()->json([
                'tenant' => tenancy()->initialized ? tenant('slug') : null,
            ]));
    }

    protected function tearDown(): void
    {
        \DB::connection('central')->statement('DROP SCHEMA IF EXISTS tenant_cpscs CASCADE');

        parent::tearDown();
    }

    public function test_subdomain_initializes_the_matching_tenant(): void
    {
        Tenant::create(['id' => 'cpscs', 'name' => 'CPSCS', 'slug' => 'cpscs', 'provisioning_status' => 'ready']);

        $this->getJson('http://cpscs.radar.test/_tenancy-probe')
            ->assertOk()
            ->assertJson(['tenant' => 'cpscs']);
    }

    public function test_unknown_subdomain_returns_404_json(): void
    {
        $this->getJson('http://nosuchschool.radar.test/_tenancy-probe')
            ->assertNotFound()
            ->assertJson(['message' => 'Unknown tenant.']);
    }

    public function test_central_domain_does_not_initialize_tenancy(): void
    {
        $this->getJson('http://radar.test/_tenancy-probe')
            ->assertOk()
            ->assertJson(['tenant' => null]);
    }

    public function test_custom_domain_resolves_to_its_tenant(): void
    {
        Tenant::create(['id' => 'cpscs', 'name' => 'CPSCS', 'slug' => 'cpscs', 'provisioning_status' => 'ready']);
        Domain::create(['domain' => 'sms.cpscs.edu.bd', 'tenant_id' => 'cpscs']);

        $this->getJson('http://sms.cpscs.edu.bd/_tenancy-probe')
            ->assertOk()
            ->assertJson(['tenant' => 'cpscs']);
    }

    public function test_lookalike_domain_is_rejected(): void
    {
        Tenant::create(['id' => 'cpscs', 'name' => 'CPSCS', 'slug' => 'cpscs', 'provisioning_status' => 'ready']);

        // Literally ends with "radar.test" but is NOT a subdomain of it.
        // A bare Str::endsWith check would resolve tenant cpscs here.
        $this->getJson('http://cpscs.attackerradar.test/_tenancy-probe')
            ->assertNotFound()
            ->assertJson(['message' => 'Unknown tenant.']);
    }

    public function test_third_party_domain_is_rejected(): void
    {
        Tenant::create(['id' => 'cpscs', 'name' => 'CPSCS', 'slug' => 'cpscs', 'provisioning_status' => 'ready']);

        // cpscs.evil.example is NOT under a central domain and has no domains row.
        $this->getJson('http://cpscs.evil.example/_tenancy-probe')
            ->assertNotFound();
    }
}

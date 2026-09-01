<?php

declare(strict_types=1);

namespace Tests\Feature\SmsCore;

use Illuminate\Foundation\Testing\RefreshDatabase;
use SmsCore\Models\Tenant;
use SmsCore\Models\TenantProduct;
use Tests\TestCase;

class TenantProvisioningTest extends TestCase
{
    use RefreshDatabase;

    /**
     * RefreshDatabase only wraps config('database.default') ('pgsql') in a
     * transaction by default. Tenant/TenantProduct rows live on the `central`
     * connection, so without adding it here, rows inserted in one test method
     * leak into the next (they share one Postgres transaction/session per
     * connection for the whole test method, but connections outside this list
     * are never rolled back at all). `pgsql` is also where stancl's
     * PostgreSQLSchemaManager issues `CREATE SCHEMA` (via the
     * template_tenant_connection), so keeping it in this list means the
     * schema itself is rolled back with everything else.
     */
    protected $connectionsToTransact = ['pgsql', 'central'];

    protected function tearDown(): void
    {
        \DB::connection('central')->statement('DROP SCHEMA IF EXISTS tenant_cpscs CASCADE');

        parent::tearDown();
    }

    public function test_creating_a_tenant_creates_its_postgres_schema(): void
    {
        $tenant = Tenant::create(['id' => 'cpscs', 'name' => 'CPSCS Saidpur', 'slug' => 'cpscs']);

        // stancl issues `CREATE SCHEMA` on tenancy.database.template_tenant_connection
        // ('pgsql' in config/tenancy.php), not on the `central` connection. That
        // statement runs inside the still-open `pgsql` test transaction (see
        // $connectionsToTransact above), so it's only visible to a query on that
        // same connection/session until the transaction commits or rolls back —
        // a separate Postgres session (e.g. `central`) cannot see it yet.
        $exists = \DB::connection('pgsql')->select(
            "SELECT schema_name FROM information_schema.schemata WHERE schema_name = ?",
            ['tenant_cpscs']
        );

        $this->assertNotEmpty($exists, 'Expected schema tenant_cpscs to be created');
        $this->assertSame('cpscs', $tenant->slug);
    }

    public function test_tenant_reports_which_products_are_enabled(): void
    {
        $tenant = Tenant::create(['id' => 'cpscs', 'name' => 'CPSCS Saidpur', 'slug' => 'cpscs']);

        TenantProduct::create(['tenant_id' => 'cpscs', 'product' => 'radar', 'status' => 'active']);
        TenantProduct::create(['tenant_id' => 'cpscs', 'product' => 'routine', 'status' => 'suspended']);

        $this->assertTrue($tenant->hasProduct('radar'));
        $this->assertFalse($tenant->hasProduct('routine'));
        $this->assertFalse($tenant->hasProduct('nonexistent'));
    }
}

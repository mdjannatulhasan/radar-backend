<?php

namespace Tests;

use SmsCore\Models\User;
use Tests\Support\TaxonomyFixtures;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use SmsCore\Models\Tenant;
use SmsCore\Models\TenantProduct;

abstract class TestCase extends BaseTestCase
{
    use TaxonomyFixtures;

    /**
     * Tests that exercise RADAR endpoints need a resolved tenant. Setting
     * $tenantSlug provisions it, enables RADAR for it, initialises tenancy,
     * and rewrites the default request host so every $this->getJson('/api/...')
     * lands on that tenant's subdomain. Set it to null to opt out — every
     * test that provisions its own tenant(s) directly (everything under
     * tests/Feature/SmsCore) must do this, or it collides with the tenant
     * this class creates.
     *
     * The slug is deliberately NOT 'cpscs': several tests under
     * tests/Feature/SmsCore provision a tenant named 'cpscs' themselves, and
     * this one's schema is built once per process and COMMITTED, so sharing the
     * name makes every one of them die on TenantDatabaseAlreadyExistsException.
     */
    protected ?string $tenantSlug = 'radartest';

    protected ?Tenant $tenant = null;

    protected $connectionsToTransact = ['pgsql', 'central'];

    /**
     * Connection used only to build tenant schemas. It is deliberately NOT in
     * $connectionsToTransact: its work has to COMMIT so that every later
     * connection can see the migrated schema.
     */
    private const SCHEMA_BUILD_CONNECTION = 'sms_core_schema_build';

    /**
     * Tenant slugs whose schema has already been migrated in this PHP process.
     *
     * @var array<int, string>
     */
    private static array $migratedTenantSchemas = [];

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

        $this->migrateTenantSchemaOnce($this->tenantSlug);

        $this->tenant = Tenant::create([
            'id' => $this->tenantSlug,
            'name' => 'RADAR Test School',
            'slug' => $this->tenantSlug,
            'provisioning_status' => 'ready',
            // The schema already exists and is migrated (see
            // migrateTenantSchemaOnce). Without this, stancl's CreateDatabase
            // listener would try to CREATE SCHEMA again and throw
            // TenantDatabaseAlreadyExistsException.
            'tenancy_create_database' => false,
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
        // tenancy()->end() issues `set search_path to public` on the pgsql
        // connection. A test that failed on a bad query leaves that
        // connection's transaction in Postgres' *aborted* state, where every
        // statement — including that one — errors. If that exception escaped,
        // parent::tearDown() would never run, RefreshDatabase would never roll
        // back, and the next test would block forever on the rows this one
        // left uncommitted. The rollback below makes the search_path moot
        // anyway, so failing to reset it is not information worth propagating.
        try {
            if (tenancy()->initialized) {
                tenancy()->end();
            }
        } catch (\Throwable) {
            // Intentionally ignored; see above.
        }

        parent::tearDown();
    }

    /**
     * Build and migrate a tenant schema once per PHP process.
     *
     * The alternative — migrating the tenant schema inside each test's
     * transaction — is honest but runs 30-odd migrations per test. This is
     * the same bargain RefreshDatabase already strikes for the central
     * schema: build the structure once per process with `migrate:fresh`,
     * then let a per-test transaction roll back the rows. The schema is
     * created on a connection outside $connectionsToTransact so that it
     * COMMITS and every later connection can see it; every row a test then
     * writes into it goes through `pgsql`, which IS transacted, so no test
     * can see another test's rows. The schema is dropped and rebuilt at the
     * start of each process, so a stale one can never be inherited.
     */
    private function migrateTenantSchemaOnce(string $slug): void
    {
        if (in_array($slug, self::$migratedTenantSchemas, true)) {
            return;
        }

        $schema = config('tenancy.database.prefix').$slug.config('tenancy.database.suffix');

        config([
            'database.connections.'.self::SCHEMA_BUILD_CONNECTION => array_merge(
                config('database.connections.'.config('tenancy.database.template_tenant_connection')),
                ['search_path' => $schema],
            ),
        ]);

        DB::purge(self::SCHEMA_BUILD_CONNECTION);

        $build = DB::connection(self::SCHEMA_BUILD_CONNECTION);
        $build->statement('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');
        $build->statement('CREATE SCHEMA "'.$schema.'"');

        self::dropSchemaOnShutdown($build->getConfig(), $schema);

        $exitCode = Artisan::call('migrate', [
            '--database' => self::SCHEMA_BUILD_CONNECTION,
            '--path' => config('tenancy.migration_parameters.--path'),
            '--realpath' => config('tenancy.migration_parameters.--realpath', false),
            '--force' => true,
        ]);

        if ($exitCode !== 0) {
            throw new \RuntimeException(
                "Failed to migrate tenant schema {$schema}:\n".Artisan::output()
            );
        }

        DB::purge(self::SCHEMA_BUILD_CONNECTION);

        self::$migratedTenantSchemas[] = $slug;
    }

    /**
     * The build schema is committed, so nothing in the test lifecycle rolls it
     * back. Drop it when the process ends rather than leaving a tenant_* schema
     * behind in the test database after every run. Raw PDO, because by the time
     * shutdown functions run the Laravel container is gone.
     *
     * @param  array<string, mixed>  $config
     */
    private static function dropSchemaOnShutdown(array $config, string $schema): void
    {
        register_shutdown_function(static function () use ($config, $schema): void {
            try {
                $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $config['host'], $config['port'], $config['database']);
                (new \PDO($dsn, $config['username'], $config['password']))
                    ->exec('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');
            } catch (\Throwable) {
                // Best effort: the next run drops and recreates the schema anyway.
            }
        });
    }

    protected function signInPps(User $user): static
    {
        Sanctum::actingAs($user, $user->permissions());

        return $this;
    }
}

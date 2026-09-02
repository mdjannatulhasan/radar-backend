<?php

declare(strict_types=1);

namespace SmsCore\Services;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use SmsCore\Models\Tenant;
use SmsCore\Models\TenantProduct;

/**
 * The one place a tenant gets provisioned.
 *
 * This logic used to live inline in TenantCreateCommand. The platform console
 * needs exactly the same steps over HTTP, and two copies of "create the row,
 * create the schema, migrate it, enable the products, flip the status" would
 * drift the first time either changed — so the command and the controller now
 * both call this.
 *
 * Provisioning is deliberately SYNCHRONOUS. Measured on the development box a
 * full run (CREATE SCHEMA + 35 tenant migrations) takes ~2.8s, which sits well
 * inside a normal request budget; a queued job would buy nothing but a polling
 * loop and a worker that has to be running for the console to work at all.
 * `provisioning_status` still carries the full pending -> migrating -> ready
 * progression so a caller can tell a half-built tenant from a finished one,
 * and a failure lands on 'failed' rather than leaving a row that claims to be
 * ready. If schema creation ever grows past a few seconds, the seam to move it
 * behind a queue is this class and nothing else.
 */
class TenantProvisioner
{
    /** Statuses a subscription may hold. */
    public const PRODUCT_STATUSES = ['active', 'trial', 'suspended'];

    /**
     * @param  list<string>  $products
     *
     * @throws RuntimeException when the slug is taken or migrations fail
     */
    public function provision(
        string $slug,
        string $name,
        array $products = ['radar'],
        string $status = 'active',
    ): Tenant {
        if (Tenant::where('slug', $slug)->orWhere('id', $slug)->exists()) {
            throw new RuntimeException("Tenant '{$slug}' already exists.");
        }

        // Creating the row fires TenantCreated, whose job pipeline runs
        // CREATE SCHEMA tenant_<slug> and migrates it. See
        // SmsCoreServiceProvider::bootTenancyEvents().
        $tenant = Tenant::create([
            'id' => $slug,
            'slug' => $slug,
            'name' => $name,
            'provisioning_status' => 'migrating',
        ]);

        try {
            // Second, idempotent pass. The pipeline above already migrated the
            // schema; this catches the case where it was skipped and gives a
            // non-zero exit code to fail on.
            $exit = Artisan::call('tenants:migrate', ['--tenants' => [$tenant->id]]);

            if ($exit !== 0) {
                throw new RuntimeException("Tenant migrations failed for '{$slug}'.");
            }

            foreach ($products as $product) {
                $product = trim($product);

                if ($product === '') {
                    continue;
                }

                TenantProduct::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'product' => $product],
                    ['status' => $status],
                );
            }

            $tenant->update([
                'provisioning_status' => 'ready',
                'migrated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Leave evidence rather than a row that lies about being ready.
            // InitializeTenancyBySubdomain 503s anything not 'ready', so a
            // failed tenant is unreachable rather than half-open.
            $tenant->update(['provisioning_status' => 'failed']);

            throw $e;
        }

        return $tenant->refresh();
    }

    /**
     * Tear a tenant down: drop its schema, then its central rows.
     *
     * Used by the throwaway tenants in the test suite and available to the
     * console. Deliberately not wired to an HTTP route — dropping a school's
     * entire schema is not a thing a web request should be able to do.
     */
    public function destroy(Tenant $tenant): void
    {
        $prefix = config('tenancy.database.prefix', 'tenant_');
        $schema = $prefix.$tenant->id;

        // Quoted so a slug can never be read as more than one identifier.
        DB::connection('central')->statement(
            'DROP SCHEMA IF EXISTS "'.str_replace('"', '', $schema).'" CASCADE'
        );

        $tenant->products()->delete();
        $tenant->delete();
    }
}

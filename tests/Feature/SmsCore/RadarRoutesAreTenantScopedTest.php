<?php

declare(strict_types=1);

namespace Tests\Feature\SmsCore;

use Illuminate\Foundation\Testing\RefreshDatabase;
use SmsCore\Models\Tenant;
use SmsCore\Models\TenantProduct;
use Tests\TestCase;

class RadarRoutesAreTenantScopedTest extends TestCase
{
    use RefreshDatabase;

    protected ?string $tenantSlug = null;

    protected $connectionsToTransact = ['pgsql', 'central'];

    protected function tearDown(): void
    {
        \DB::connection('central')->statement('DROP SCHEMA IF EXISTS tenant_other CASCADE');
        \DB::connection('central')->statement('DROP SCHEMA IF EXISTS tenant_cpscs CASCADE');

        parent::tearDown();
    }

    public function test_radar_api_on_central_domain_is_rejected(): void
    {
        // No subdomain -> no tenant -> the product gate has nothing to check.
        $this->postJson('http://radar.test/api/v1/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'wrong',
        ])->assertStatus(400);
    }

    public function test_radar_api_for_a_tenant_without_radar_is_forbidden(): void
    {
        Tenant::create(['id' => 'other', 'name' => 'Other School', 'slug' => 'other', 'provisioning_status' => 'ready']);

        $this->postJson('http://other.radar.test/api/v1/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'wrong',
        ])->assertForbidden();
    }

    public function test_radar_api_for_an_enabled_tenant_reaches_the_controller(): void
    {
        Tenant::create(['id' => 'cpscs', 'name' => 'CPSCS', 'slug' => 'cpscs', 'provisioning_status' => 'ready']);
        TenantProduct::create(['tenant_id' => 'cpscs', 'product' => 'radar', 'status' => 'active']);

        // Reaches AuthController and fails on credentials/validation, not the gate.
        $this->postJson('http://cpscs.radar.test/api/v1/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'wrong',
        ])->assertStatus(422);
    }
}

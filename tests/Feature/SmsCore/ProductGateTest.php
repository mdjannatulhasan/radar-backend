<?php

declare(strict_types=1);

namespace Tests\Feature\SmsCore;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use SmsCore\Http\Middleware\EnsureProductEnabled;
use SmsCore\Http\Middleware\InitializeTenancyBySubdomain;
use SmsCore\Models\Tenant;
use SmsCore\Models\TenantProduct;
use Tests\TestCase;

class ProductGateTest extends TestCase
{
    use RefreshDatabase;

    protected $connectionsToTransact = ['pgsql', 'central'];

    protected ?string $tenantSlug = null;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware([InitializeTenancyBySubdomain::class, EnsureProductEnabled::class.':radar'])
            ->get('/_radar-probe', fn () => response()->json(['ok' => true]));
    }

    protected function tearDown(): void
    {
        \DB::connection('central')->statement('DROP SCHEMA IF EXISTS tenant_cpscs CASCADE');

        parent::tearDown();
    }

    private function tenant(): Tenant
    {
        return Tenant::create([
            'id' => 'cpscs', 'name' => 'CPSCS', 'slug' => 'cpscs',
            'provisioning_status' => 'ready',
        ]);
    }

    public function test_tenant_with_active_radar_may_pass(): void
    {
        $this->tenant();
        TenantProduct::create(['tenant_id' => 'cpscs', 'product' => 'radar', 'status' => 'active']);

        $this->getJson('http://cpscs.radar.test/_radar-probe')
            ->assertOk()
            ->assertJson(['ok' => true]);
    }

    public function test_tenant_on_live_trial_may_pass(): void
    {
        $this->tenant();
        TenantProduct::create([
            'tenant_id' => 'cpscs', 'product' => 'radar', 'status' => 'trial',
            'trial_ends_at' => now()->addDays(14),
        ]);

        $this->getJson('http://cpscs.radar.test/_radar-probe')->assertOk();
    }

    public function test_tenant_without_radar_is_blocked(): void
    {
        $this->tenant();

        $this->getJson('http://cpscs.radar.test/_radar-probe')
            ->assertForbidden()
            ->assertJson(['message' => 'This product is not enabled for your school.']);
    }

    public function test_lapsed_trial_is_blocked(): void
    {
        $this->tenant();
        TenantProduct::create([
            'tenant_id' => 'cpscs', 'product' => 'radar', 'status' => 'trial',
            'trial_ends_at' => now()->subDay(),
        ]);

        $this->getJson('http://cpscs.radar.test/_radar-probe')->assertForbidden();
    }

    public function test_expired_subscription_is_blocked(): void
    {
        $this->tenant();
        TenantProduct::create([
            'tenant_id' => 'cpscs', 'product' => 'radar', 'status' => 'active',
            'expires_at' => now()->subDay(),
        ]);

        $this->getJson('http://cpscs.radar.test/_radar-probe')->assertForbidden();
    }

    public function test_suspended_subscription_is_blocked(): void
    {
        $this->tenant();
        TenantProduct::create(['tenant_id' => 'cpscs', 'product' => 'radar', 'status' => 'suspended']);

        $this->getJson('http://cpscs.radar.test/_radar-probe')->assertForbidden();
    }

    public function test_a_different_product_does_not_unlock_radar(): void
    {
        $this->tenant();
        TenantProduct::create(['tenant_id' => 'cpscs', 'product' => 'routine', 'status' => 'active']);

        $this->getJson('http://cpscs.radar.test/_radar-probe')->assertForbidden();
    }
}

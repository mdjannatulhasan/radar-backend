<?php

declare(strict_types=1);

namespace Tests\Feature\SmsCore;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use SmsCore\Models\Tenant;
use Tests\TestCase;

class TenancyIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected ?string $tenantSlug = null;

    protected $connectionsToTransact = ['pgsql', 'central'];

    public function test_initializing_a_tenant_switches_the_postgres_search_path(): void
    {
        $tenant = Tenant::create([
            'id' => 'iso', 'name' => 'Iso', 'slug' => 'iso',
            'provisioning_status' => 'ready',
        ]);

        $this->assertSame('public', $this->searchPath());

        tenancy()->initialize($tenant);
        $this->assertStringContainsString('tenant_iso', $this->searchPath());

        tenancy()->end();
        $this->assertSame('public', $this->searchPath());
    }

    public function test_two_tenants_cannot_see_each_others_rows(): void
    {
        $a = Tenant::create(['id' => 'aaa', 'name' => 'A', 'slug' => 'aaa', 'provisioning_status' => 'ready']);
        $b = Tenant::create(['id' => 'bbb', 'name' => 'B', 'slug' => 'bbb', 'provisioning_status' => 'ready']);

        foreach ([$a, $b] as $t) {
            tenancy()->initialize($t);
            DB::statement('CREATE TABLE probe (id int, owner text)');
            DB::table('probe')->insert(['id' => 1, 'owner' => $t->slug]);
            tenancy()->end();
        }

        tenancy()->initialize($a);
        $this->assertSame('aaa', DB::table('probe')->value('owner'));
        tenancy()->end();

        tenancy()->initialize($b);
        $this->assertSame('bbb', DB::table('probe')->value('owner'));
        tenancy()->end();
    }

    private function searchPath(): string
    {
        return DB::selectOne('show search_path')->search_path;
    }
}

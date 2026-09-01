<?php

declare(strict_types=1);

namespace SmsCore\Console;

use Illuminate\Console\Command;
use SmsCore\Models\Tenant;
use SmsCore\Models\TenantProduct;

class TenantCreateCommand extends Command
{
    protected $signature = 'sms:tenant:create
        {slug : Subdomain label and schema suffix, e.g. cpscs}
        {name : Display name, e.g. "Cantonment Public School & College, Saidpur"}
        {--products=radar : Comma-separated products to enable}
        {--status=active : Subscription status for those products}';

    protected $description = 'Provision a tenant: create its Postgres schema, run tenant migrations, enable products.';

    public function handle(): int
    {
        $slug = $this->argument('slug');

        if (Tenant::where('slug', $slug)->exists()) {
            $this->error("Tenant '{$slug}' already exists.");

            return self::FAILURE;
        }

        $this->info("Creating tenant '{$slug}' …");

        $tenant = Tenant::create([
            'id' => $slug,
            'slug' => $slug,
            'name' => $this->argument('name'),
            'provisioning_status' => 'migrating',
        ]);

        $this->info("Schema tenant_{$slug} created. Running tenant migrations …");

        $this->call('tenants:migrate', ['--tenants' => [$tenant->id]]);

        foreach (explode(',', $this->option('products')) as $product) {
            $product = trim($product);

            if ($product === '') {
                continue;
            }

            TenantProduct::create([
                'tenant_id' => $tenant->id,
                'product' => $product,
                'status' => $this->option('status'),
            ]);

            $this->line("  enabled product: {$product} ({$this->option('status')})");
        }

        $tenant->update([
            'provisioning_status' => 'ready',
            'migrated_at' => now(),
        ]);

        $this->info("Tenant '{$slug}' is ready at {$slug}.".config('tenancy.central_domains')[0]);

        return self::SUCCESS;
    }
}

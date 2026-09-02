<?php

declare(strict_types=1);

namespace SmsCore\Console;

use Illuminate\Console\Command;
use RuntimeException;
use SmsCore\Services\TenantProvisioner;

class TenantCreateCommand extends Command
{
    protected $signature = 'sms:tenant:create
        {slug : Subdomain label and schema suffix, e.g. cpscs}
        {name : Display name, e.g. "Cantonment Public School & College, Saidpur"}
        {--products=radar : Comma-separated products to enable}
        {--status=active : Subscription status for those products}';

    protected $description = 'Provision a tenant: create its Postgres schema, run tenant migrations, enable products.';

    /**
     * The steps themselves live in TenantProvisioner, because the platform
     * console provisions over HTTP and must not run a second copy of them.
     * This command is now the CLI skin on that service.
     */
    public function handle(TenantProvisioner $provisioner): int
    {
        $slug = $this->argument('slug');
        $products = array_filter(array_map('trim', explode(',', $this->option('products'))));

        $this->info("Creating tenant '{$slug}' …");

        try {
            $tenant = $provisioner->provision(
                $slug,
                $this->argument('name'),
                $products,
                $this->option('status'),
            );
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($tenant->products as $product) {
            $this->line("  enabled product: {$product->product} ({$product->status})");
        }

        $this->info("Tenant '{$slug}' is ready at {$slug}.".config('tenancy.central_domains')[0]);

        return self::SUCCESS;
    }
}

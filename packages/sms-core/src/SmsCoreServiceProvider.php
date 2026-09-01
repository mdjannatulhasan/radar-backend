<?php

declare(strict_types=1);

namespace SmsCore;

use Illuminate\Support\ServiceProvider;

class SmsCoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/sms-core.php', 'sms-core');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/sms-core.php' => config_path('sms-core.php'),
        ], 'sms-core-config');
    }

    /**
     * Migration paths, resolved by the host app. Central migrations run on the
     * `central` connection; tenant migrations run inside each tenant schema.
     */
    public static function centralMigrationPath(): string
    {
        return __DIR__.'/../database/migrations/central';
    }

    public static function tenantMigrationPath(): string
    {
        return __DIR__.'/../database/migrations/tenant';
    }
}

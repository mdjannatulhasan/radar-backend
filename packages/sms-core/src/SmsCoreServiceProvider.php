<?php

declare(strict_types=1);

namespace SmsCore;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Stancl\JobPipeline\JobPipeline;
use Stancl\Tenancy\Events\TenantCreated;
use Stancl\Tenancy\Jobs\CreateDatabase;

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

        $this->bootTenancyEvents();

        if ($this->app->runningInConsole()) {
            $this->commands([
                \SmsCore\Console\TenantCreateCommand::class,
            ]);
        }
    }

    /**
     * stancl/tenancy's own service provider registers the tenancy singletons
     * and bootstrappers, but it does NOT wire tenant lifecycle events to jobs
     * — that mapping normally lives in an app-published TenancyServiceProvider,
     * which this platform doesn't have. Without this, creating a Tenant row
     * never provisions its Postgres schema.
     */
    protected function bootTenancyEvents(): void
    {
        Event::listen(TenantCreated::class, JobPipeline::make([
            CreateDatabase::class,
        ])->send(function (TenantCreated $event) {
            return $event->tenant;
        })->shouldBeQueued(false)->toListener());
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

<?php

declare(strict_types=1);

namespace SmsCore;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use SmsCore\Tenancy\SchemaSearchPathBootstrapper;
use Stancl\JobPipeline\JobPipeline;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Events\TenantCreated;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Listeners\RevertToCentralContext;
use Stancl\Tenancy\Tenancy;

class SmsCoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/sms-core.php', 'sms-core');

        // Bootstrappers are stateful, so stancl registers each one as a
        // singleton; ours has to be one too (it remembers the search_path to
        // revert to).
        $this->app->singleton(SchemaSearchPathBootstrapper::class);
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

        // stancl ships these two in a stub TenancyServiceProvider that
        // `php artisan tenancy:install` publishes (assets/TenancyServiceProvider.stub.php,
        // lines 72 and 77). This app never ran that, so nothing ran the
        // bootstrappers: tenancy()->initialize() set tenant() and nothing else,
        // search_path never left `public`, and there was no isolation at all.
        Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
        Event::listen(TenancyEnded::class, RevertToCentralContext::class);

        // config/tenancy.php lists stancl's DatabaseTenancyBootstrapper, which
        // answers "point the app at this tenant" by opening a SECOND
        // connection. This platform is schema-per-tenant inside one database,
        // where the tenant boundary is search_path on the existing connection
        // — see SchemaSearchPathBootstrapper for why a second connection is
        // actively wrong here. Swap it at resolve time rather than forking the
        // config, so the bootstrapper list stays declared in one place.
        app(Tenancy::class)->getBootstrappersUsing = fn (): array => array_map(
            fn (string $bootstrapper): string => $bootstrapper === DatabaseTenancyBootstrapper::class
                ? SchemaSearchPathBootstrapper::class
                : $bootstrapper,
            $this->app['config']['tenancy.bootstrappers'] ?? [],
        );
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

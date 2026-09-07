<?php

declare(strict_types=1);

use SmsCore\Models\Tenant;
use SmsCore\Models\Domain;

return [
    'tenant_model' => Tenant::class,
    'id_generator' => Stancl\Tenancy\UUIDGenerator::class,
    'domain_model' => Domain::class,

    /*
     * Hosts that serve the central app (super-admin console, tenant signup).
     * Anything else is treated as `<slug>.<central_domain>`.
     */
    'central_domains' => [
        env('CENTRAL_DOMAIN', 'radar.test'),
        'admin.'.env('CENTRAL_DOMAIN', 'radar.test'),
        '127.0.0.1',
        'localhost',
    ],

    'bootstrappers' => [
        Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper::class,
        Stancl\Tenancy\Bootstrappers\CacheTenancyBootstrapper::class,
        Stancl\Tenancy\Bootstrappers\FilesystemTenancyBootstrapper::class,
        Stancl\Tenancy\Bootstrappers\QueueTenancyBootstrapper::class,
    ],

    'database' => [
        'central_connection' => 'central',

        /*
         * Schema-per-tenant: one physical database, one Postgres schema per
         * tenant, one connection pool. `pgsql` is cloned and its search_path
         * rewritten to `tenant_<id>` by PostgreSQLSchemaManager.
         */
        'template_tenant_connection' => 'pgsql',

        'prefix' => env('TENANT_SCHEMA_PREFIX', 'tenant_'),
        'suffix' => '',

        'managers' => [
            'pgsql' => Stancl\Tenancy\TenantDatabaseManagers\PostgreSQLSchemaManager::class,
        ],
    ],

    'cache' => [
        'tag_base' => 'tenant',
    ],

    'filesystem' => [
        'suffix_base' => 'tenant',
        'disks' => ['local', 'public'],
        'root_override' => [
            'local' => '%storage_path%/app/',
            'public' => '%storage_path%/app/public/',
        ],
        'suffix_storage_path' => true,
        'asset_helper_tenancy' => false,
    ],

    'redis' => [
        'prefix_base' => 'tenant',
        'prefixed_connections' => [],
    ],

    'features' => [],

    /*
     * Tenant schema migrations come from sms-core plus RADAR's own
     * product migrations. Order matters: sms-core first.
     */
    'migration_parameters' => [
        '--force' => true,
        '--path' => [
            'packages/sms-core/database/migrations/tenant',
            'database/migrations/tenant',
        ],
        '--realpath' => false,
    ],

    'seeder_parameters' => [
        '--class' => 'DatabaseSeeder',
    ],
];

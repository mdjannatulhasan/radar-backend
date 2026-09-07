<?php

declare(strict_types=1);

namespace SmsCore\Tenancy;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\DatabaseManager;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Contracts\TenantWithDatabase;

/**
 * Schema-per-tenant, single connection.
 *
 * stancl's own DatabaseTenancyBootstrapper answers "point the app at this
 * tenant's data" by building a SECOND connection (`database.connections.tenant`)
 * and making it the default. For database-per-tenant that is the only option.
 * For schema-per-tenant inside one physical database it is both unnecessary and
 * actively harmful:
 *
 *   - It opens a second PDO session. Anything the first session has written but
 *     not committed — including `CREATE SCHEMA tenant_x` itself — is invisible
 *     to it. Provisioning a tenant and then using it inside one transaction
 *     (which is exactly what a `RefreshDatabase` test does) cannot work.
 *   - Writes made through it sit outside whatever transaction the central
 *     connection holds, so nothing rolls them back.
 *
 * Switching `search_path` on the existing connection is the direct expression
 * of what schema-per-tenant means, and keeps one session, one transaction
 * scope. `central` is a separate connection pinned to `public`, so central
 * tables (tenants, domains, admins, tenant_products) are unaffected.
 */
class SchemaSearchPathBootstrapper implements TenancyBootstrapper
{
    protected ?string $originalSearchPath = null;

    public function __construct(
        protected DatabaseManager $database,
        protected Config $config,
    ) {
    }

    public function bootstrap(Tenant $tenant): void
    {
        /** @var TenantWithDatabase $tenant */
        $this->switchTo($tenant->database()->getName());
    }

    public function revert(): void
    {
        $this->switchTo($this->originalSearchPath ?? 'public');

        $this->originalSearchPath = null;
    }

    /**
     * The connection whose search_path is the tenant boundary. This is the
     * same connection stancl clones tenant connections from and the same one
     * PostgreSQLSchemaManager issues CREATE SCHEMA on, so provisioning and
     * usage always share one session.
     */
    protected function connectionName(): string
    {
        return $this->config->get('tenancy.database.template_tenant_connection')
            ?? $this->config->get('database.default');
    }

    protected function switchTo(string $searchPath): void
    {
        $name = $this->connectionName();

        if ($this->originalSearchPath === null) {
            $this->originalSearchPath = $this->config->get("database.connections.{$name}.search_path") ?: 'public';
        }

        // Keep the config in step with the live session: Laravel's Postgres
        // schema builder (hasTable(), getTables(), ...) reads search_path from
        // config, not from the server, and a later reconnect must land in the
        // same schema.
        $this->config->set("database.connections.{$name}.search_path", $searchPath);

        $quoted = collect(explode(',', $searchPath))
            ->map(fn (string $schema): string => '"'.trim($schema, " \t\"").'"')
            ->implode(', ');

        $this->database->connection($name)->statement("set search_path to {$quoted}");
    }
}

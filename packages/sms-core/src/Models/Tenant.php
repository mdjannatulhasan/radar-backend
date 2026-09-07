<?php

declare(strict_types=1);

namespace SmsCore\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

class Tenant extends BaseTenant implements TenantWithDatabase
{
    use HasDatabase, HasDomains;

    /**
     * Real columns on the tenants table. Everything else stancl virtualises
     * into the JSON `data` column.
     */
    public static function getCustomColumns(): array
    {
        return ['id', 'name', 'slug', 'provisioning_status', 'migrated_at'];
    }

    protected function casts(): array
    {
        return ['migrated_at' => 'datetime'];
    }

    public function products(): HasMany
    {
        return $this->hasMany(TenantProduct::class, 'tenant_id', 'id');
    }

    public function isProvisioned(): bool
    {
        return $this->provisioning_status === 'ready';
    }

    /**
     * True when this tenant has bought the product and the subscription has
     * not lapsed. Callers are the EnsureProductEnabled middleware and the
     * super-admin console.
     */
    public function hasProduct(string $product): bool
    {
        return $this->products()
            ->where('product', $product)
            ->whereIn('status', config('sms-core.active_statuses'))
            ->where(function ($q): void {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            // A trial also lapses on trial_ends_at. Without this a trial whose
            // trial_ends_at has passed but whose expires_at was never set stays
            // enabled forever.
            ->where(function ($q): void {
                $q->where('status', '!=', 'trial')
                    ->orWhereNull('trial_ends_at')
                    ->orWhere('trial_ends_at', '>', now());
            })
            ->exists();
    }
}

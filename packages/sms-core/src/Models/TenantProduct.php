<?php

declare(strict_types=1);

namespace SmsCore\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantProduct extends Model
{
    protected $connection = 'central';

    protected $table = 'tenant_products';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'id');
    }
}

<?php

namespace App\Models\Pps;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SchoolClass extends Model
{
    protected $table = 'pps_classes';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'numeric_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function classConfigs(): HasMany
    {
        return $this->hasMany(ClassConfig::class, 'class_id');
    }
}

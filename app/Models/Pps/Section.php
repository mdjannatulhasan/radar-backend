<?php

namespace App\Models\Pps;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Section extends Model
{
    protected $table = 'pps_sections';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function classConfigs(): HasMany
    {
        return $this->hasMany(ClassConfig::class, 'section_id');
    }
}

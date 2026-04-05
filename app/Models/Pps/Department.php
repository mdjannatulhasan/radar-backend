<?php

namespace App\Models\Pps;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    protected $table = 'pps_departments';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function classSections(): HasMany
    {
        return $this->hasMany(ClassSection::class, 'department_id');
    }

    public function subjects(): HasMany
    {
        return $this->hasMany(Subject::class, 'department_id');
    }
}

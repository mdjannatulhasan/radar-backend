<?php

declare(strict_types=1);

namespace SmsCore\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class School extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['weekend_days' => 'array'];
    }

    public function levels(): HasMany
    {
        return $this->hasMany(Level::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(Version::class);
    }

    public function classLevels(): HasMany
    {
        return $this->hasMany(ClassLevel::class);
    }

    public function teachers(): HasMany
    {
        return $this->hasMany(Teacher::class);
    }
}

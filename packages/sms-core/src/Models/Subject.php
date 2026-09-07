<?php

declare(strict_types=1);

namespace SmsCore\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use SmsCore\Concerns\BelongsToSchool;

class Subject extends Model
{
    use BelongsToSchool;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_optional' => 'boolean',
            'counts_as_class' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(Level::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(Version::class);
    }

    public function quotas(): HasMany
    {
        return $this->hasMany(SubjectQuota::class);
    }
}

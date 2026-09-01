<?php

declare(strict_types=1);

namespace SmsCore\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use SmsCore\Concerns\BelongsToSchool;

class ClassLevel extends Model
{
    use BelongsToSchool;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'day_periods' => 'array',
            'is_active' => 'boolean',
            'numeric_order' => 'integer',
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

    public function sections(): HasMany
    {
        return $this->hasMany(Section::class);
    }

    /** "Class 11 (Science) — English / College" */
    public function getFullLabelAttribute(): string
    {
        return sprintf(
            '%s — %s / %s',
            $this->name,
            $this->version?->name ?? '?',
            $this->level?->name ?? '?'
        );
    }

    public function scopeForLevel(Builder $q, int|string|null $levelId): Builder
    {
        return $levelId === null ? $q : $q->where('level_id', $levelId);
    }

    public function scopeForVersion(Builder $q, int|string|null $versionId): Builder
    {
        return $versionId === null ? $q : $q->where('version_id', $versionId);
    }

    public function scopeForGroup(Builder $q, ?string $group): Builder
    {
        return $group === null ? $q : $q->where('group', $group);
    }
}

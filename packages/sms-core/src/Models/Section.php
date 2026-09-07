<?php

declare(strict_types=1);

namespace SmsCore\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use SmsCore\Concerns\BelongsToSchool;

class Section extends Model
{
    use BelongsToSchool;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function classLevel(): BelongsTo
    {
        return $this->belongsTo(ClassLevel::class);
    }

    public function sectionName(): BelongsTo
    {
        return $this->belongsTo(SectionName::class);
    }

    public function classTeacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'class_teacher_id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(StudentEnrollment::class);
    }

    /** "Class 11 (Science) — Shapla" */
    public function getDisplayNameAttribute(): string
    {
        return sprintf(
            '%s — %s',
            $this->classLevel?->name ?? '?',
            $this->sectionName?->name ?? '?'
        );
    }
}

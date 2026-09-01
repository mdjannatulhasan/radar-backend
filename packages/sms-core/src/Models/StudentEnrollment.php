<?php

declare(strict_types=1);

namespace SmsCore\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use SmsCore\Concerns\BelongsToSchool;

class StudentEnrollment extends Model
{
    use BelongsToSchool;

    protected $guarded = [];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    /** Filter enrollments down the class_level chain. */
    public function scopeForTaxonomy(
        Builder $q,
        int|string|null $levelId = null,
        int|string|null $versionId = null,
        ?string $group = null,
        int|string|null $classLevelId = null,
    ): Builder {
        return $q->whereHas('section.classLevel', function (Builder $cl) use ($levelId, $versionId, $group, $classLevelId): void {
            if ($levelId !== null) {
                $cl->where('level_id', $levelId);
            }
            if ($versionId !== null) {
                $cl->where('version_id', $versionId);
            }
            if ($group !== null) {
                $cl->where('group', $group);
            }
            if ($classLevelId !== null) {
                $cl->where('id', $classLevelId);
            }
        });
    }
}

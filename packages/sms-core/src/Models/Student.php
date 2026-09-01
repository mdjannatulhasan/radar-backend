<?php

declare(strict_types=1);

namespace SmsCore\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use SmsCore\Concerns\BelongsToSchool;

class Student extends Model
{
    use BelongsToSchool;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'admission_date' => 'date',
            'private_tuition_subjects' => 'array',
            'special_needs' => 'array',
            'economically_vulnerable' => 'boolean',
            'current_gpa' => 'float',
        ];
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(StudentEnrollment::class);
    }

    /**
     * The enrollment for the current academic year. This replaces the old
     * denormalised students.class_name / students.section columns and is the
     * only route to a student's level, version, group, class and section.
     */
    public function currentEnrollment(): HasOne
    {
        return $this->hasOne(StudentEnrollment::class)
            ->whereHas('academicYear', fn ($q) => $q->where('is_current', true));
    }
}

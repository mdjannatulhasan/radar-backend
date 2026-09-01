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

    /**
     * class_name and section_name are derived (see the accessors below) but are
     * appended so that a serialised student still answers "which class?" — the
     * question every RADAR payload asks. Callers that serialise many students
     * should eager-load App\Support\StudentTaxonomyFilter::eagerLoad() so the
     * accessors read from memory rather than issuing a query per row.
     */
    protected $appends = ['class_name', 'section_name', 'section'];

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

    /**
     * Derived, read-only conveniences for the three taxonomy facts almost every
     * caller wants. They are NOT columns — the old denormalised
     * students.class_name / students.section strings are gone — so each one
     * walks currentEnrollment -> section -> class_level. Deliberately kept out
     * of $appends: a caller that serialises many students must eager-load that
     * chain (see App\Support\StudentTaxonomyFilter::eagerLoad) rather than pay
     * a query per row.
     */
    public function getSectionIdAttribute(): ?int
    {
        $id = $this->currentEnrollment?->section_id;

        return $id === null ? null : (int) $id;
    }

    public function getClassNameAttribute(): ?string
    {
        return $this->currentEnrollment?->section?->classLevel?->name;
    }

    public function getSectionNameAttribute(): ?string
    {
        return $this->currentEnrollment?->section?->sectionName?->name;
    }

    /**
     * Alias of section_name, kept because `student.section` is the field name
     * ~19 frontend call sites already read — dashboard, alert queue,
     * notifications, reports, parent report, student profile, tuition batches,
     * admin workspace and the teacher forms. Renaming all of them to
     * section_name would be churn for no gain: on a student, "section" meaning
     * the section's name is exactly right.
     */
    public function getSectionAttribute(): ?string
    {
        return $this->getSectionNameAttribute();
    }
}

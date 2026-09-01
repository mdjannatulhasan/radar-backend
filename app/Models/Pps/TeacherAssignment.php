<?php

declare(strict_types=1);

namespace App\Models\Pps;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use SmsCore\Concerns\BelongsToSchool;
use SmsCore\Models\Section;
use SmsCore\Models\Subject;
use SmsCore\Models\Teacher;

class TeacherAssignment extends Model
{
    use BelongsToSchool;

    protected $table = 'pps_teacher_assignments';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_class_teacher' => 'boolean'];
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'teacher_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class, 'section_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class, 'subject_id');
    }
}

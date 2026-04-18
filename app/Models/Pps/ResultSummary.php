<?php

namespace App\Models\Pps;

use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResultSummary extends Model
{
    protected $table = 'pps_result_summary';

    protected $fillable = [
        'exam_id', 'student_id',
        'total_marks_obtained', 'total_marks_full',
        'gpa', 'letter_grade',
        'discipline', 'handwriting', 'is_promoted',
        'total_presence', 'total_working_days',
        'class_position', 'total_students_in_class',
        'computed_at', 'computed_by',
    ];

    protected $casts = [
        'total_marks_obtained'    => 'float',
        'total_marks_full'        => 'float',
        'gpa'                     => 'float',
        'is_promoted'             => 'boolean',
        'total_presence'          => 'integer',
        'total_working_days'      => 'integer',
        'class_position'          => 'integer',
        'total_students_in_class' => 'integer',
        'computed_at'             => 'datetime',
    ];

    public function exam(): BelongsTo
    {
        return $this->belongsTo(ExamDefinition::class, 'exam_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function computedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'computed_by');
    }
}

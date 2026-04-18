<?php

namespace App\Models\Pps;

use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PretestMark extends Model
{
    protected $table = 'pps_pretest_marks';

    protected $fillable = [
        'exam_id', 'student_id', 'subject_id',
        'ct', 'attendance',
        'cq', 'cq_con',
        'mcq', 'mcq_con',
        'total_obtained', 'highest_marks',
        'letter_grade', 'grade_point', 'promotion_grade',
        'entered_by',
    ];

    protected $casts = [
        'ct'             => 'float',
        'attendance'     => 'float',
        'cq'             => 'float',
        'cq_con'         => 'float',
        'mcq'            => 'float',
        'mcq_con'        => 'float',
        'total_obtained' => 'float',
        'highest_marks'  => 'float',
        'grade_point'    => 'float',
    ];

    public function exam(): BelongsTo
    {
        return $this->belongsTo(ExamDefinition::class, 'exam_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class, 'subject_id');
    }

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }
}

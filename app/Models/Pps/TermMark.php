<?php

namespace App\Models\Pps;

use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TermMark extends Model
{
    protected $table = 'pps_term_marks';

    protected $fillable = [
        'exam_id', 'student_id', 'subject_id',
        'spot_test', 'spot_test_con',
        'class_test2', 'class_test2_con',
        'attendance',
        'term_marks', 'term_con',
        'vt', 'vt_con',
        'total_obtained', 'highest_marks',
        'letter_grade', 'grade_point',
        'entered_by',
    ];

    protected $casts = [
        'spot_test'       => 'float',
        'spot_test_con'   => 'float',
        'class_test2'     => 'float',
        'class_test2_con' => 'float',
        'attendance'      => 'float',
        'term_marks'      => 'float',
        'term_con'        => 'float',
        'vt'              => 'float',
        'vt_con'          => 'float',
        'total_obtained'  => 'float',
        'highest_marks'   => 'float',
        'grade_point'     => 'float',
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

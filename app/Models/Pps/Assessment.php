<?php

namespace App\Models\Pps;

use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Assessment extends Model
{
    protected $table = 'pps_assessments';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'marks_obtained' => 'float',
            'total_marks' => 'float',
            'percentage' => 'float',
            'exam_date' => 'date',
            'is_verified' => 'boolean',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }
}


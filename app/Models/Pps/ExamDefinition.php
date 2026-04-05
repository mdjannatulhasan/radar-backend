<?php

namespace App\Models\Pps;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamDefinition extends Model
{
    protected $table = 'pps_exam_definitions';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'total_marks' => 'float',
            'exam_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class, 'subject_id');
    }
}

<?php

namespace App\Models\Pps;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamScope extends Model
{
    protected $table = 'pps_exam_scopes';

    protected $guarded = [];

    public function exam(): BelongsTo
    {
        return $this->belongsTo(ExamDefinition::class, 'exam_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class, 'subject_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }
}

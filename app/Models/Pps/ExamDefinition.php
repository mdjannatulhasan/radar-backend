<?php

namespace App\Models\Pps;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExamDefinition extends Model
{
    protected $table = 'pps_exam_definitions';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'total_marks' => 'float',
            'exam_date'   => 'date',
            'is_active'   => 'boolean',
        ];
    }

    public function scopes(): HasMany
    {
        return $this->hasMany(ExamScope::class, 'exam_id');
    }
}

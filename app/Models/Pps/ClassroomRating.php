<?php

namespace App\Models\Pps;

use SmsCore\Models\Student;
use SmsCore\Models\Teacher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClassroomRating extends Model
{
    protected $table = 'pps_classroom_ratings';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'rating_period' => 'date',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'teacher_id');
    }
}


<?php

namespace App\Models\Pps;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeacherAssignment extends Model
{
    protected $table = 'pps_teacher_assignments';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_class_teacher' => 'boolean',
        ];
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }
}

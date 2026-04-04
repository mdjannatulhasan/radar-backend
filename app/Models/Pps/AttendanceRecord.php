<?php

namespace App\Models\Pps;

use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceRecord extends Model
{
    protected $table = 'pps_attendance';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'parent_notified' => 'boolean',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function markedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marked_by');
    }
}


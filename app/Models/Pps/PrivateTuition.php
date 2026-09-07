<?php

namespace App\Models\Pps;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use SmsCore\Models\Student;
use SmsCore\Models\Subject;
use SmsCore\Models\Teacher;
use SmsCore\Models\User;

class PrivateTuition extends Model
{
    protected $table = 'pps_private_tuitions';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'started_on' => 'date',
            'ended_on' => 'date',
            'hours_per_week' => 'integer',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}

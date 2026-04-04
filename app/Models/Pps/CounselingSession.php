<?php

namespace App\Models\Pps;

use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CounselingSession extends Model
{
    protected $table = 'pps_counseling_sessions';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'session_date' => 'date',
            'next_session_date' => 'date',
            'psychometric_scores' => 'array',
            'special_needs_profile' => 'array',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function counselor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counselor_id');
    }

    public function referredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_by');
    }

    public function alert(): BelongsTo
    {
        return $this->belongsTo(PpsAlert::class, 'alert_id');
    }
}

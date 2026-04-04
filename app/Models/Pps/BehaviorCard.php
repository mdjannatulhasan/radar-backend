<?php

namespace App\Models\Pps;

use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BehaviorCard extends Model
{
    protected $table = 'pps_behavior_cards';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_integrity_violation' => 'boolean',
            'issued_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }
}


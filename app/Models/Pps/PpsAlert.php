<?php

namespace App\Models\Pps;

use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PpsAlert extends Model
{
    protected $table = 'pps_alerts';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'trigger_reasons' => 'array',
            'notified_to' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}

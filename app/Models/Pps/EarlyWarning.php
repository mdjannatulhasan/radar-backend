<?php

namespace App\Models\Pps;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use SmsCore\Models\Student;
use SmsCore\Models\User;

class EarlyWarning extends Model
{
    public const CATEGORIES = [1 => 'imminent', 3 => 'near', 6 => 'emerging'];

    protected $table = 'pps_early_warnings';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'horizon_months' => 'integer',
            'current_risk' => 'float',
            'projected_risk' => 'float',
            'projected_overall' => 'float',
            'drivers' => 'array',
            'acknowledged_at' => 'datetime',
            'notified_at' => 'datetime',
        ];
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', ['open', 'acknowledged']);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }
}

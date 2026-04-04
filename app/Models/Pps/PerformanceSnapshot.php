<?php

namespace App\Models\Pps;

use App\Models\Student;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PerformanceSnapshot extends Model
{
    protected $table = 'pps_performance_snapshots';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'academic_score' => 'float',
            'attendance_score' => 'float',
            'behavior_score' => 'float',
            'participation_score' => 'float',
            'extracurricular_score' => 'float',
            'overall_score' => 'float',
            'risk_score' => 'float',
            'snapshot_data' => 'array',
            'calculated_at' => 'datetime',
        ];
    }

    public function scopeForPeriod(Builder $query, string $period): Builder
    {
        return $query->where('snapshot_period', $period);
    }

    public function scopeAtRisk(Builder $query): Builder
    {
        return $query->where('alert_level', '!=', 'none');
    }

    public function scopeUrgent(Builder $query): Builder
    {
        return $query->where('alert_level', 'urgent');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}

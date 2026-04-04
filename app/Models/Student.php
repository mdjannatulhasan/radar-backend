<?php

namespace App\Models;

use App\Models\Pps\Assessment;
use App\Models\Pps\AttendanceRecord;
use App\Models\Pps\BehaviorCard;
use App\Models\Pps\ClassroomRating;
use App\Models\Pps\CounselingSession;
use App\Models\Pps\Extracurricular;
use App\Models\Pps\PerformanceSnapshot;
use App\Models\Pps\PpsAlert;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Student extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'admission_date' => 'date',
            'current_gpa' => 'float',
            'private_tuition_subjects' => 'array',
            'special_needs' => 'array',
        ];
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(Assessment::class);
    }

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    public function behaviorCards(): HasMany
    {
        return $this->hasMany(BehaviorCard::class);
    }

    public function classroomRatings(): HasMany
    {
        return $this->hasMany(ClassroomRating::class);
    }

    public function extracurriculars(): HasMany
    {
        return $this->hasMany(Extracurricular::class);
    }

    public function performanceSnapshots(): HasMany
    {
        return $this->hasMany(PerformanceSnapshot::class);
    }

    public function counselingSessions(): HasMany
    {
        return $this->hasMany(CounselingSession::class);
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(PpsAlert::class);
    }
}

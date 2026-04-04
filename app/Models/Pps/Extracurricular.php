<?php

namespace App\Models\Pps;

use App\Models\Student;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Extracurricular extends Model
{
    protected $table = 'pps_extracurricular';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'achievement_level' => 'integer',
            'event_date' => 'date',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}


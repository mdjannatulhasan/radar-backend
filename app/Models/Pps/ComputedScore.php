<?php
namespace App\Models\Pps;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComputedScore extends Model
{
    protected $table = 'pps_computed_scores';
    protected $fillable = [
        'exam_id', 'student_id', 'subject_id',
        'total_obtained', 'total_possible', 'percentage',
        'letter_grade', 'grade_point', 'computed_at',
    ];
    protected $casts = [
        'total_obtained' => 'float',
        'total_possible' => 'float',
        'percentage'     => 'float',
        'grade_point'    => 'float',
    ];

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class, 'exam_id');
    }
}

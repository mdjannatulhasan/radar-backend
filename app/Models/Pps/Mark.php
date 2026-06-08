<?php
namespace App\Models\Pps;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Mark extends Model
{
    protected $table = 'pps_marks';
    protected $fillable = ['component_id', 'student_id', 'subject_id', 'marks_obtained', 'entered_by', 'recorded_at'];
    protected $casts = ['marks_obtained' => 'float'];

    public function component(): BelongsTo
    {
        return $this->belongsTo(ExamComponent::class, 'component_id');
    }
}

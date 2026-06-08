<?php
namespace App\Models\Pps;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamClassMap extends Model
{
    protected $table = 'pps_exam_class_map';
    protected $fillable = ['exam_id', 'class_id', 'section_id', 'subject_id'];

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class, 'exam_id');
    }
}

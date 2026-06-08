<?php
namespace App\Models\Pps;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExamComponent extends Model
{
    protected $table = 'pps_exam_components';
    protected $fillable = ['exam_id', 'name', 'code', 'max_raw_marks', 'max_contribution', 'sort_order'];
    protected $casts = [
        'max_raw_marks'    => 'float',
        'max_contribution' => 'float',
        'sort_order'       => 'integer',
    ];

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class, 'exam_id');
    }

    public function marks(): HasMany
    {
        return $this->hasMany(Mark::class, 'component_id');
    }
}

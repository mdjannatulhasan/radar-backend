<?php
namespace App\Models\Pps;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Exam extends Model
{
    protected $table = 'pps_exams';
    protected $fillable = [
        'exam_type_id', 'title', 'academic_year', 'term',
        'exam_date', 'scope', 'status', 'created_by', 'is_active',
    ];
    protected $casts = ['is_active' => 'boolean', 'academic_year' => 'integer', 'term' => 'integer'];

    public function examType(): BelongsTo
    {
        return $this->belongsTo(ExamType::class, 'exam_type_id');
    }

    public function components(): HasMany
    {
        return $this->hasMany(ExamComponent::class, 'exam_id')->orderBy('sort_order');
    }

    public function classMaps(): HasMany
    {
        return $this->hasMany(ExamClassMap::class, 'exam_id');
    }

    public function computedScores(): HasMany
    {
        return $this->hasMany(ComputedScore::class, 'exam_id');
    }
}

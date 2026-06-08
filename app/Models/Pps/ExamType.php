<?php
namespace App\Models\Pps;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExamType extends Model
{
    protected $table = 'pps_exam_types';
    protected $fillable = ['name', 'code', 'is_terminal', 'is_system', 'created_by', 'is_active'];
    protected $casts = ['is_terminal' => 'boolean', 'is_system' => 'boolean', 'is_active' => 'boolean'];

    public function exams(): HasMany
    {
        return $this->hasMany(Exam::class, 'exam_type_id');
    }
}

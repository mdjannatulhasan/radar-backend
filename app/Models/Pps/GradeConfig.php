<?php

namespace App\Models\Pps;

use Illuminate\Database\Eloquent\Model;

class GradeConfig extends Model
{
    protected $table = 'pps_grade_config';

    protected $fillable = [
        'school_id', 'min_pct', 'max_pct', 'letter_grade', 'grade_point', 'sort_order',
    ];

    protected $casts = [
        'min_pct'     => 'float',
        'max_pct'     => 'float',
        'grade_point' => 'float',
        'sort_order'  => 'integer',
    ];
}

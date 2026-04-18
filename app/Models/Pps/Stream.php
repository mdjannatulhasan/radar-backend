<?php

namespace App\Models\Pps;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Stream extends Model
{
    protected $table = 'pps_streams';

    protected $fillable = ['name', 'code', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function subjects(): HasMany
    {
        return $this->hasMany(Subject::class, 'stream_id');
    }
}

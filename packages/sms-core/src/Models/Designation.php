<?php

declare(strict_types=1);

namespace SmsCore\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use SmsCore\Concerns\BelongsToSchool;

class Designation extends Model
{
    use BelongsToSchool;

    protected $guarded = [];

    public function teachers(): HasMany
    {
        return $this->hasMany(Teacher::class);
    }
}

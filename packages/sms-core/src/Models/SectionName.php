<?php

declare(strict_types=1);

namespace SmsCore\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use SmsCore\Concerns\BelongsToSchool;

class SectionName extends Model
{
    use BelongsToSchool;

    protected $guarded = [];

    public function sections(): HasMany
    {
        return $this->hasMany(Section::class);
    }
}

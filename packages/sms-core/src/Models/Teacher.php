<?php

declare(strict_types=1);

namespace SmsCore\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use SmsCore\Concerns\BelongsToSchool;

class Teacher extends Model
{
    use BelongsToSchool;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'max_weekly_periods' => 'integer',
        ];
    }

    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class);
    }

    /** Nullable: a teacher on staff need not have a login. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

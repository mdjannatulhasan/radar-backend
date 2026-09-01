<?php

declare(strict_types=1);

namespace SmsCore\Concerns;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use SmsCore\Models\School;
use SmsCore\Scopes\SchoolScope;

trait BelongsToSchool
{
    public static function bootBelongsToSchool(): void
    {
        static::addGlobalScope(new SchoolScope());

        static::creating(function ($model): void {
            if ($model->school_id === null && app()->bound('sms.school_id')) {
                $model->school_id = app('sms.school_id');
            }
        });
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }
}

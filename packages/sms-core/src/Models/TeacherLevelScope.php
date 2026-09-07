<?php

declare(strict_types=1);

namespace SmsCore\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use SmsCore\Concerns\BelongsToSchool;

/** One (teacher, version, level) responsibility pair. Table: teacher_scopes. */
class TeacherLevelScope extends Model
{
    use BelongsToSchool;

    protected $table = 'teacher_scopes';

    protected $guarded = [];

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(Version::class);
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(Level::class);
    }
}

<?php

namespace App\Models\Pps;

use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PpsNotificationLog extends Model
{
    protected $table = 'pps_notification_logs';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'generated_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}

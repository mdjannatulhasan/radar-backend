<?php

namespace App\Models\Pps;

use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class PpsNotice extends Model
{
    protected $table = 'pps_notices';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'audience'              => 'array',
            'is_expiry_enabled'     => 'boolean',
            'is_pinned'             => 'boolean',
            'expires_at'            => 'datetime',
        ];
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function targetStudent(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'target_student_id');
    }

    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    /** Scope: not expired (or expiry disabled) */
    public function scopeActive(Builder $query): void
    {
        $query->where(function (Builder $q): void {
            $q->where('is_expiry_enabled', false)
              ->orWhereNull('expires_at')
              ->orWhere('expires_at', '>', Carbon::now());
        });
    }

    /** Scope: visible to a given user based on role + targeting */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $role = strtolower($user->role ?? '');

        // Admin / superadmin / principal see everything
        if (in_array($role, ['admin', 'superadmin', 'principal'], true)) {
            return;
        }

        // Map role → audience slug stored in the JSON array
        $roleSlugMap = [
            'teacher'         => 'teachers',
            'counselor'       => 'counselors',
            'welfare_officer' => 'welfare_officers',
            'guardian'        => 'guardians',
        ];
        $slug = $roleSlugMap[$role] ?? $role;

        $query->where(function (Builder $q) use ($slug, $user, $role): void {
            // Catch-all audiences
            $q->whereJsonContains('audience', 'public')
              ->orWhereJsonContains('audience', 'all')
              ->orWhereJsonContains('audience', 'staff') // non-guardian staff
              ->orWhereJsonContains('audience', $slug);

            // Specific user target
            $q->orWhere('target_user_id', $user->id);

            // Guardians also see 'students' notices + their children's specific notices
            if ($role === 'guardian') {
                $childIds = Student::query()
                    ->where('guardian_email', $user->email)
                    ->pluck('id');

                $q->orWhereJsonContains('audience', 'students');
                if ($childIds->isNotEmpty()) {
                    $q->orWhereIn('target_student_id', $childIds);
                }
            }
        });

        // Staff should NOT see notices limited to 'guardians' only unless 'public'/'all'/'staff'
        if ($role !== 'guardian') {
            $query->where(function (Builder $q): void {
                $q->whereJsonDoesntContain('audience', 'guardians')
                  ->orWhereJsonContains('audience', 'public')
                  ->orWhereJsonContains('audience', 'all')
                  ->orWhereJsonContains('audience', 'staff');
            });
        }
    }
}

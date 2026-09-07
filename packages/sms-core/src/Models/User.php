<?php

declare(strict_types=1);

namespace SmsCore\Models;

use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use SmsCore\Concerns\BelongsToSchool;

/**
 * A login. Distinct from Teacher, which is a person on staff: most staff have
 * no account, and some accounts (guardians, counselors) are not staff.
 */
class User extends Authenticatable
{
    use BelongsToSchool, HasApiTokens, Notifiable;

    public const ROLE_ADMIN = 'admin';

    public const ROLE_SUPERADMIN = 'superadmin';

    public const ROLE_PRINCIPAL = 'principal';

    public const ROLE_TEACHER = 'teacher';

    public const ROLE_GUARDIAN = 'guardian';

    public const ROLE_COUNSELOR = 'counselor';

    /**
     * Resolves a user's permission strings. Which actions a role may perform is
     * a PRODUCT question — sms-core has no opinion on RADAR's permission
     * vocabulary — so the product registers a resolver from its service
     * provider. Unset, a user simply has no permissions.
     *
     * @var (callable(self): array<int, string>)|null
     */
    public static $permissionResolver = null;

    protected $guarded = [];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /** Nullable: only staff accounts have a teacher record. */
    public function teacher(): HasOne
    {
        return $this->hasOne(Teacher::class);
    }

    protected function setRoleAttribute(?string $value): void
    {
        $this->attributes['role'] = $value !== null ? strtolower(trim($value)) : null;
    }

    protected function setEmailAttribute(?string $value): void
    {
        $this->attributes['email'] = $value !== null ? strtolower(trim($value)) : null;
    }

    /** @param array<int, string>|string $roles */
    public function hasAnyRole(array|string $roles): bool
    {
        $roles = is_array($roles) ? $roles : [$roles];
        $roles = array_map(fn (string $role): string => strtolower(trim($role)), $roles);

        return in_array($this->role, $roles, true);
    }

    /** @return array<int, string> */
    public function permissions(): array
    {
        $resolver = static::$permissionResolver;

        return $resolver === null ? [] : (array) $resolver($this);
    }

    public function hasPermission(string $permission): bool
    {
        return in_array(trim($permission), $this->permissions(), true);
    }

    public function isGuardianOf(int $studentId): bool
    {
        if ($this->email === null) {
            return false;
        }

        return Student::query()
            ->whereKey($studentId)
            ->where('guardian_email', $this->email)
            ->exists();
    }
}

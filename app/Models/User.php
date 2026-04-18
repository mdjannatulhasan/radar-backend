<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Pps\TeacherAssignment;
use App\Support\PpsPermissions;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'role', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public const ROLE_ADMIN = 'admin';

    public const ROLE_SUPERADMIN = 'superadmin';

    public const ROLE_PRINCIPAL = 'principal';

    public const ROLE_TEACHER = 'teacher';

    public const ROLE_GUARDIAN = 'guardian';

    public const ROLE_COUNSELOR = 'counselor';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'is_active'         => 'boolean',
        ];
    }

    protected function setRoleAttribute(?string $value): void
    {
        $this->attributes['role'] = $value !== null ? strtolower(trim($value)) : null;
    }

    protected function setEmailAttribute(?string $value): void
    {
        $this->attributes['email'] = $value !== null ? strtolower(trim($value)) : null;
    }

    public function hasAnyRole(array|string $roles): bool
    {
        $roles = is_array($roles) ? $roles : [$roles];
        $roles = array_map(fn (string $role) => strtolower(trim($role)), $roles);

        return in_array($this->role, $roles, true);
    }

    public function permissions(): array
    {
        return PpsPermissions::forRole($this->role);
    }

    public function teacherAssignments(): HasMany
    {
        return $this->hasMany(TeacherAssignment::class, 'teacher_id');
    }

    public function hasPermission(string $permission): bool
    {
        return in_array(trim($permission), $this->permissions(), true);
    }

    public function isGuardianOf(int $studentId): bool
    {
        return \App\Models\Student::query()
            ->whereKey($studentId)
            ->where('guardian_email', $this->email)
            ->exists();
    }

    public function canAccessStudent(Student|int $student): bool
    {
        if (! $this->hasAnyRole('teacher')) {
            return true;
        }

        $assignments = $this->teacherAssignments()
            ->get(['class_name', 'section'])
            ->unique(fn (TeacherAssignment $assignment) => "{$assignment->class_name}:{$assignment->section}");

        if ($assignments->isEmpty()) {
            return false;
        }

        $query = Student::query();

        if ($student instanceof Student) {
            $query->whereKey($student->id);
        } else {
            $query->whereKey($student);
        }

        return $query
            ->where(function ($studentQuery) use ($assignments): void {
                $assignments->each(function (TeacherAssignment $assignment) use ($studentQuery): void {
                    $studentQuery->orWhere(function ($classQuery) use ($assignment): void {
                        $classQuery
                            ->where('class_name', $assignment->class_name)
                            ->where('section', $assignment->section);
                    });
                });
            })
            ->exists();
    }

    public function isAssignedToClass(string $className, string $section): bool
    {
        if (! $this->hasAnyRole('teacher')) {
            return true;
        }

        return $this->teacherAssignments()
            ->where('class_name', $className)
            ->where('section', $section)
            ->exists();
    }

    public function isClassTeacherForClass(string $className, string $section): bool
    {
        return $this->teacherAssignments()
            ->where('class_name', $className)
            ->where('section', $section)
            ->where('is_class_teacher', true)
            ->exists();
    }

    public function assignedSubjectsForClass(string $className, string $section): array
    {
        return $this->teacherAssignments()
            ->where('class_name', $className)
            ->where('section', $section)
            ->whereNotNull('subject')
            ->pluck('subject')
            ->unique()
            ->values()
            ->all();
    }
}

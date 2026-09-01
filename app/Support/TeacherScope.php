<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Pps\TeacherAssignment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use SmsCore\Models\Student;
use SmsCore\Models\User;

/**
 * What a teacher is allowed to see, derived from pps_teacher_assignments.
 *
 * This used to live on SmsCore\Models\User as canAccessStudent() / isAssignedToClass()
 * and friends. It cannot follow the user model into sms-core: assignments are a
 * RADAR product table, and sms-core knows nothing about them. It is also no
 * longer expressible as (class_name, section) strings — an assignment now names
 * a concrete section, which carries level, version and group with it.
 *
 * Every method is teacher semantics: callers gate on hasAnyRole('teacher')
 * before asking, exactly as they did before.
 */
final class TeacherScope
{
    /** A user's staff record, if they have one. Most logins do not. */
    public static function teacherId(?User $user): ?int
    {
        return $user?->teacher?->id;
    }

    /** @return Collection<int, TeacherAssignment> */
    public static function assignments(?User $user): Collection
    {
        $teacherId = self::teacherId($user);

        if ($teacherId === null) {
            return collect();
        }

        return TeacherAssignment::query()->where('teacher_id', $teacherId)->get();
    }

    /** @return array<int, int> */
    public static function sectionIds(?User $user): array
    {
        return self::assignments($user)
            ->pluck('section_id')
            ->filter()
            ->unique()
            ->values()
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Narrow a Student query to the sections this teacher is assigned to.
     * A teacher with no assignments sees nobody — fail closed.
     *
     * @param  Builder<Student>  $students
     */
    public static function applyStudentScope(Builder $students, ?User $user): void
    {
        $sectionIds = self::sectionIds($user);

        if ($sectionIds === []) {
            $students->whereRaw('1 = 0');

            return;
        }

        $students->whereHas(
            'currentEnrollment',
            fn (Builder $enrollment) => $enrollment->whereIn('section_id', $sectionIds)
        );
    }

    public static function canAccessStudent(?User $user, Student|int $student): bool
    {
        $sectionIds = self::sectionIds($user);

        if ($sectionIds === []) {
            return false;
        }

        return Student::query()
            ->whereKey($student instanceof Student ? $student->getKey() : $student)
            ->whereHas(
                'currentEnrollment',
                fn (Builder $enrollment) => $enrollment->whereIn('section_id', $sectionIds)
            )
            ->exists();
    }

    public static function isAssignedToSection(?User $user, ?int $sectionId): bool
    {
        return $sectionId !== null && in_array($sectionId, self::sectionIds($user), true);
    }

    public static function isClassTeacherForSection(?User $user, ?int $sectionId): bool
    {
        if ($sectionId === null) {
            return false;
        }

        return self::assignments($user)
            ->contains(fn (TeacherAssignment $a): bool => (int) $a->section_id === $sectionId && $a->is_class_teacher);
    }

    /** @return array<int, int> */
    public static function assignedSubjectIdsForSection(?User $user, ?int $sectionId): array
    {
        if ($sectionId === null) {
            return [];
        }

        return self::assignments($user)
            ->filter(fn (TeacherAssignment $a): bool => (int) $a->section_id === $sectionId)
            ->pluck('subject_id')
            ->filter()
            ->unique()
            ->values()
            ->map(fn ($id): int => (int) $id)
            ->all();
    }
}

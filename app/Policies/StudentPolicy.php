<?php

namespace App\Policies;

use SmsCore\Models\Student;
use SmsCore\Models\User;
use App\Support\PpsPermissions;
use App\Support\TeacherScope;

class StudentPolicy
{
    public function viewContext(User $user, Student $student): bool
    {
        if (! $user->hasPermission(PpsPermissions::STUDENT_CONTEXT_VIEW)) {
            return false;
        }

        if ($user->hasAnyRole(['principal', 'admin', 'counselor'])) {
            return true;
        }

        if ($user->hasAnyRole('teacher')) {
            // Teacher reach is no longer a method on the user: it is derived from
            // pps_teacher_assignments -> sections. No assignments means no students.
            return TeacherScope::canAccessStudent($user, $student);
        }

        return $user->hasAnyRole('guardian') && $user->isGuardianOf($student->id);
    }

    public function updateContext(User $user, Student $student): bool
    {
        if (! $user->hasPermission(PpsPermissions::STUDENT_CONTEXT_UPDATE)) {
            return false;
        }

        if ($user->hasAnyRole(['principal', 'admin', 'counselor'])) {
            return true;
        }

        return $user->hasAnyRole('guardian') && $user->isGuardianOf($student->id);
    }

    public function viewCounseling(User $user, Student $student): bool
    {
        if (! $user->hasPermission(PpsPermissions::STUDENT_COUNSELING_VIEW)) {
            return false;
        }

        return $user->hasAnyRole(['principal', 'admin', 'counselor']);
    }

    public function viewParentReport(User $user, Student $student): bool
    {
        if (! $user->hasPermission(PpsPermissions::PARENT_REPORT_VIEW)) {
            return false;
        }

        if ($user->hasAnyRole(['principal', 'admin'])) {
            return true;
        }

        return $user->hasAnyRole('guardian') && $user->isGuardianOf($student->id);
    }
}

<?php

namespace App\Policies;

use App\Models\Student;
use App\Models\User;
use App\Support\PpsPermissions;

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
            return $user->canAccessStudent($student);
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

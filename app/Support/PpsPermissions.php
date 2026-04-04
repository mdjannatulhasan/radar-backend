<?php

namespace App\Support;

final class PpsPermissions
{
    public const DASHBOARD_VIEW = 'pps.dashboard.view';
    public const ALERTS_VIEW = 'pps.alerts.view';
    public const ALERTS_RESOLVE = 'pps.alerts.resolve';
    public const STUDENTS_VIEW = 'pps.students.view';
    public const STUDENT_CONTEXT_VIEW = 'pps.students.context.view';
    public const STUDENT_CONTEXT_UPDATE = 'pps.students.context.update';
    public const STUDENT_WHAT_IF_RUN = 'pps.students.what_if.run';
    public const STUDENT_COUNSELING_VIEW = 'pps.students.counseling.view';
    public const CLASS_ANALYTICS_VIEW = 'pps.classes.analytics.view';
    public const TEACHER_EFFECTIVENESS_VIEW = 'pps.teachers.effectiveness.view';
    public const ASSESSMENTS_MANAGE = 'pps.assessments.manage';
    public const ATTENDANCE_MANAGE = 'pps.attendance.manage';
    public const BEHAVIOR_MANAGE = 'pps.behavior.manage';
    public const CLASSROOM_RATINGS_MANAGE = 'pps.classroom_ratings.manage';
    public const EXTRACURRICULAR_MANAGE = 'pps.extracurricular.manage';
    public const SETTINGS_VIEW = 'pps.settings.view';
    public const SETTINGS_UPDATE = 'pps.settings.update';
    public const REPORTS_VIEW = 'pps.reports.view';
    public const NOTIFICATIONS_VIEW = 'pps.notifications.view';
    public const NOTIFICATIONS_RUN = 'pps.notifications.run';
    public const PARENT_PORTAL_VIEW = 'pps.parents.portal.view';
    public const PARENT_REPORT_VIEW = 'pps.parents.report.view';
    public const PARENT_REPORT_PRINT = 'pps.parents.report.print';
    public const COUNSELING_MANAGE = 'pps.counseling.manage';
    public const PSYCHOMETRIC_MANAGE = 'pps.psychometric.manage';

    public static function all(): array
    {
        return [
            self::DASHBOARD_VIEW,
            self::ALERTS_VIEW,
            self::ALERTS_RESOLVE,
            self::STUDENTS_VIEW,
            self::STUDENT_CONTEXT_VIEW,
            self::STUDENT_CONTEXT_UPDATE,
            self::STUDENT_WHAT_IF_RUN,
            self::STUDENT_COUNSELING_VIEW,
            self::CLASS_ANALYTICS_VIEW,
            self::TEACHER_EFFECTIVENESS_VIEW,
            self::ASSESSMENTS_MANAGE,
            self::ATTENDANCE_MANAGE,
            self::BEHAVIOR_MANAGE,
            self::CLASSROOM_RATINGS_MANAGE,
            self::EXTRACURRICULAR_MANAGE,
            self::SETTINGS_VIEW,
            self::SETTINGS_UPDATE,
            self::REPORTS_VIEW,
            self::NOTIFICATIONS_VIEW,
            self::NOTIFICATIONS_RUN,
            self::PARENT_PORTAL_VIEW,
            self::PARENT_REPORT_VIEW,
            self::PARENT_REPORT_PRINT,
            self::COUNSELING_MANAGE,
            self::PSYCHOMETRIC_MANAGE,
        ];
    }

    public static function forRole(?string $role): array
    {
        return match (strtolower(trim((string) $role))) {
            'admin' => self::all(),
            'principal' => [
                self::DASHBOARD_VIEW,
                self::ALERTS_VIEW,
                self::ALERTS_RESOLVE,
                self::STUDENTS_VIEW,
                self::STUDENT_CONTEXT_VIEW,
                self::STUDENT_CONTEXT_UPDATE,
                self::STUDENT_WHAT_IF_RUN,
                self::STUDENT_COUNSELING_VIEW,
                self::CLASS_ANALYTICS_VIEW,
                self::TEACHER_EFFECTIVENESS_VIEW,
                self::SETTINGS_VIEW,
                self::SETTINGS_UPDATE,
                self::REPORTS_VIEW,
                self::NOTIFICATIONS_VIEW,
                self::NOTIFICATIONS_RUN,
                self::COUNSELING_MANAGE,
            ],
            'teacher' => [
                self::ALERTS_VIEW,
                self::ALERTS_RESOLVE,
                self::STUDENTS_VIEW,
                self::STUDENT_CONTEXT_VIEW,
                self::STUDENT_WHAT_IF_RUN,
                self::CLASS_ANALYTICS_VIEW,
                self::ASSESSMENTS_MANAGE,
                self::ATTENDANCE_MANAGE,
                self::BEHAVIOR_MANAGE,
                self::CLASSROOM_RATINGS_MANAGE,
                self::EXTRACURRICULAR_MANAGE,
                self::NOTIFICATIONS_VIEW,
            ],
            'guardian' => [
                self::STUDENT_CONTEXT_VIEW,
                self::STUDENT_CONTEXT_UPDATE,
                self::NOTIFICATIONS_VIEW,
                self::PARENT_PORTAL_VIEW,
                self::PARENT_REPORT_VIEW,
                self::PARENT_REPORT_PRINT,
            ],
            'counselor' => [
                self::STUDENTS_VIEW,
                self::STUDENT_CONTEXT_VIEW,
                self::STUDENT_CONTEXT_UPDATE,
                self::STUDENT_COUNSELING_VIEW,
                self::NOTIFICATIONS_VIEW,
                self::COUNSELING_MANAGE,
                self::PSYCHOMETRIC_MANAGE,
            ],
            default => [],
        };
    }

    public static function homePathForRole(?string $role): string
    {
        return match (strtolower(trim((string) $role))) {
            'teacher' => '/pps/teacher',
            'guardian' => '/pps/parents',
            'counselor' => '/pps/students',
            default => '/pps',
        };
    }

    public static function roleLabel(?string $role): string
    {
        return match (strtolower(trim((string) $role))) {
            'admin' => 'System Admin',
            'principal' => 'Principal',
            'teacher' => 'Teacher',
            'guardian' => 'Guardian',
            'counselor' => 'Counselor',
            default => 'User',
        };
    }
}

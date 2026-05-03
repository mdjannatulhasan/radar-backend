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
    public const ASSESSMENTS_VIEW   = 'pps.assessments.view';   // read-only: results, report cards, marks grid
    public const ASSESSMENTS_MANAGE = 'pps.assessments.manage'; // read+write: enter/save marks, compute GPA
    public const ATTENDANCE_MANAGE = 'pps.attendance.manage';
    public const BEHAVIOR_MANAGE = 'pps.behavior.manage';
    public const CLASSROOM_RATINGS_MANAGE = 'pps.classroom_ratings.manage';
    public const EXTRACURRICULAR_MANAGE = 'pps.extracurricular.manage';
    public const SETTINGS_VIEW = 'pps.settings.view';
    public const SETTINGS_UPDATE = 'pps.settings.update';
    public const ADMIN_PANEL_VIEW = 'pps.admin.panel.view';
    public const MASTER_DATA_MANAGE = 'pps.master_data.manage';
    public const STUDENTS_MANAGE = 'pps.students.manage';
    public const TEACHER_ASSIGNMENTS_MANAGE = 'pps.teacher_assignments.manage';
    public const BULK_IMPORT_MANAGE = 'pps.bulk_import.manage';
    public const REPORTS_VIEW = 'pps.reports.view';
    public const NOTIFICATIONS_VIEW = 'pps.notifications.view';
    public const NOTIFICATIONS_RUN = 'pps.notifications.run';
    public const PARENT_PORTAL_VIEW = 'pps.parents.portal.view';
    public const PARENT_REPORT_VIEW = 'pps.parents.report.view';
    public const PARENT_REPORT_PRINT = 'pps.parents.report.print';
    public const COUNSELING_MANAGE = 'pps.counseling.manage';
    public const PSYCHOMETRIC_MANAGE = 'pps.psychometric.manage';
    public const WELFARE_VIEW = 'pps.welfare.view';
    public const WELFARE_MANAGE = 'pps.welfare.manage';
    public const NOTICES_VIEW = 'pps.notices.view';
    public const NOTICES_MANAGE = 'pps.notices.manage';
    public const USER_MANAGE = 'pps.users.manage';

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
            self::ASSESSMENTS_VIEW,
            self::ASSESSMENTS_MANAGE,
            self::ATTENDANCE_MANAGE,
            self::BEHAVIOR_MANAGE,
            self::CLASSROOM_RATINGS_MANAGE,
            self::EXTRACURRICULAR_MANAGE,
            self::SETTINGS_VIEW,
            self::SETTINGS_UPDATE,
            self::ADMIN_PANEL_VIEW,
            self::MASTER_DATA_MANAGE,
            self::STUDENTS_MANAGE,
            self::TEACHER_ASSIGNMENTS_MANAGE,
            self::BULK_IMPORT_MANAGE,
            self::REPORTS_VIEW,
            self::NOTIFICATIONS_VIEW,
            self::NOTIFICATIONS_RUN,
            self::PARENT_PORTAL_VIEW,
            self::PARENT_REPORT_VIEW,
            self::PARENT_REPORT_PRINT,
            self::COUNSELING_MANAGE,
            self::PSYCHOMETRIC_MANAGE,
            self::WELFARE_VIEW,
            self::WELFARE_MANAGE,
            self::NOTICES_VIEW,
            self::NOTICES_MANAGE,
            self::USER_MANAGE,
        ];
    }

    public static function forRole(?string $role): array
    {
        return match (strtolower(trim((string) $role))) {
            'superadmin' => self::all(),
            'admin'      => array_filter(self::all(), fn ($p) => $p !== self::USER_MANAGE),
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
                self::ASSESSMENTS_VIEW,   // read-only: view marks, results, report cards
                self::SETTINGS_VIEW,
                self::SETTINGS_UPDATE,
                self::REPORTS_VIEW,
                self::NOTIFICATIONS_VIEW,
                self::NOTIFICATIONS_RUN,
                self::COUNSELING_MANAGE,
                self::PSYCHOMETRIC_MANAGE,
                self::PARENT_PORTAL_VIEW,
                self::PARENT_REPORT_VIEW,
                self::NOTICES_VIEW,
                self::NOTICES_MANAGE,
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
                self::NOTICES_VIEW,
            ],
            'guardian' => [
                self::STUDENT_CONTEXT_VIEW,
                self::STUDENT_CONTEXT_UPDATE,
                self::NOTIFICATIONS_VIEW,
                self::PARENT_PORTAL_VIEW,
                self::PARENT_REPORT_VIEW,
                self::PARENT_REPORT_PRINT,
                self::NOTICES_VIEW,
            ],
            'counselor' => [
                self::STUDENTS_VIEW,
                self::STUDENT_CONTEXT_VIEW,
                self::STUDENT_CONTEXT_UPDATE,
                self::STUDENT_COUNSELING_VIEW,
                self::NOTIFICATIONS_VIEW,
                self::COUNSELING_MANAGE,
                self::PSYCHOMETRIC_MANAGE,
                self::NOTICES_VIEW,
            ],
            'welfare_officer' => [
                self::STUDENTS_VIEW,
                self::STUDENT_CONTEXT_VIEW,
                self::NOTIFICATIONS_VIEW,
                self::WELFARE_VIEW,
                self::WELFARE_MANAGE,
                self::REPORTS_VIEW,
                self::NOTICES_VIEW,
            ],
            default => [],
        };
    }

    public static function homePathForRole(?string $role): string
    {
        return match (strtolower(trim((string) $role))) {
            'superadmin', 'admin' => '/admin',
            'teacher' => '/teacher',
            'guardian' => '/parents',
            'counselor' => '/students',
            'welfare_officer' => '/welfare',
            default => '/',
        };
    }

    public static function roleLabel(?string $role): string
    {
        return match (strtolower(trim((string) $role))) {
            'superadmin' => 'Super Admin',
            'admin' => 'System Admin',
            'principal' => 'Principal',
            'teacher' => 'Teacher',
            'guardian' => 'Guardian',
            'counselor' => 'Counselor',
            'welfare_officer' => 'Welfare Officer',
            default => 'User',
        };
    }
}

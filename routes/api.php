<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Pps\AlertController;
use App\Http\Controllers\Api\V1\Pps\AdministrationController;
use App\Http\Controllers\Api\V1\Pps\AssessmentController;
use App\Http\Controllers\Api\V1\Pps\TermMarksController;
use App\Http\Controllers\Api\V1\Pps\PretestMarksController;
use App\Http\Controllers\Api\V1\Pps\ResultSummaryController;
use App\Http\Controllers\Api\V1\Pps\AttendanceController;
use App\Http\Controllers\Api\V1\Pps\BehaviorController;
use App\Http\Controllers\Api\V1\Pps\ClassroomRatingController;
use App\Http\Controllers\Api\V1\Pps\CounselingController;
use App\Http\Controllers\Api\V1\Pps\DashboardController;
use App\Http\Controllers\Api\V1\Pps\ExtracurricularController;
use App\Http\Controllers\Api\V1\Pps\NoticeController;
use App\Http\Controllers\Api\V1\Pps\NotificationController;
use App\Http\Controllers\Api\V1\Pps\ParentViewController;
use App\Http\Controllers\Api\V1\Pps\ReportController;
use App\Http\Controllers\Api\V1\Pps\SchoolPpsConfigController;
use App\Http\Controllers\Api\V1\Pps\StudentPerformanceController;
use App\Http\Controllers\Api\V1\Pps\UserManagementController;
use App\Http\Controllers\Api\V1\Pps\WelfareController;
use App\Support\PpsPermissions;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/auth')
    ->middleware(['throttle:pps-auth', 'pps.security'])
    ->group(function (): void {
        Route::post('/login', [AuthController::class, 'login']);
    });

Route::prefix('v1/auth')
    ->middleware(['auth:sanctum', 'throttle:pps-api', 'pps.security'])
    ->group(function (): void {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });

Route::prefix('v1/pps')
    ->middleware(['auth:sanctum', 'throttle:pps-api', 'pps.security'])
    ->group(function (): void {
    Route::get('/dashboard/summary', [DashboardController::class, 'summary'])
        ->middleware('pps.permission:'.PpsPermissions::DASHBOARD_VIEW);
    Route::get('/alerts', [AlertController::class, 'index'])
        ->middleware('pps.permission:'.PpsPermissions::ALERTS_VIEW);
    Route::patch('/alerts/{alert}/resolve', [DashboardController::class, 'resolve'])
        ->middleware('pps.permission:'.PpsPermissions::ALERTS_RESOLVE);

    Route::get('/students/search', [StudentPerformanceController::class, 'quickSearch'])
        ->middleware('pps.permission:'.PpsPermissions::STUDENTS_VIEW);
    Route::get('/students', [StudentPerformanceController::class, 'index'])
        ->middleware('pps.permission:'.PpsPermissions::STUDENTS_VIEW);
    Route::get('/students/{student}', [StudentPerformanceController::class, 'show'])
        ->middleware('pps.permission:'.PpsPermissions::STUDENTS_VIEW);
    Route::get('/students/{student}/context', [StudentPerformanceController::class, 'context'])
        ->middleware('pps.permission:'.PpsPermissions::STUDENT_CONTEXT_VIEW);
    Route::patch('/students/{student}/context', [StudentPerformanceController::class, 'updateContext'])
        ->middleware('pps.permission:'.PpsPermissions::STUDENT_CONTEXT_UPDATE);
    Route::post('/students/{student}/what-if', [StudentPerformanceController::class, 'whatIf'])
        ->middleware('pps.permission:'.PpsPermissions::STUDENT_WHAT_IF_RUN);
    Route::get('/students/{student}/counseling', [CounselingController::class, 'studentSessions'])
        ->middleware('pps.permission:'.PpsPermissions::STUDENT_COUNSELING_VIEW);

    Route::get('/classes/{className}/{section}/analytics', [StudentPerformanceController::class, 'classAnalytics'])
        ->middleware('pps.permission:'.PpsPermissions::CLASS_ANALYTICS_VIEW);
    Route::get('/teachers/effectiveness', [StudentPerformanceController::class, 'teacherEffectiveness'])
        ->middleware('pps.permission:'.PpsPermissions::TEACHER_EFFECTIVENESS_VIEW);

    Route::get('/assessments', [AssessmentController::class, 'index'])
        ->middleware('pps.permission:'.PpsPermissions::ASSESSMENTS_MANAGE);
    Route::post('/assessments', [AssessmentController::class, 'store'])
        ->middleware('pps.permission:'.PpsPermissions::ASSESSMENTS_MANAGE);
    Route::post('/assessments/bulk', [AssessmentController::class, 'bulkStore'])
        ->middleware('pps.permission:'.PpsPermissions::ASSESSMENTS_MANAGE);

    Route::get('/attendance', [AttendanceController::class, 'index'])
        ->middleware('pps.permission:'.PpsPermissions::ATTENDANCE_MANAGE);
    Route::post('/attendance', [AttendanceController::class, 'store'])
        ->middleware('pps.permission:'.PpsPermissions::ATTENDANCE_MANAGE);
    Route::post('/attendance/bulk', [AttendanceController::class, 'bulkStore'])
        ->middleware('pps.permission:'.PpsPermissions::ATTENDANCE_MANAGE);

    Route::get('/behavior-cards', [BehaviorController::class, 'index'])
        ->middleware('pps.permission:'.PpsPermissions::BEHAVIOR_MANAGE);
    Route::post('/behavior-cards', [BehaviorController::class, 'store'])
        ->middleware('pps.permission:'.PpsPermissions::BEHAVIOR_MANAGE);
    Route::get('/classroom-ratings', [ClassroomRatingController::class, 'index'])
        ->middleware('pps.permission:'.PpsPermissions::CLASSROOM_RATINGS_MANAGE);
    Route::post('/classroom-ratings', [ClassroomRatingController::class, 'store'])
        ->middleware('pps.permission:'.PpsPermissions::CLASSROOM_RATINGS_MANAGE);
    Route::get('/extracurricular', [ExtracurricularController::class, 'index'])
        ->middleware('pps.permission:'.PpsPermissions::EXTRACURRICULAR_MANAGE);
    Route::post('/extracurricular', [ExtracurricularController::class, 'store'])
        ->middleware('pps.permission:'.PpsPermissions::EXTRACURRICULAR_MANAGE);

    Route::get('/settings', [SchoolPpsConfigController::class, 'show'])
        ->middleware('pps.permission:'.PpsPermissions::SETTINGS_VIEW);
    Route::patch('/settings', [SchoolPpsConfigController::class, 'update'])
        ->middleware('pps.permission:'.PpsPermissions::SETTINGS_UPDATE);

    Route::get('/admin/overview', [AdministrationController::class, 'overview'])
        ->middleware('pps.permission:'.PpsPermissions::ADMIN_PANEL_VIEW);
    Route::post('/admin/departments', [AdministrationController::class, 'storeDepartment'])
        ->middleware('pps.permission:'.PpsPermissions::MASTER_DATA_MANAGE);
    Route::patch('/admin/departments/{department}', [AdministrationController::class, 'updateDepartment'])
        ->middleware('pps.permission:'.PpsPermissions::MASTER_DATA_MANAGE);
    Route::delete('/admin/departments/{department}', [AdministrationController::class, 'destroyDepartment'])
        ->middleware('pps.permission:'.PpsPermissions::MASTER_DATA_MANAGE);

    Route::post('/admin/class-sections', [AdministrationController::class, 'storeClassSection'])
        ->middleware('pps.permission:'.PpsPermissions::MASTER_DATA_MANAGE);
    Route::patch('/admin/class-sections/{classSection}', [AdministrationController::class, 'updateClassSection'])
        ->middleware('pps.permission:'.PpsPermissions::MASTER_DATA_MANAGE);
    Route::delete('/admin/class-sections/{classSection}', [AdministrationController::class, 'destroyClassSection'])
        ->middleware('pps.permission:'.PpsPermissions::MASTER_DATA_MANAGE);

    Route::post('/admin/subjects', [AdministrationController::class, 'storeSubject'])
        ->middleware('pps.permission:'.PpsPermissions::MASTER_DATA_MANAGE);
    Route::patch('/admin/subjects/{subject}', [AdministrationController::class, 'updateSubject'])
        ->middleware('pps.permission:'.PpsPermissions::MASTER_DATA_MANAGE);
    Route::delete('/admin/subjects/{subject}', [AdministrationController::class, 'destroySubject'])
        ->middleware('pps.permission:'.PpsPermissions::MASTER_DATA_MANAGE);

    Route::post('/admin/exams', [AdministrationController::class, 'storeExam'])
        ->middleware('pps.permission:'.PpsPermissions::MASTER_DATA_MANAGE);
    Route::patch('/admin/exams/{exam}', [AdministrationController::class, 'updateExam'])
        ->middleware('pps.permission:'.PpsPermissions::MASTER_DATA_MANAGE);
    Route::delete('/admin/exams/{exam}', [AdministrationController::class, 'destroyExam'])
        ->middleware('pps.permission:'.PpsPermissions::MASTER_DATA_MANAGE);

    Route::post('/admin/streams', [AdministrationController::class, 'storeStream'])
        ->middleware('pps.permission:'.PpsPermissions::MASTER_DATA_MANAGE);
    Route::patch('/admin/streams/{stream}', [AdministrationController::class, 'updateStream'])
        ->middleware('pps.permission:'.PpsPermissions::MASTER_DATA_MANAGE);
    Route::delete('/admin/streams/{stream}', [AdministrationController::class, 'destroyStream'])
        ->middleware('pps.permission:'.PpsPermissions::MASTER_DATA_MANAGE);

    Route::patch('/admin/grade-config', [AdministrationController::class, 'updateGradeConfig'])
        ->middleware('pps.permission:'.PpsPermissions::MASTER_DATA_MANAGE);

    Route::post('/admin/teacher-assignments', [AdministrationController::class, 'storeTeacherAssignment'])
        ->middleware('pps.permission:'.PpsPermissions::TEACHER_ASSIGNMENTS_MANAGE);
    Route::patch('/admin/teacher-assignments/{teacherAssignment}', [AdministrationController::class, 'updateTeacherAssignment'])
        ->middleware('pps.permission:'.PpsPermissions::TEACHER_ASSIGNMENTS_MANAGE);
    Route::delete('/admin/teacher-assignments/{teacherAssignment}', [AdministrationController::class, 'destroyTeacherAssignment'])
        ->middleware('pps.permission:'.PpsPermissions::TEACHER_ASSIGNMENTS_MANAGE);

    Route::post('/admin/students', [AdministrationController::class, 'storeStudent'])
        ->middleware('pps.permission:'.PpsPermissions::STUDENTS_MANAGE);
    Route::patch('/admin/students/{student}', [AdministrationController::class, 'updateStudent'])
        ->middleware('pps.permission:'.PpsPermissions::STUDENTS_MANAGE);
    Route::delete('/admin/students/{student}', [AdministrationController::class, 'destroyStudent'])
        ->middleware('pps.permission:'.PpsPermissions::STUDENTS_MANAGE);

    Route::post('/admin/bulk/students', [AdministrationController::class, 'bulkStudents'])
        ->middleware('pps.permission:'.PpsPermissions::BULK_IMPORT_MANAGE);
    Route::post('/admin/bulk/teacher-assignments', [AdministrationController::class, 'bulkTeacherAssignments'])
        ->middleware('pps.permission:'.PpsPermissions::BULK_IMPORT_MANAGE);

    Route::get('/reports/custom', [StudentPerformanceController::class, 'customReport'])
        ->middleware('pps.permission:'.PpsPermissions::REPORTS_VIEW);
    Route::get('/reports/generate/{type}', [ReportController::class, 'generate'])
        ->middleware('pps.permission:'.PpsPermissions::REPORTS_VIEW);
    Route::get('/reports/report-card', [ReportController::class, 'studentReportCard'])
        ->middleware('pps.permission:'.PpsPermissions::REPORTS_VIEW);
    Route::get('/reports/tabulation', [ReportController::class, 'tabulationSheet'])
        ->middleware('pps.permission:'.PpsPermissions::REPORTS_VIEW);
    Route::get('/notifications', [NotificationController::class, 'index'])
        ->middleware('pps.permission:'.PpsPermissions::NOTIFICATIONS_VIEW);
    Route::post('/notifications/run/{type}', [NotificationController::class, 'run'])
        ->middleware('pps.permission:'.PpsPermissions::NOTIFICATIONS_RUN);

    Route::get('/parents/my-children', [ParentViewController::class, 'myChildren'])
        ->middleware('pps.permission:'.PpsPermissions::PARENT_PORTAL_VIEW);
    Route::get('/parents/my-children/{student}/report', [ParentViewController::class, 'report'])
        ->middleware('pps.permission:'.PpsPermissions::PARENT_REPORT_VIEW);
    Route::get('/parents/my-children/{student}/report/print', [ParentViewController::class, 'printableReport'])
        ->middleware('pps.permission:'.PpsPermissions::PARENT_REPORT_PRINT)
        ->name('pps.parents.report.print');

    // Marks entry — Format A (term-based, Classes 4–10)
    Route::get('/marks/term', [TermMarksController::class, 'index'])
        ->middleware('pps.permission:'.PpsPermissions::ASSESSMENTS_MANAGE);
    Route::post('/marks/term', [TermMarksController::class, 'bulkStore'])
        ->middleware('pps.permission:'.PpsPermissions::ASSESSMENTS_MANAGE);

    // Marks entry — Format B (Pre-Test, Class 12)
    Route::get('/marks/pretest', [PretestMarksController::class, 'index'])
        ->middleware('pps.permission:'.PpsPermissions::ASSESSMENTS_MANAGE);
    Route::post('/marks/pretest', [PretestMarksController::class, 'bulkStore'])
        ->middleware('pps.permission:'.PpsPermissions::ASSESSMENTS_MANAGE);

    // Result summary — GPA computation
    Route::get('/results/summary', [ResultSummaryController::class, 'index'])
        ->middleware('pps.permission:'.PpsPermissions::ASSESSMENTS_MANAGE);
    Route::post('/results/compute', [ResultSummaryController::class, 'compute'])
        ->middleware('pps.permission:'.PpsPermissions::ASSESSMENTS_MANAGE);

    Route::post('/counseling-sessions', [CounselingController::class, 'store'])
        ->middleware('pps.permission:'.PpsPermissions::COUNSELING_MANAGE);
    Route::patch('/counseling-sessions/{session}', [CounselingController::class, 'update'])
        ->middleware('pps.permission:'.PpsPermissions::COUNSELING_MANAGE);
    Route::get('/students/{student}/counseling', [CounselingController::class, 'studentSessions'])
        ->middleware('pps.permission:'.PpsPermissions::STUDENT_COUNSELING_VIEW);
    Route::post('/psychometric', [CounselingController::class, 'storePsychometric'])
        ->middleware('pps.permission:'.PpsPermissions::PSYCHOMETRIC_MANAGE);

    // Welfare interventions
    Route::get('/welfare/students', [WelfareController::class, 'students'])
        ->middleware('pps.permission:'.PpsPermissions::WELFARE_VIEW);
    Route::get('/welfare/students/export', [WelfareController::class, 'export'])
        ->middleware('pps.permission:'.PpsPermissions::WELFARE_VIEW);
    Route::get('/welfare/students/{student}/interventions', [WelfareController::class, 'index'])
        ->middleware('pps.permission:'.PpsPermissions::WELFARE_VIEW);
    Route::post('/welfare/students/{student}/interventions', [WelfareController::class, 'store'])
        ->middleware('pps.permission:'.PpsPermissions::WELFARE_MANAGE);

    // Notice board
    Route::get('/notices', [NoticeController::class, 'index'])
        ->middleware('pps.permission:'.PpsPermissions::NOTICES_VIEW);
    Route::post('/notices', [NoticeController::class, 'store'])
        ->middleware('pps.permission:'.PpsPermissions::NOTICES_MANAGE);
    Route::patch('/notices/{notice}', [NoticeController::class, 'update'])
        ->middleware('pps.permission:'.PpsPermissions::NOTICES_MANAGE);
    Route::delete('/notices/{notice}', [NoticeController::class, 'destroy'])
        ->middleware('pps.permission:'.PpsPermissions::NOTICES_MANAGE);

    // User management (superadmin only — USER_MANAGE permission)
    Route::get('/admin/users', [UserManagementController::class, 'index'])
        ->middleware('pps.permission:'.PpsPermissions::USER_MANAGE);
    Route::post('/admin/users', [UserManagementController::class, 'store'])
        ->middleware('pps.permission:'.PpsPermissions::USER_MANAGE);
    Route::patch('/admin/users/{user}', [UserManagementController::class, 'update'])
        ->middleware('pps.permission:'.PpsPermissions::USER_MANAGE);
    Route::delete('/admin/users/{user}', [UserManagementController::class, 'destroy'])
        ->middleware('pps.permission:'.PpsPermissions::USER_MANAGE);
});

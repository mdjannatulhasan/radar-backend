<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Pps\AlertController;
use App\Http\Controllers\Api\V1\Pps\AdministrationController;
use App\Http\Controllers\Api\V1\Pps\AssessmentController;
use App\Http\Controllers\Api\V1\Pps\MarksMetaController;
use App\Http\Controllers\Api\V1\Pps\TermMarksController;
use App\Http\Controllers\Api\V1\Pps\PretestMarksController;
use App\Http\Controllers\Api\V1\Pps\ExamListController;
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
use App\Http\Controllers\Api\V1\Pps\RolePermissionController;
use App\Http\Controllers\Api\V1\Pps\WelfareController;
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
        ->middleware('pps.can:dashboard.view');
    Route::get('/alerts', [AlertController::class, 'index'])
        ->middleware('pps.can:alerts.view');
    Route::patch('/alerts/{alert}/resolve', [DashboardController::class, 'resolve'])
        ->middleware('pps.can:alerts.resolve');

    Route::get('/students/search', [StudentPerformanceController::class, 'quickSearch'])
        ->middleware('pps.can:students.view');
    Route::get('/students', [StudentPerformanceController::class, 'index'])
        ->middleware('pps.can:students.view');
    Route::get('/students/{student}', [StudentPerformanceController::class, 'show'])
        ->middleware('pps.can:students.view');
    Route::get('/students/{student}/context', [StudentPerformanceController::class, 'context'])
        ->middleware('pps.can:students.context_view');
    Route::patch('/students/{student}/context', [StudentPerformanceController::class, 'updateContext'])
        ->middleware('pps.can:students.context_update');
    Route::post('/students/{student}/what-if', [StudentPerformanceController::class, 'whatIf'])
        ->middleware('pps.can:students.what_if');
    Route::get('/students/{student}/counseling', [CounselingController::class, 'studentSessions'])
        ->middleware('pps.can:students.counseling');

    Route::get('/classes/{className}/{section}/analytics', [StudentPerformanceController::class, 'classAnalytics'])
        ->middleware('pps.can:classes.view');
    Route::get('/teachers/effectiveness', [StudentPerformanceController::class, 'teacherEffectiveness'])
        ->middleware('pps.can:teacher_effectiveness.view');

    Route::get('/assessments', [AssessmentController::class, 'index'])
        ->middleware('pps.can:assessments.manage');
    Route::post('/assessments', [AssessmentController::class, 'store'])
        ->middleware('pps.can:assessments.manage');
    Route::post('/assessments/bulk', [AssessmentController::class, 'bulkStore'])
        ->middleware('pps.can:assessments.manage');

    Route::get('/attendance', [AttendanceController::class, 'index'])
        ->middleware('pps.can:attendance.manage');
    Route::post('/attendance', [AttendanceController::class, 'store'])
        ->middleware('pps.can:attendance.manage');
    Route::post('/attendance/bulk', [AttendanceController::class, 'bulkStore'])
        ->middleware('pps.can:attendance.manage');

    Route::get('/behavior-cards', [BehaviorController::class, 'index'])
        ->middleware('pps.can:behavior.manage');
    Route::post('/behavior-cards', [BehaviorController::class, 'store'])
        ->middleware('pps.can:behavior.manage');
    Route::get('/classroom-ratings', [ClassroomRatingController::class, 'index'])
        ->middleware('pps.can:classroom_ratings.manage');
    Route::post('/classroom-ratings', [ClassroomRatingController::class, 'store'])
        ->middleware('pps.can:classroom_ratings.manage');
    Route::get('/extracurricular', [ExtracurricularController::class, 'index'])
        ->middleware('pps.can:extracurricular.manage');
    Route::post('/extracurricular', [ExtracurricularController::class, 'store'])
        ->middleware('pps.can:extracurricular.manage');

    Route::get('/settings', [SchoolPpsConfigController::class, 'show'])
        ->middleware('pps.can:settings.view');
    Route::patch('/settings', [SchoolPpsConfigController::class, 'update'])
        ->middleware('pps.can:settings.update');

    Route::get('/admin/overview', [AdministrationController::class, 'overview'])
        ->middleware('pps.can:admin_panel.view');
    Route::post('/admin/departments', [AdministrationController::class, 'storeDepartment'])
        ->middleware('pps.can:admin_panel.manage');
    Route::patch('/admin/departments/{department}', [AdministrationController::class, 'updateDepartment'])
        ->middleware('pps.can:admin_panel.manage');
    Route::delete('/admin/departments/{department}', [AdministrationController::class, 'destroyDepartment'])
        ->middleware('pps.can:admin_panel.manage');

    Route::post('/admin/class-sections', [AdministrationController::class, 'storeClassSection'])
        ->middleware('pps.can:admin_panel.manage');
    Route::patch('/admin/class-sections/{classSection}', [AdministrationController::class, 'updateClassSection'])
        ->middleware('pps.can:admin_panel.manage');
    Route::delete('/admin/class-sections/{classSection}', [AdministrationController::class, 'destroyClassSection'])
        ->middleware('pps.can:admin_panel.manage');

    Route::post('/admin/subjects', [AdministrationController::class, 'storeSubject'])
        ->middleware('pps.can:admin_panel.manage');
    Route::patch('/admin/subjects/{subject}', [AdministrationController::class, 'updateSubject'])
        ->middleware('pps.can:admin_panel.manage');
    Route::delete('/admin/subjects/{subject}', [AdministrationController::class, 'destroySubject'])
        ->middleware('pps.can:admin_panel.manage');

    Route::post('/admin/exams', [AdministrationController::class, 'storeExam'])
        ->middleware('pps.can:admin_panel.manage');
    Route::patch('/admin/exams/{exam}', [AdministrationController::class, 'updateExam'])
        ->middleware('pps.can:admin_panel.manage');
    Route::delete('/admin/exams/{exam}', [AdministrationController::class, 'destroyExam'])
        ->middleware('pps.can:admin_panel.manage');

    Route::post('/admin/streams', [AdministrationController::class, 'storeStream'])
        ->middleware('pps.can:admin_panel.manage');
    Route::patch('/admin/streams/{stream}', [AdministrationController::class, 'updateStream'])
        ->middleware('pps.can:admin_panel.manage');
    Route::delete('/admin/streams/{stream}', [AdministrationController::class, 'destroyStream'])
        ->middleware('pps.can:admin_panel.manage');

    Route::patch('/admin/grade-config', [AdministrationController::class, 'updateGradeConfig'])
        ->middleware('pps.can:admin_panel.manage');
    Route::post('/admin/grade-config/reset', [AdministrationController::class, 'resetGradeConfig'])
        ->middleware('pps.can:admin_panel.manage');

    Route::post('/admin/teacher-assignments', [AdministrationController::class, 'storeTeacherAssignment'])
        ->middleware('pps.can:teacher_assignments.manage');
    Route::patch('/admin/teacher-assignments/{teacherAssignment}', [AdministrationController::class, 'updateTeacherAssignment'])
        ->middleware('pps.can:teacher_assignments.manage');
    Route::delete('/admin/teacher-assignments/{teacherAssignment}', [AdministrationController::class, 'destroyTeacherAssignment'])
        ->middleware('pps.can:teacher_assignments.manage');

    Route::post('/admin/students', [AdministrationController::class, 'storeStudent'])
        ->middleware('pps.can:students.manage');
    Route::patch('/admin/students/{student}', [AdministrationController::class, 'updateStudent'])
        ->middleware('pps.can:students.manage');
    Route::delete('/admin/students/{student}', [AdministrationController::class, 'destroyStudent'])
        ->middleware('pps.can:students.manage');

    Route::post('/admin/bulk/students', [AdministrationController::class, 'bulkStudents'])
        ->middleware('pps.can:bulk_import.manage');
    Route::post('/admin/bulk/teacher-assignments', [AdministrationController::class, 'bulkTeacherAssignments'])
        ->middleware('pps.can:bulk_import.manage');

    Route::get('/reports/custom', [StudentPerformanceController::class, 'customReport'])
        ->middleware('pps.can:reports.view');
    Route::get('/reports/generate/{type}', [ReportController::class, 'generate'])
        ->middleware('pps.can:reports.view');
    Route::get('/reports/report-card', [ReportController::class, 'studentReportCard'])
        ->middleware('pps.can:reports.view');
    Route::get('/reports/tabulation', [ReportController::class, 'tabulationSheet'])
        ->middleware('pps.can:reports.view');

    Route::get('/notifications', [NotificationController::class, 'index'])
        ->middleware('pps.can:notifications.view');
    Route::post('/notifications/run/{type}', [NotificationController::class, 'run'])
        ->middleware('pps.can:notifications.run');

    Route::get('/parents/my-children', [ParentViewController::class, 'myChildren'])
        ->middleware('pps.can:parents.portal');
    Route::get('/parents/my-children/{student}/report', [ParentViewController::class, 'report'])
        ->middleware('pps.can:parents.report');
    Route::get('/parents/my-children/{student}/report/print', [ParentViewController::class, 'printableReport'])
        ->middleware('pps.can:parents.print')
        ->name('pps.parents.report.print');

    // Exam list — active exams for marks/results/report-cards pages
    Route::get('/exams', [ExamListController::class, 'index'])
        ->middleware('pps.can:marks.read');

    // Marks meta — exams + subjects scoped to the authenticated user
    Route::get('/marks/meta', [MarksMetaController::class, 'index'])
        ->middleware('pps.can:marks.read');

    // Marks entry — Format A: GET is read, POST is write
    Route::get('/marks/term', [TermMarksController::class, 'index'])
        ->middleware('pps.can:marks.read');
    Route::post('/marks/term', [TermMarksController::class, 'bulkStore'])
        ->middleware('pps.can:marks.write');

    // Marks entry — Format B: same pattern
    Route::get('/marks/pretest', [PretestMarksController::class, 'index'])
        ->middleware('pps.can:marks.read');
    Route::post('/marks/pretest', [PretestMarksController::class, 'bulkStore'])
        ->middleware('pps.can:marks.write');

    // Result summary — GET is read, POST compute is write
    Route::get('/results/summary', [ResultSummaryController::class, 'index'])
        ->middleware('pps.can:results.read');
    Route::post('/results/compute', [ResultSummaryController::class, 'compute'])
        ->middleware('pps.can:results.compute');

    Route::post('/counseling-sessions', [CounselingController::class, 'store'])
        ->middleware('pps.can:counseling.manage');
    Route::patch('/counseling-sessions/{session}', [CounselingController::class, 'update'])
        ->middleware('pps.can:counseling.manage');
    Route::get('/students/{student}/counseling', [CounselingController::class, 'studentSessions'])
        ->middleware('pps.can:students.counseling');
    Route::post('/psychometric', [CounselingController::class, 'storePsychometric'])
        ->middleware('pps.can:counseling.psychometric');

    // Welfare interventions
    Route::get('/welfare/students', [WelfareController::class, 'students'])
        ->middleware('pps.can:welfare.view');
    Route::get('/welfare/students/export', [WelfareController::class, 'export'])
        ->middleware('pps.can:welfare.view');
    Route::get('/welfare/students/{student}/interventions', [WelfareController::class, 'index'])
        ->middleware('pps.can:welfare.view');
    Route::post('/welfare/students/{student}/interventions', [WelfareController::class, 'store'])
        ->middleware('pps.can:welfare.manage');

    // Notice board
    Route::get('/notices', [NoticeController::class, 'index'])
        ->middleware('pps.can:notices.view');
    Route::post('/notices', [NoticeController::class, 'store'])
        ->middleware('pps.can:notices.manage');
    Route::patch('/notices/{notice}', [NoticeController::class, 'update'])
        ->middleware('pps.can:notices.manage');
    Route::delete('/notices/{notice}', [NoticeController::class, 'destroy'])
        ->middleware('pps.can:notices.manage');

    // Role permission management (admin_panel.manage)
    Route::get('/admin/roles', [RolePermissionController::class, 'index'])
        ->middleware('pps.can:admin_panel.manage');
    Route::get('/admin/roles/{role}/permissions', [RolePermissionController::class, 'show'])
        ->middleware('pps.can:admin_panel.manage');
    Route::patch('/admin/roles/{role}/permissions', [RolePermissionController::class, 'update'])
        ->middleware('pps.can:admin_panel.manage');
    Route::get('/admin/permission-modules', [RolePermissionController::class, 'modules'])
        ->middleware('pps.can:admin_panel.manage');

    // User management (superadmin only)
    Route::get('/admin/users', [UserManagementController::class, 'index'])
        ->middleware('pps.can:users.manage');
    Route::post('/admin/users', [UserManagementController::class, 'store'])
        ->middleware('pps.can:users.manage');
    Route::patch('/admin/users/{user}', [UserManagementController::class, 'update'])
        ->middleware('pps.can:users.manage');
    Route::delete('/admin/users/{user}', [UserManagementController::class, 'destroy'])
        ->middleware('pps.can:users.manage');
});

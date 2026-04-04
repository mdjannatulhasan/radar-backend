<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Pps\AlertController;
use App\Http\Controllers\Api\V1\Pps\AssessmentController;
use App\Http\Controllers\Api\V1\Pps\AttendanceController;
use App\Http\Controllers\Api\V1\Pps\BehaviorController;
use App\Http\Controllers\Api\V1\Pps\ClassroomRatingController;
use App\Http\Controllers\Api\V1\Pps\CounselingController;
use App\Http\Controllers\Api\V1\Pps\DashboardController;
use App\Http\Controllers\Api\V1\Pps\ExtracurricularController;
use App\Http\Controllers\Api\V1\Pps\NotificationController;
use App\Http\Controllers\Api\V1\Pps\ParentViewController;
use App\Http\Controllers\Api\V1\Pps\ReportController;
use App\Http\Controllers\Api\V1\Pps\SchoolPpsConfigController;
use App\Http\Controllers\Api\V1\Pps\StudentPerformanceController;
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

    Route::get('/reports/custom', [StudentPerformanceController::class, 'customReport'])
        ->middleware('pps.permission:'.PpsPermissions::REPORTS_VIEW);
    Route::get('/reports/generate/{type}', [ReportController::class, 'generate'])
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

    Route::post('/counseling-sessions', [CounselingController::class, 'store'])
        ->middleware('pps.permission:'.PpsPermissions::COUNSELING_MANAGE);
    Route::post('/psychometric', [CounselingController::class, 'storePsychometric'])
        ->middleware('pps.permission:'.PpsPermissions::PSYCHOMETRIC_MANAGE);
});

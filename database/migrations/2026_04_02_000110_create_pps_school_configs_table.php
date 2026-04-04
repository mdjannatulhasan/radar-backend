<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pps_school_configs', function (Blueprint $table): void {
            $table->id();
            $table->decimal('weight_academic', 4, 2)->default(config('pps.weights.academic'));
            $table->decimal('weight_attendance', 4, 2)->default(config('pps.weights.attendance'));
            $table->decimal('weight_behavior', 4, 2)->default(config('pps.weights.behavior'));
            $table->decimal('weight_participation', 4, 2)->default(config('pps.weights.participation'));
            $table->decimal('weight_extracurricular', 4, 2)->default(config('pps.weights.extracurricular'));
            $table->decimal('threshold_risk_watch', 5, 2)->default(config('pps.thresholds.risk_watch'));
            $table->decimal('threshold_risk_warning', 5, 2)->default(config('pps.thresholds.risk_warning'));
            $table->decimal('threshold_risk_urgent', 5, 2)->default(config('pps.thresholds.risk_urgent'));
            $table->decimal('threshold_attendance_watch', 5, 2)->default(config('pps.thresholds.attendance_watch'));
            $table->decimal('threshold_attendance_warning', 5, 2)->default(config('pps.thresholds.attendance_warning'));
            $table->decimal('threshold_attendance_urgent', 5, 2)->default(config('pps.thresholds.attendance_urgent'));
            $table->decimal('threshold_grade_drop_warning', 5, 2)->default(config('pps.thresholds.grade_drop_warning'));
            $table->decimal('threshold_grade_drop_urgent', 5, 2)->default(config('pps.thresholds.grade_drop_urgent'));
            $table->unsignedTinyInteger('threshold_yellow_cards_warning')->default(config('pps.thresholds.yellow_cards_warning'));
            $table->boolean('notify_parent_on_warning')->default(config('pps.notifications.notify_parent_on_warning'));
            $table->boolean('notify_parent_on_watch')->default(config('pps.notifications.notify_parent_on_watch'));
            $table->boolean('send_monthly_parent_report')->default(config('pps.notifications.send_monthly_parent_report'));
            $table->boolean('send_weekly_principal_summary')->default(config('pps.notifications.send_weekly_principal_summary'));
            $table->boolean('notify_guardian_email_on_urgent')->default(config('pps.notifications.notify_guardian_email_on_urgent'));
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pps_school_configs');
    }
};

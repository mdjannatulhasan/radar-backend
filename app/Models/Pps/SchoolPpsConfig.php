<?php

namespace App\Models\Pps;

use Illuminate\Database\Eloquent\Model;

class SchoolPpsConfig extends Model
{
    protected $table = 'pps_school_configs';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'weight_academic' => 'float',
            'weight_attendance' => 'float',
            'weight_behavior' => 'float',
            'weight_participation' => 'float',
            'weight_extracurricular' => 'float',
            'threshold_risk_watch' => 'float',
            'threshold_risk_warning' => 'float',
            'threshold_risk_urgent' => 'float',
            'threshold_attendance_watch' => 'float',
            'threshold_attendance_warning' => 'float',
            'threshold_attendance_urgent' => 'float',
            'threshold_grade_drop_warning' => 'float',
            'threshold_grade_drop_urgent' => 'float',
            'threshold_yellow_cards_warning' => 'integer',
            'notify_parent_on_warning' => 'boolean',
            'notify_parent_on_watch' => 'boolean',
            'send_monthly_parent_report' => 'boolean',
            'send_weekly_principal_summary' => 'boolean',
            'notify_guardian_email_on_urgent' => 'boolean',
        ];
    }

    public static function current(): self
    {
        return static::query()->first() ?? static::query()->create(static::defaults());
    }

    public static function defaults(): array
    {
        return [
            'weight_academic' => config('pps.weights.academic'),
            'weight_attendance' => config('pps.weights.attendance'),
            'weight_behavior' => config('pps.weights.behavior'),
            'weight_participation' => config('pps.weights.participation'),
            'weight_extracurricular' => config('pps.weights.extracurricular'),
            'threshold_risk_watch' => config('pps.thresholds.risk_watch'),
            'threshold_risk_warning' => config('pps.thresholds.risk_warning'),
            'threshold_risk_urgent' => config('pps.thresholds.risk_urgent'),
            'threshold_attendance_watch' => config('pps.thresholds.attendance_watch'),
            'threshold_attendance_warning' => config('pps.thresholds.attendance_warning'),
            'threshold_attendance_urgent' => config('pps.thresholds.attendance_urgent'),
            'threshold_grade_drop_warning' => config('pps.thresholds.grade_drop_warning'),
            'threshold_grade_drop_urgent' => config('pps.thresholds.grade_drop_urgent'),
            'threshold_yellow_cards_warning' => config('pps.thresholds.yellow_cards_warning'),
            'notify_parent_on_warning' => config('pps.notifications.notify_parent_on_warning'),
            'notify_parent_on_watch' => config('pps.notifications.notify_parent_on_watch'),
            'send_monthly_parent_report' => config('pps.notifications.send_monthly_parent_report'),
            'send_weekly_principal_summary' => config('pps.notifications.send_weekly_principal_summary'),
            'notify_guardian_email_on_urgent' => config('pps.notifications.notify_guardian_email_on_urgent'),
        ];
    }
}

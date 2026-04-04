<?php

return [
    'weights' => [
        'academic' => 0.40,
        'attendance' => 0.20,
        'behavior' => 0.15,
        'participation' => 0.15,
        'extracurricular' => 0.10,
    ],
    'thresholds' => [
        'risk_watch' => 20,
        'risk_warning' => 40,
        'risk_urgent' => 70,
        'attendance_watch' => 85,
        'attendance_warning' => 75,
        'attendance_urgent' => 60,
        'grade_drop_warning' => 10,
        'grade_drop_urgent' => 20,
        'yellow_cards_warning' => 3,
    ],
    'notifications' => [
        'notify_parent_on_warning' => true,
        'notify_parent_on_watch' => false,
        'send_monthly_parent_report' => true,
        'send_weekly_principal_summary' => true,
        'notify_guardian_email_on_urgent' => true,
    ],
];

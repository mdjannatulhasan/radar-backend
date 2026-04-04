<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('pps:notify-alerts {period?}', function (?string $period = null) {
    $count = app(\App\Services\Pps\NotificationDigestService::class)
        ->generateAlertNotifications($period);

    $this->info("Generated {$count} PPS alert notification log(s).");
})->purpose('Generate deterministic PPS alert notifications.');

Artisan::command('pps:monthly-parent-reports {period?}', function (?string $period = null) {
    $count = app(\App\Services\Pps\NotificationDigestService::class)
        ->generateMonthlyParentReports($period);

    $this->info("Generated {$count} monthly parent report notification log(s).");
})->purpose('Generate PPS monthly parent report notifications.');

Artisan::command('pps:weekly-principal-summary {period?}', function (?string $period = null) {
    $count = app(\App\Services\Pps\NotificationDigestService::class)
        ->generateWeeklyPrincipalSummary($period);

    $this->info("Generated {$count} weekly principal summary notification log(s).");
})->purpose('Generate PPS weekly principal summary notifications.');

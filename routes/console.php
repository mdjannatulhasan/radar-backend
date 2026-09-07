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

Artisan::command('pps:early-warnings {period?}', function (?string $period = null) {
    $stats = app(\App\Services\Pps\EarlyWarningService::class)
        ->generate($period ?: now()->format('Y-m'));

    $this->info(sprintf(
        'Early warnings for %s: scanned %d, created %d, updated %d, cleared %d, notifications %d.',
        $stats['period'], $stats['scanned'], $stats['created'], $stats['updated'], $stats['cleared'], $stats['notified'],
    ));
})->purpose('Predict students likely to fall within 1/3/6 months and notify their teachers and VPs.');

// Monthly, after the month's snapshots exist. Runs for every tenant.
// Needs the host cron: * * * * * php artisan schedule:run
\Illuminate\Support\Facades\Schedule::command('tenants:run pps:early-warnings')
    ->monthlyOn(2, '06:00')
    ->withoutOverlapping();

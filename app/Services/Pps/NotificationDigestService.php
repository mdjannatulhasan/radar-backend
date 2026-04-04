<?php

namespace App\Services\Pps;

use App\Models\Pps\PpsAlert;
use App\Models\Pps\PpsNotificationLog;
use App\Models\Pps\SchoolPpsConfig;
use App\Models\Student;
use App\Models\User;

class NotificationDigestService
{
    public function generateAlertNotifications(?string $period = null): int
    {
        $activePeriod = $period ?: now()->format('Y-m');
        $alerts = PpsAlert::query()
            ->where('snapshot_period', $activePeriod)
            ->whereNull('resolved_at')
            ->with('student')
            ->get();

        $created = 0;
        foreach ($alerts as $alert) {
            foreach (($alert->notified_to ?? []) as $target) {
                $recipient = $this->resolveRecipient($target['role'] ?? '', $alert->student);
                $created += (int) $this->createIfMissing([
                    'type' => 'alert_notification',
                    'channel' => $target['channel'] ?? 'database',
                    'recipient_role' => $target['role'] ?? 'unknown',
                    'recipient_user_id' => $recipient?->id,
                    'student_id' => $alert->student_id,
                    'snapshot_period' => $alert->snapshot_period,
                    'subject' => strtoupper($alert->alert_level).' PPS alert for '.$alert->student?->name,
                    'body' => collect($alert->trigger_reasons)->pluck('detail')->implode(' '),
                    'meta' => [
                        'alert_id' => $alert->id,
                        'alert_level' => $alert->alert_level,
                    ],
                ]);
            }
        }

        return $created;
    }

    public function generateMonthlyParentReports(?string $period = null): int
    {
        $config = SchoolPpsConfig::current();
        if (! $config->send_monthly_parent_report) {
            return 0;
        }

        $activePeriod = $period ?: now()->format('Y-m');
        $students = Student::query()
            ->whereNotNull('guardian_email')
            ->with(['performanceSnapshots' => fn ($query) => $query->where('snapshot_period', $activePeriod)])
            ->get();

        $created = 0;
        foreach ($students as $student) {
            $snapshot = $student->performanceSnapshots->first();
            $guardian = User::query()->where('email', $student->guardian_email)->first();

            if (! $snapshot || ! $guardian) {
                continue;
            }

            $created += (int) $this->createIfMissing([
                'type' => 'monthly_parent_report',
                'channel' => 'email',
                'recipient_role' => 'guardian',
                'recipient_user_id' => $guardian->id,
                'student_id' => $student->id,
                'snapshot_period' => $activePeriod,
                'subject' => "Monthly PPS report for {$student->name}",
                'body' => "Overall {$snapshot->overall_score}, academic {$snapshot->academic_score}, attendance {$snapshot->attendance_score}, alert {$snapshot->alert_level}.",
                'meta' => [
                    'report_type' => 'student_card',
                    'student_name' => $student->name,
                ],
            ]);
        }

        return $created;
    }

    public function generateWeeklyPrincipalSummary(?string $period = null): int
    {
        $config = SchoolPpsConfig::current();
        if (! $config->send_weekly_principal_summary) {
            return 0;
        }

        $activePeriod = $period ?: now()->format('Y-m');
        $summary = app(\App\Http\Controllers\Api\V1\Pps\DashboardController::class)
            ->summary(new \Illuminate\Http\Request(['period' => $activePeriod]))
            ->getData(true);

        $recipients = User::query()
            ->whereIn('role', ['principal', 'admin'])
            ->get();

        $created = 0;
        foreach ($recipients as $recipient) {
            $created += (int) $this->createIfMissing([
                'type' => 'weekly_principal_summary',
                'channel' => 'email',
                'recipient_role' => $recipient->role,
                'recipient_user_id' => $recipient->id,
                'snapshot_period' => $activePeriod,
                'subject' => "Weekly PPS summary for {$activePeriod}",
                'body' => "Urgent {$summary['summary']['urgent_count']}, warning {$summary['summary']['warning_count']}, watch {$summary['summary']['watch_count']}, school average {$summary['summary']['school_avg']}.",
                'meta' => [
                    'class_overview' => array_slice($summary['class_overview'], 0, 5),
                    'notable_items' => $summary['notable_items'],
                ],
            ]);
        }

        return $created;
    }

    public function queryLogsForViewer(?User $user, array $filters = [])
    {
        $query = PpsNotificationLog::query()
            ->with('student:id,name,class_name,section', 'recipient:id,name,email,role')
            ->orderByDesc('generated_at')
            ->orderByDesc('id');

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->hasAnyRole(['principal', 'admin', 'counselor'])) {
            // Full visibility.
        } elseif ($user->hasAnyRole('guardian')) {
            $query->where('recipient_user_id', $user->id);
        } else {
            $query->where(function ($builder) use ($user): void {
                $builder->where('recipient_user_id', $user->id)
                    ->orWhereIn('recipient_role', ['class_teacher', 'subject_teacher']);
            });
        }

        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (! empty($filters['snapshot_period'])) {
            $query->where('snapshot_period', $filters['snapshot_period']);
        }

        return $query;
    }

    private function createIfMissing(array $attributes): bool
    {
        $existing = PpsNotificationLog::query()
            ->where('type', $attributes['type'])
            ->where('recipient_role', $attributes['recipient_role'])
            ->where('recipient_user_id', $attributes['recipient_user_id'])
            ->where('student_id', $attributes['student_id'] ?? null)
            ->where('snapshot_period', $attributes['snapshot_period'] ?? null)
            ->first();

        if ($existing) {
            return false;
        }

        PpsNotificationLog::query()->create([
            ...$attributes,
            'generated_at' => now(),
        ]);

        return true;
    }

    private function resolveRecipient(string $role, ?Student $student): ?User
    {
        return match ($role) {
            'guardian' => $student?->guardian_email
                ? User::query()->where('email', $student->guardian_email)->first()
                : null,
            'principal', 'admin', 'counselor' => User::query()->where('role', $role)->first(),
            default => null,
        };
    }
}

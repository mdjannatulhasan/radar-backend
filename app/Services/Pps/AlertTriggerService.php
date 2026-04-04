<?php

namespace App\Services\Pps;

use App\Models\Pps\AttendanceRecord;
use App\Models\Pps\BehaviorCard;
use App\Models\Pps\ClassroomRating;
use App\Models\Pps\PerformanceSnapshot;
use App\Models\Pps\PpsAlert;
use App\Models\Pps\SchoolPpsConfig;
use Carbon\Carbon;

class AlertTriggerService
{
    public function evaluate(int $studentId, string $period): ?PpsAlert
    {
        $config = SchoolPpsConfig::current();

        $current = PerformanceSnapshot::query()
            ->where('student_id', $studentId)
            ->where('snapshot_period', $period)
            ->first();

        if (! $current || $current->alert_level === 'none') {
            PpsAlert::query()
                ->where('student_id', $studentId)
                ->whereNull('resolved_at')
                ->update([
                    'resolved_at' => now(),
                    'resolution_note' => 'Auto-resolved after risk dropped below threshold.',
                ]);

            return null;
        }

        $previous = PerformanceSnapshot::query()
            ->where('student_id', $studentId)
            ->where('snapshot_period', '<', $period)
            ->orderByDesc('snapshot_period')
            ->first();

        $triggers = $this->collectTriggers($studentId, $period, $current, $previous, $config);

        if ($triggers === []) {
            return null;
        }

        $existing = PpsAlert::query()
            ->where('student_id', $studentId)
            ->where('snapshot_period', $period)
            ->where('alert_level', $current->alert_level)
            ->whereNull('resolved_at')
            ->first();

        if ($existing) {
            return $existing;
        }

        return PpsAlert::query()->create([
            'student_id' => $studentId,
            'snapshot_period' => $period,
            'alert_level' => $current->alert_level,
            'trigger_reasons' => $triggers,
            'notified_to' => $this->buildNotifiedTo($current->alert_level, $config),
        ]);
    }

    private function collectTriggers(
        int $studentId,
        string $period,
        PerformanceSnapshot $current,
        ?PerformanceSnapshot $previous,
        SchoolPpsConfig $config,
    ): array {
        [$year, $month] = array_map('intval', explode('-', $period));
        $triggers = [];

        if ($current->academic_score < 33) {
            $triggers[] = ['type' => 'academic_fail_zone', 'detail' => 'Academic score is in the fail zone.', 'value' => $current->academic_score];
        }

        if ($previous) {
            $drop = $previous->academic_score - $current->academic_score;
            if ($drop >= $config->threshold_grade_drop_urgent) {
                $triggers[] = ['type' => 'rapid_academic_drop', 'detail' => 'Academic performance fell sharply.', 'value' => round($drop, 1)];
            } elseif ($drop >= $config->threshold_grade_drop_warning) {
                $triggers[] = ['type' => 'academic_drop', 'detail' => 'Academic performance declined meaningfully.', 'value' => round($drop, 1)];
            }
        }

        if ($current->attendance_score < $config->threshold_attendance_urgent) {
            $triggers[] = ['type' => 'critical_attendance', 'detail' => 'Attendance is critically low.', 'value' => $current->attendance_score];
        } elseif ($current->attendance_score < $config->threshold_attendance_warning) {
            $triggers[] = ['type' => 'low_attendance', 'detail' => 'Attendance is below the warning threshold.', 'value' => $current->attendance_score];
        }

        $consecutiveAbsences = $this->checkConsecutiveAbsence($studentId, $year, $month);
        if ($consecutiveAbsences >= 3) {
            $triggers[] = ['type' => 'consecutive_absence', 'detail' => "Absent for {$consecutiveAbsences} consecutive tracked days.", 'value' => $consecutiveAbsences];
        }

        $redCards = BehaviorCard::query()
            ->where('student_id', $studentId)
            ->whereYear('issued_at', $year)
            ->whereMonth('issued_at', $month)
            ->where('card_type', 'red')
            ->count();
        if ($redCards > 0) {
            $triggers[] = ['type' => 'red_card', 'detail' => "Received {$redCards} red card(s).", 'value' => $redCards];
        }

        $yellowCards = BehaviorCard::query()
            ->where('student_id', $studentId)
            ->whereYear('issued_at', $year)
            ->whereMonth('issued_at', $month)
            ->where('card_type', 'yellow')
            ->count();
        if ($yellowCards >= $config->threshold_yellow_cards_warning) {
            $triggers[] = ['type' => 'multiple_yellow_cards', 'detail' => "Received {$yellowCards} yellow card(s).", 'value' => $yellowCards];
        }

        $flags = ClassroomRating::query()
            ->where('student_id', $studentId)
            ->whereYear('rating_period', $year)
            ->whereMonth('rating_period', $month)
            ->whereNotNull('behavioral_flag')
            ->pluck('behavioral_flag')
            ->filter()
            ->unique()
            ->values()
            ->all();
        if ($flags !== []) {
            $triggers[] = ['type' => 'behavioral_flag', 'detail' => 'Teacher flagged a noticeable behavior change.', 'value' => implode(', ', $flags)];
        }

        if ($previous) {
            $drops = [];
            if (($previous->academic_score - $current->academic_score) > 5) {
                $drops[] = 'academic';
            }
            if (($previous->attendance_score - $current->attendance_score) > 5) {
                $drops[] = 'attendance';
            }
            if (($previous->behavior_score - $current->behavior_score) > 5) {
                $drops[] = 'behavior';
            }
            if (count($drops) >= 2) {
                $triggers[] = ['type' => 'combined_drop', 'detail' => 'Multiple indicators dropped together.', 'value' => implode(', ', $drops)];
            }
        }

        return $triggers;
    }

    private function checkConsecutiveAbsence(int $studentId, int $year, int $month): int
    {
        $dates = AttendanceRecord::query()
            ->where('student_id', $studentId)
            ->whereYear('date', $year)
            ->whereMonth('date', $month)
            ->where('status', 'absent')
            ->whereNull('period')
            ->orderBy('date')
            ->pluck('date')
            ->map(fn ($date) => Carbon::parse($date))
            ->values();

        if ($dates->isEmpty()) {
            return 0;
        }

        $currentRun = 1;
        $maxRun = 1;

        for ($index = 1; $index < $dates->count(); $index++) {
            if ($dates[$index]->diffInDays($dates[$index - 1]) === 1) {
                $currentRun++;
                $maxRun = max($maxRun, $currentRun);
            } else {
                $currentRun = 1;
            }
        }

        return $maxRun;
    }

    private function buildNotifiedTo(string $alertLevel, SchoolPpsConfig $config): array
    {
        $targets = [
            ['role' => 'class_teacher', 'channel' => 'database'],
            ['role' => 'subject_teacher', 'channel' => 'database'],
        ];

        if ($alertLevel === 'watch' && $config->notify_parent_on_watch) {
            $targets[] = ['role' => 'guardian', 'channel' => 'sms'];
        }

        if (in_array($alertLevel, ['warning', 'urgent'], true)) {
            $targets[] = ['role' => 'principal', 'channel' => 'database'];
            if ($config->notify_parent_on_warning) {
                $targets[] = ['role' => 'guardian', 'channel' => 'sms'];
            }
        }

        if ($alertLevel === 'urgent') {
            $targets[] = ['role' => 'counselor', 'channel' => 'database'];
            if ($config->notify_guardian_email_on_urgent) {
                $targets[] = ['role' => 'guardian', 'channel' => 'email'];
            }
        }

        return $targets;
    }
}

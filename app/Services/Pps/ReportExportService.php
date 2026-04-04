<?php

namespace App\Services\Pps;

use App\Models\Pps\PerformanceSnapshot;
use App\Models\Student;

class ReportExportService
{
    public function buildStudentCard(Student $student, PerformanceSnapshot $snapshot, string $lang = 'en'): array
    {
        $subjects = collect($snapshot->snapshot_data['subjects'] ?? [])
            ->map(fn (array $data, string $subject) => "{$subject}: ".round((float) ($data['avg'] ?? 0), 1))
            ->values()
            ->all();

        if ($lang === 'bn') {
            return [
                "শিক্ষার্থী: {$student->name}",
                "শ্রেণি: {$student->class_name}-{$student->section}",
                'রোল: '.($student->roll_number ?? 'N/A'),
                "সামগ্রিক স্কোর: {$snapshot->overall_score}",
                "একাডেমিক: {$snapshot->academic_score}",
                "হাজিরা: {$snapshot->attendance_score}",
                "আচরণ: {$snapshot->behavior_score}",
                "অংশগ্রহণ: {$snapshot->participation_score}",
                "সহশিক্ষা: {$snapshot->extracurricular_score}",
                "সতর্কতার স্তর: {$snapshot->alert_level}",
                'বিষয়সমূহ: '.implode(' | ', $subjects),
            ];
        }

        return [
            "Student: {$student->name}",
            "Class: {$student->class_name}-{$student->section}",
            "Roll: ".($student->roll_number ?? 'N/A'),
            "Overall: {$snapshot->overall_score}",
            "Academic: {$snapshot->academic_score}",
            "Attendance: {$snapshot->attendance_score}",
            "Behavior: {$snapshot->behavior_score}",
            "Participation: {$snapshot->participation_score}",
            "Extracurricular: {$snapshot->extracurricular_score}",
            "Alert level: {$snapshot->alert_level}",
            'Subjects: '.implode(' | ', $subjects),
        ];
    }

    public function buildClassSummary(string $className, string $section, $summary, $subjects): array
    {
        $lines = [
            "Class summary for {$className}-{$section}",
            "Total students: ".($summary->total ?? 0),
            "Average overall: ".($summary->avg_overall ?? 0),
            "Average academic: ".($summary->avg_academic ?? 0),
            "Average attendance: ".($summary->avg_attendance ?? 0),
            "Urgent: ".($summary->urgent ?? 0),
            "Warning: ".($summary->warning ?? 0),
            "Watch: ".($summary->watch ?? 0),
            'Subject overview:',
        ];

        foreach ($subjects as $subject) {
            $lines[] = "- {$subject['subject']}: {$subject['class_avg']} (gap {$subject['school_gap']})";
        }

        return $lines;
    }

    public function buildAtRiskList($snapshots): array
    {
        $lines = ['At-risk student list'];

        foreach ($snapshots as $snapshot) {
            $student = $snapshot->student;
            $lines[] = "{$student?->name} | {$student?->class_name}-{$student?->section} | Risk {$snapshot->risk_score} | Alert {$snapshot->alert_level}";
        }

        return $lines;
    }

    public function buildTeacherEffectiveness($rows): array
    {
        $lines = ['Teacher effectiveness report'];

        foreach ($rows as $row) {
            $lines[] = "{$row['teacher_name']} | {$row['subject']} | Avg {$row['avg_score']} | Change ".($row['change'] ?? 'N/A');
        }

        return $lines;
    }

    public function buildBoardSummary(string $period, array $summary, array $classOverview, array $teacherHighlights): array
    {
        $lines = [
            "Board and trustee summary for {$period}",
            "Tracked students: {$summary['total_students']}",
            "School average: {$summary['school_avg']}",
            "Urgent cases: {$summary['urgent_count']}",
            "Warning cases: {$summary['warning_count']}",
            'Section highlights:',
        ];

        foreach (array_slice($classOverview, 0, 5) as $row) {
            $lines[] = "- {$row['class_name']}-{$row['section']}: avg {$row['avg_score']}, urgent {$row['urgent']}, warning {$row['warning']}";
        }

        if ($teacherHighlights !== []) {
            $lines[] = 'Teacher highlights:';
            foreach ($teacherHighlights as $row) {
                $change = $row['change'] ?? 'N/A';
                $lines[] = "- {$row['teacher_name']} ({$row['subject']}): avg {$row['avg_score']}, change {$change}";
            }
        }

        return $lines;
    }

    public function buildFullDataExport($snapshots): array
    {
        return $snapshots->map(function ($snapshot): array {
            $student = $snapshot->student;

            return [
                'student_code' => $student?->student_code,
                'student_name' => $student?->name,
                'class_name' => $student?->class_name,
                'section' => $student?->section,
                'roll_number' => $student?->roll_number,
                'guardian_name' => $student?->guardian_name,
                'guardian_phone' => $student?->guardian_phone,
                'guardian_email' => $student?->guardian_email,
                'family_status' => $student?->family_status,
                'economic_status' => $student?->economic_status,
                'scholarship_status' => $student?->scholarship_status,
                'private_tuition_subjects' => implode(', ', collect($student?->private_tuition_subjects ?? [])->map(fn ($row) => is_array($row) ? ($row['subject'] ?? '') : $row)->filter()->all()),
                'special_needs' => implode(', ', $student?->special_needs ?? []),
                'snapshot_period' => $snapshot->snapshot_period,
                'overall_score' => $snapshot->overall_score,
                'academic_score' => $snapshot->academic_score,
                'attendance_score' => $snapshot->attendance_score,
                'behavior_score' => $snapshot->behavior_score,
                'participation_score' => $snapshot->participation_score,
                'extracurricular_score' => $snapshot->extracurricular_score,
                'risk_score' => $snapshot->risk_score,
                'alert_level' => $snapshot->alert_level,
                'trend_direction' => $snapshot->trend_direction,
            ];
        })->all();
    }

    public function buildNotificationDigest($logs): array
    {
        $lines = ['PPS notification digest'];

        foreach ($logs as $log) {
            $studentName = $log->student?->name ? " | {$log->student->name}" : '';
            $lines[] = "{$log->type} | {$log->recipient_role} | {$log->channel}{$studentName} | {$log->subject}";
        }

        return $lines;
    }

    public function toCsv(array $headers, array $rows): string
    {
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, $headers);
        foreach ($rows as $row) {
            fputcsv($stream, $row);
        }
        rewind($stream);
        $csv = stream_get_contents($stream) ?: '';
        fclose($stream);

        return $csv;
    }

    public function rowsToCsv(array $rows): string
    {
        if ($rows === []) {
            return $this->toCsv([], []);
        }

        $headers = array_keys($rows[0]);

        return $this->toCsv(
            $headers,
            array_map(fn (array $row) => array_values($row), $rows)
        );
    }
}

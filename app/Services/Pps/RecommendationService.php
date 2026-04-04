<?php

namespace App\Services\Pps;

use App\Models\Pps\PerformanceSnapshot;
use App\Models\Student;

class RecommendationService
{
    public function forStudent(int $studentId, ?PerformanceSnapshot $snapshot): array
    {
        if (! $snapshot) {
            return [];
        }

        $recommendations = [];
        $subjectData = $snapshot->snapshot_data['subjects'] ?? [];
        $student = Student::query()->find($studentId);
        $subjectScores = collect($subjectData)->map(fn (array $data) => (float) ($data['avg'] ?? 0));

        foreach ($subjectData as $subject => $data) {
            $average = (float) ($data['avg'] ?? 0);

            if ($average < 45) {
                $recommendations[] = [
                    'type' => 'academic',
                    'priority' => 'high',
                    'text' => "{$subject} needs immediate intervention with targeted practice and direct teacher follow-up.",
                ];
            } elseif ($average < 60) {
                $recommendations[] = [
                    'type' => 'academic',
                    'priority' => 'medium',
                    'text' => "{$subject} should get a short weekly reinforcement plan before the gap widens.",
                ];
            }
        }

        if ($subjectScores->isNotEmpty()) {
            $strongest = collect($subjectData)->sortByDesc(fn (array $row) => $row['avg'] ?? 0)->keys()->first();
            $weakest = collect($subjectData)->sortBy(fn (array $row) => $row['avg'] ?? 0)->keys()->first();

            if ($strongest && $weakest && $strongest !== $weakest && ($subjectData[$strongest]['avg'] ?? 0) >= 75 && ($subjectData[$weakest]['avg'] ?? 0) < 60) {
                $recommendations[] = [
                    'type' => 'cross_subject',
                    'priority' => 'medium',
                    'text' => "Use the learning habits working in {$strongest} to support {$weakest}.",
                ];
            }
        }

        if ($snapshot->attendance_score < 75) {
            $recommendations[] = [
                'type' => 'attendance',
                'priority' => 'high',
                'text' => 'Attendance is materially affecting progress. A guardian conversation should happen this week.',
            ];
        }

        if ($snapshot->behavior_score < 60 && $snapshot->academic_score < 60) {
            $recommendations[] = [
                'type' => 'wellbeing',
                'priority' => 'high',
                'text' => 'Academic and behavior signals are both soft. A counselor check-in is warranted.',
            ];
        }

        if ($snapshot->trend_direction === 'up' && $snapshot->overall_score >= 75) {
            $recommendations[] = [
                'type' => 'positive',
                'priority' => 'low',
                'text' => 'Momentum is improving. Recognition from a teacher may help sustain it.',
            ];
        }

        if ($snapshot->extracurricular_score >= 80 && $snapshot->academic_score < 60) {
            $recommendations[] = [
                'type' => 'balance',
                'priority' => 'medium',
                'text' => 'The student is engaged beyond class but needs tighter academic balance and time structure.',
            ];
        }

        $tuitionSubjects = collect($student?->private_tuition_subjects ?? [])
            ->map(fn (mixed $row) => is_array($row) ? ($row['subject'] ?? null) : $row)
            ->filter()
            ->values();
        foreach ($tuitionSubjects as $tuitionSubject) {
            $tuitionAverage = (float) ($subjectData[$tuitionSubject]['avg'] ?? 0);
            if ($tuitionAverage > 0 && $tuitionAverage < 55) {
                $recommendations[] = [
                    'type' => 'tuition_effectiveness',
                    'priority' => 'medium',
                    'text' => "{$tuitionSubject} tuition is not converting into results yet. Review the tutor fit, hours, or practice quality.",
                ];
            }
        }

        if ($student && (! empty($student->special_needs) || $student->health_notes) && $snapshot->participation_score < 60) {
            $recommendations[] = [
                'type' => 'support_plan',
                'priority' => 'medium',
                'text' => 'The support plan should be adapted to current participation barriers and any documented health or learning needs.',
            ];
        }

        if ($snapshot->overall_score >= 85 && $snapshot->trend_direction === 'up') {
            $recommendations[] = [
                'type' => 'recognition',
                'priority' => 'low',
                'text' => 'The student has sustained strong momentum and should be considered for positive recognition.',
            ];
        }

        return $recommendations;
    }

    public function narrativeForStudent(?PerformanceSnapshot $snapshot): string
    {
        if (! $snapshot) {
            return 'No advisory brief is available until a monthly snapshot has been calculated.';
        }

        $messages = [];

        if ($snapshot->alert_level === 'urgent') {
            $messages[] = 'This case needs a short intervention cycle with named ownership within the week.';
        } elseif ($snapshot->alert_level === 'warning') {
            $messages[] = 'The pattern is recoverable, but the response should be structured rather than observational.';
        } else {
            $messages[] = 'Current signals are manageable and can be improved through steady classroom support.';
        }

        if ($snapshot->attendance_score < 80) {
            $messages[] = 'Attendance is currently the most direct lever and should be addressed first.';
        }

        if ($snapshot->academic_score < 60) {
            $messages[] = 'Instruction should narrow to the weakest subjects and shorten the feedback loop.';
        }

        if ($snapshot->participation_score < 60) {
            $messages[] = 'Participation is low enough that classroom confidence-building should be deliberate.';
        }

        return implode(' ', array_slice($messages, 0, 3));
    }
}

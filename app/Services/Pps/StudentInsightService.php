<?php

namespace App\Services\Pps;

use App\Models\Pps\Assessment;
use App\Models\Pps\CounselingSession;
use App\Models\Pps\PerformanceSnapshot;
use App\Models\Student;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class StudentInsightService
{
    public function buildAcademicProfile(Student $student, string $period, ?PerformanceSnapshot $snapshot): array
    {
        $periodDate = Carbon::createFromFormat('Y-m', $period);
        $subjects = collect($snapshot?->snapshot_data['subjects'] ?? []);
        $averageScore = $subjects->avg(fn (array $subject) => (float) ($subject['avg'] ?? 0));

        $gpa = $student->current_gpa ?? ($averageScore !== null ? $this->toGpa((float) $averageScore) : null);
        $grade = $student->current_grade ?? ($averageScore !== null ? $this->toGrade((float) $averageScore) : null);
        $classRank = $student->class_rank ?? $this->calculateClassRank($student, $period);

        return [
            'gpa' => $gpa !== null ? round((float) $gpa, 2) : null,
            'grade' => $grade,
            'class_rank' => $classRank,
            'subject_ranks' => $this->buildSubjectRanks($student, $subjects, $periodDate),
        ];
    }

    public function buildContext(Student $student, ?User $viewer): array
    {
        $viewerIsOwnerGuardian = $viewer?->hasAnyRole('guardian') && $viewer->isGuardianOf($student->id);
        $hasFullContext = $viewer?->hasAnyRole(['principal', 'admin', 'counselor']) || $viewerIsOwnerGuardian;

        $base = [
            'admission_date' => $student->admission_date?->toDateString(),
            'private_tuition_subjects' => $student->private_tuition_subjects ?? [],
            'private_tuition_notes' => $student->private_tuition_notes,
            'family_summary' => $this->buildFamilySummary($student),
            'special_circumstance' => $this->hasSensitiveContext($student),
        ];

        if (! $hasFullContext) {
            return $base + [
                'restricted' => true,
                'limited_message' => $this->hasSensitiveContext($student)
                    ? 'Sensitive home or health context exists. Coordinate with the counselor or principal for action planning.'
                    : 'No restricted home or health context is currently flagged.',
            ];
        }

        return $base + [
            'restricted' => false,
            'family_status' => $student->family_status,
            'economic_status' => $student->economic_status,
            'scholarship_status' => $student->scholarship_status,
            'health_notes' => $student->health_notes,
            'allergies' => $student->allergies,
            'medications' => $student->medications,
            'residence_change_note' => $student->residence_change_note,
            'special_needs' => $student->special_needs ?? [],
            'confidential_context' => $student->confidential_context,
        ];
    }

    public function buildWellbeing(Student $student, ?User $viewer): array
    {
        $latestSession = CounselingSession::query()
            ->where('student_id', $student->id)
            ->orderByDesc('session_date')
            ->first();

        $latestPsychometric = CounselingSession::query()
            ->where('student_id', $student->id)
            ->where('session_type', 'psychometric')
            ->orderByDesc('session_date')
            ->first();

        $isPrivileged = $viewer?->hasAnyRole(['principal', 'admin', 'counselor']) ?? false;
        $counselingActive = CounselingSession::query()
            ->where('student_id', $student->id)
            ->whereIn('progress_status', ['improving', 'stable', 'deteriorating'])
            ->exists();

        $summary = [
            'counseling_active' => $counselingActive,
            'last_session_date' => $latestSession?->session_date?->toDateString(),
            'status' => $this->wellbeingStatus($latestPsychometric),
            'status_message' => $this->wellbeingMessage($latestPsychometric, $counselingActive),
        ];

        if (! $isPrivileged) {
            return $summary;
        }

        $psychometricScores = $latestPsychometric?->psychometric_scores
            ?: $this->extractLegacyPsychometricScores($latestPsychometric);

        return $summary + [
            'score' => $this->psychometricComposite($psychometricScores),
            'assessment_tool' => $latestPsychometric?->assessment_tool,
            'psychometric_scores' => $psychometricScores,
            'special_needs_profile' => $latestPsychometric?->special_needs_profile ?? [],
            'progress_status' => $latestSession?->progress_status,
            'action_plan' => $latestSession?->action_plan,
        ];
    }

    public function buildTuitionAnalysis(Student $student, ?PerformanceSnapshot $snapshot): ?array
    {
        $tuitionSubjects = collect($student->private_tuition_subjects ?? [])
            ->map(function (mixed $subject): array {
                if (is_string($subject)) {
                    return ['subject' => $subject];
                }

                return [
                    'subject' => $subject['subject'] ?? null,
                    'hours_per_week' => $subject['hours_per_week'] ?? null,
                    'tutor_name' => $subject['tutor_name'] ?? null,
                ];
            })
            ->filter(fn (array $row) => ! empty($row['subject']))
            ->values();

        if ($tuitionSubjects->isEmpty() || ! $snapshot) {
            return null;
        }

        $period = $snapshot->snapshot_period;
        $classmates = Student::query()
            ->where('class_name', $student->class_name)
            ->where('section', $student->section)
            ->pluck('id');

        $results = $tuitionSubjects->map(function (array $entry) use ($classmates, $period, $snapshot): array {
            $subject = $entry['subject'];
            $studentAverage = (float) (($snapshot->snapshot_data['subjects'][$subject]['avg'] ?? 0));

            $classAverage = (float) (Assessment::query()
                ->whereIn('student_id', $classmates)
                ->where('subject', $subject)
                ->whereYear('exam_date', substr($period, 0, 4))
                ->whereMonth('exam_date', substr($period, 5, 2))
                ->avg('percentage') ?? 0);

            $effectiveness = match (true) {
                $studentAverage >= $classAverage + 5 => 'effective',
                $studentAverage >= max(40, $classAverage - 5) => 'mixed',
                default => 'ineffective',
            };

            return [
                'subject' => $subject,
                'hours_per_week' => $entry['hours_per_week'] ?? null,
                'tutor_name' => $entry['tutor_name'] ?? null,
                'student_average' => round($studentAverage, 1),
                'class_average' => round($classAverage, 1),
                'effectiveness' => $effectiveness,
                'summary' => $this->tuitionSummary($subject, $studentAverage, $classAverage, $effectiveness),
            ];
        })->all();

        return [
            'tracked' => count($results),
            'subjects' => $results,
        ];
    }

    private function buildSubjectRanks(Student $student, Collection $subjects, Carbon $periodDate): array
    {
        if ($subjects->isEmpty()) {
            return [];
        }

        $classmates = Student::query()
            ->where('class_name', $student->class_name)
            ->where('section', $student->section)
            ->pluck('id');

        return $subjects->map(function (array $data, string $subject) use ($student, $classmates, $periodDate): array {
            $averages = Assessment::query()
                ->whereIn('student_id', $classmates)
                ->where('subject', $subject)
                ->whereYear('exam_date', $periodDate->year)
                ->whereMonth('exam_date', $periodDate->month)
                ->groupBy('student_id')
                ->selectRaw('student_id, AVG(percentage) as avg_pct')
                ->orderByDesc('avg_pct')
                ->get();

            $rank = $averages->search(fn ($row) => (int) $row->student_id === $student->id);

            return [
                'subject' => $subject,
                'rank' => $rank === false ? null : $rank + 1,
                'cohort_size' => $averages->count(),
                'average' => round((float) ($data['avg'] ?? 0), 1),
            ];
        })->values()->all();
    }

    private function calculateClassRank(Student $student, string $period): ?int
    {
        $ranked = PerformanceSnapshot::query()
            ->forPeriod($period)
            ->join('students', 'students.id', '=', 'pps_performance_snapshots.student_id')
            ->where('students.class_name', $student->class_name)
            ->where('students.section', $student->section)
            ->orderByDesc('overall_score')
            ->pluck('pps_performance_snapshots.student_id')
            ->values();

        $position = $ranked->search($student->id);

        return $position === false ? null : $position + 1;
    }

    private function hasSensitiveContext(Student $student): bool
    {
        return (bool) (
            $student->family_status ||
            $student->economic_status ||
            $student->health_notes ||
            $student->residence_change_note ||
            ! empty($student->special_needs) ||
            $student->confidential_context
        );
    }

    private function buildFamilySummary(Student $student): ?string
    {
        $parts = collect([
            $student->family_status,
            $student->economic_status,
            $student->scholarship_status,
        ])->filter()->values();

        return $parts->isEmpty() ? null : $parts->implode(' | ');
    }

    private function extractLegacyPsychometricScores(?CounselingSession $session): array
    {
        if (! $session || ! $session->session_notes) {
            return [];
        }

        $decoded = json_decode($session->session_notes, true);
        if (! is_array($decoded)) {
            return [];
        }

        return collect($decoded)
            ->only(['self_confidence', 'anxiety_level', 'social_skills', 'emotional_regulation', 'notes'])
            ->filter(fn ($value, $key) => $value !== null || $key === 'notes')
            ->all();
    }

    private function psychometricComposite(array $scores): ?float
    {
        if ($scores === []) {
            return null;
        }

        $metrics = collect([
            $scores['self_confidence'] ?? null,
            isset($scores['anxiety_level']) ? 100 - (float) $scores['anxiety_level'] : null,
            $scores['social_skills'] ?? null,
            $scores['emotional_regulation'] ?? null,
        ])->filter(fn ($value) => $value !== null);

        if ($metrics->isEmpty()) {
            return null;
        }

        return round((float) $metrics->avg(), 1);
    }

    private function wellbeingStatus(?CounselingSession $session): string
    {
        $score = $this->psychometricComposite(
            $session?->psychometric_scores ?: $this->extractLegacyPsychometricScores($session)
        );

        return match (true) {
            $score === null => 'unknown',
            $score >= 75 => 'healthy',
            $score >= 55 => 'monitor',
            default => 'needs_support',
        };
    }

    private function wellbeingMessage(?CounselingSession $session, bool $counselingActive): string
    {
        $status = $this->wellbeingStatus($session);

        if ($counselingActive && $status === 'needs_support') {
            return 'Support is active and wellbeing indicators still need close follow-up.';
        }

        return match ($status) {
            'healthy' => 'Recent wellbeing indicators are healthy.',
            'monitor' => 'Recent wellbeing indicators are mixed and should be monitored.',
            'needs_support' => 'Recent wellbeing indicators suggest the student needs structured support.',
            default => $counselingActive
                ? 'Counseling support is active, but there is not enough psychometric history yet.'
                : 'No recent psychometric record is available.',
        };
    }

    private function tuitionSummary(string $subject, float $studentAverage, float $classAverage, string $effectiveness): string
    {
        return match ($effectiveness) {
            'effective' => "{$subject} tuition appears effective. The student is performing above the class benchmark.",
            'mixed' => "{$subject} tuition is producing limited separation from the class average and should be reviewed.",
            default => "{$subject} tuition is not translating into results yet. Review hours, teaching approach, or fit.",
        };
    }

    private function toGpa(float $score): float
    {
        return match (true) {
            $score >= 80 => 5.0,
            $score >= 70 => 4.0,
            $score >= 60 => 3.5,
            $score >= 50 => 3.0,
            $score >= 40 => 2.0,
            $score >= 33 => 1.0,
            default => 0.0,
        };
    }

    private function toGrade(float $score): string
    {
        return match (true) {
            $score >= 80 => 'A+',
            $score >= 70 => 'A',
            $score >= 60 => 'A-',
            $score >= 50 => 'B',
            $score >= 40 => 'C',
            $score >= 33 => 'D',
            default => 'F',
        };
    }
}

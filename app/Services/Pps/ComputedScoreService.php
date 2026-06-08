<?php
namespace App\Services\Pps;

use App\Models\Pps\ComputedScore;
use App\Models\Pps\ExamComponent;
use App\Models\Pps\Mark;

class ComputedScoreService
{
    public function recompute(int $examId, int $studentId, int $subjectId): void
    {
        $components = ExamComponent::where('exam_id', $examId)->get(['id', 'max_raw_marks', 'max_contribution']);

        if ($components->isEmpty()) {
            return;
        }

        $componentIds = $components->pluck('id');
        $marks = Mark::whereIn('component_id', $componentIds)
            ->where('student_id', $studentId)
            ->where('subject_id', $subjectId)
            ->get(['component_id', 'marks_obtained'])
            ->keyBy('component_id');

        $totalObtained = 0.0;
        $totalPossible = 0.0;

        foreach ($components as $component) {
            $totalPossible += $component->max_contribution;
            $mark = $marks->get($component->id);
            if ($mark) {
                $contribution = ($mark->marks_obtained / $component->max_raw_marks) * $component->max_contribution;
                $totalObtained += $contribution;
            }
        }

        $percentage = $totalPossible > 0 ? round(($totalObtained / $totalPossible) * 100, 2) : 0;
        [$grade, $gp] = $this->gradeFromPercentage($percentage);

        ComputedScore::updateOrCreate(
            ['exam_id' => $examId, 'student_id' => $studentId, 'subject_id' => $subjectId],
            [
                'total_obtained' => round($totalObtained, 2),
                'total_possible' => round($totalPossible, 2),
                'percentage'     => $percentage,
                'letter_grade'   => $grade,
                'grade_point'    => $gp,
                'computed_at'    => now(),
            ]
        );
    }

    public function recomputeForExamSubject(int $examId, int $subjectId): void
    {
        $componentIds = ExamComponent::where('exam_id', $examId)->pluck('id');

        $studentIds = Mark::whereIn('component_id', $componentIds)
            ->where('subject_id', $subjectId)
            ->distinct()
            ->pluck('student_id');

        foreach ($studentIds as $studentId) {
            $this->recompute($examId, $studentId, $subjectId);
        }
    }

    private function gradeFromPercentage(float $pct): array
    {
        return match (true) {
            $pct >= 80 => ['A+', 5.00],
            $pct >= 70 => ['A',  4.00],
            $pct >= 60 => ['A-', 3.50],
            $pct >= 50 => ['B',  3.00],
            $pct >= 40 => ['C',  2.00],
            $pct >= 33 => ['D',  1.00],
            default    => ['F',  0.00],
        };
    }
}

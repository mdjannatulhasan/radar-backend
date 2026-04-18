<?php

namespace App\Services\Pps;

use App\Models\Pps\GradeConfig;
use Illuminate\Support\Collection;

class GradeCalculatorService
{
    private ?Collection $gradeTable = null;

    /**
     * Resolve letter grade and grade point from a percentage.
     * Uses DB-stored grade config (school_id NULL = system default).
     *
     * @return array{letter_grade: string, grade_point: float}
     */
    public function resolve(float $percentage, ?int $schoolId = null): array
    {
        $table = $this->loadGradeTable($schoolId);

        foreach ($table as $row) {
            if ($percentage >= $row->min_pct && $percentage <= $row->max_pct) {
                return [
                    'letter_grade' => $row->letter_grade,
                    'grade_point'  => (float) $row->grade_point,
                ];
            }
        }

        // Fallback — should not occur if grade config is complete
        return ['letter_grade' => 'F', 'grade_point' => 0.00];
    }

    /**
     * Calculate GPA from a list of grade points.
     * If any core subject has grade_point = 0 (F), overall GPA = 0.
     *
     * @param array<array{grade_point: float, is_core: bool}> $subjects
     */
    public function calculateGpa(array $subjects): float
    {
        if (empty($subjects)) {
            return 0.00;
        }

        foreach ($subjects as $subject) {
            if ($subject['is_core'] && $subject['grade_point'] <= 0) {
                return 0.00;
            }
        }

        $total = array_sum(array_column($subjects, 'grade_point'));

        return round($total / count($subjects), 2);
    }

    private function loadGradeTable(?int $schoolId): Collection
    {
        if ($this->gradeTable !== null) {
            return $this->gradeTable;
        }

        // Prefer school-specific config; fall back to system default (school_id NULL)
        $rows = GradeConfig::query()
            ->where(function ($q) use ($schoolId): void {
                $q->whereNull('school_id');
                if ($schoolId !== null) {
                    $q->orWhere('school_id', $schoolId);
                }
            })
            ->orderByRaw('school_id IS NULL ASC') // school-specific takes priority
            ->orderBy('sort_order')
            ->get();

        $this->gradeTable = $rows;

        return $this->gradeTable;
    }
}

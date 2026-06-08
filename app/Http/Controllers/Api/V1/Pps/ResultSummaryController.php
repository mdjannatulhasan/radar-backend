<?php

namespace App\Http\Controllers\Api\V1\Pps;

use App\Http\Controllers\Controller;
use App\Models\Pps\ComputedScore;
use App\Models\Pps\Exam;
use App\Models\Pps\ExamComponent;
use App\Models\Pps\Mark;
use App\Models\Student;
use App\Services\Pps\ComputedScoreService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ResultSummaryController extends Controller
{
    public function __construct(
        private readonly ComputedScoreService $scorer,
    ) {
    }

    /**
     * GET /v1/pps/results/summary?exam_id=
     * Returns per-student aggregate rows from pps_computed_scores.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'exam_id' => ['required', 'exists:pps_exams,id'],
        ]);

        $rows = DB::table('pps_computed_scores as cs')
            ->join('students as s', 's.id', '=', 'cs.student_id')
            ->where('cs.exam_id', $data['exam_id'])
            ->groupBy('cs.student_id', 's.id', 's.name', 's.roll_number')
            ->selectRaw(
                'cs.student_id,
                 s.name,
                 s.roll_number,
                 SUM(cs.total_obtained) as total_obtained,
                 SUM(cs.total_possible) as total_possible,
                 CASE WHEN SUM(cs.total_possible) > 0
                      THEN ROUND(SUM(cs.total_obtained) / SUM(cs.total_possible) * 100, 2)
                      ELSE 0 END as percentage'
            )
            ->orderBy('s.roll_number')
            ->get();

        // Attach letter_grade and grade_point from the first computed score for that student
        // (they share the same grading scale so we can derive from overall percentage)
        $result = $rows->map(function ($row): array {
            $pct = (float) $row->percentage;
            [$grade, $gp] = $this->gradeFromPercentage($pct);

            return [
                'student_id'     => $row->student_id,
                'name'           => $row->name,
                'roll_number'    => $row->roll_number,
                'total_obtained' => round((float) $row->total_obtained, 2),
                'total_possible' => round((float) $row->total_possible, 2),
                'percentage'     => $pct,
                'letter_grade'   => $grade,
                'grade_point'    => $gp,
            ];
        });

        return response()->json(['data' => $result]);
    }

    /**
     * POST /v1/pps/results/compute
     * Body: { exam_id }
     * Recomputes ComputedScore for every (student, subject) combo in the exam.
     */
    public function compute(Request $request): JsonResponse
    {
        $data = $request->validate([
            'exam_id' => ['required', 'exists:pps_exams,id'],
        ]);

        $examId = (int) $data['exam_id'];

        // Find all student+subject combos that have marks for this exam
        $componentIds = ExamComponent::where('exam_id', $examId)->pluck('id');

        $combos = Mark::whereIn('component_id', $componentIds)
            ->select('student_id', 'subject_id')
            ->distinct()
            ->get();

        $count = 0;
        foreach ($combos as $combo) {
            $this->scorer->recompute($examId, $combo->student_id, $combo->subject_id);
            $count++;
        }

        return response()->json(['computed' => $count]);
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

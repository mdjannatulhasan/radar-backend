<?php

namespace App\Http\Controllers\Api\V1\Pps;

use App\Http\Controllers\Controller;
use App\Models\Pps\ExamComponent;
use App\Models\Pps\Mark;
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
     * GET /v1/pps/results/meta?exam_id=
     * Returns available classes, sections per class, and subjects per class for filter dropdowns.
     */
    public function meta(Request $request): JsonResponse
    {
        $data = $request->validate([
            'exam_id' => ['required', 'exists:pps_exams,id'],
        ]);

        $examId = (int) $data['exam_id'];

        $rows = DB::table('pps_computed_scores as cs')
            ->join('students as s', 's.id', '=', 'cs.student_id')
            ->join('pps_subjects as sub', 'sub.id', '=', 'cs.subject_id')
            ->where('cs.exam_id', $examId)
            ->select('s.class_name', 's.section', 'sub.id as subject_id', 'sub.name as subject_name')
            ->distinct()
            ->get();

        $classes = $rows->pluck('class_name')->filter()->unique()->sort()->values();

        $sections = [];
        $subjects = [];
        foreach ($rows as $row) {
            if (!$row->class_name) continue;
            $sections[$row->class_name][] = $row->section;
            $subjects[$row->class_name][] = ['id' => $row->subject_id, 'name' => $row->subject_name];
        }

        foreach ($sections as $cls => $secs) {
            $sections[$cls] = collect($secs)->filter()->unique()->sort()->values()->all();
        }
        foreach ($subjects as $cls => $subs) {
            $subjects[$cls] = collect($subs)
                ->unique('id')
                ->sortBy('name')
                ->values()
                ->all();
        }

        return response()->json([
            'classes'  => $classes,
            'sections' => $sections,
            'subjects' => $subjects,
        ]);
    }

    /**
     * GET /v1/pps/results/summary?exam_id=&class_name=&section=&subject_id=
     * class_name is required. section and subject_id are optional.
     * When no subject_id: returns per-student rows with all subjects as columns.
     * When subject_id: returns per-student rows for that subject only.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'exam_id'    => ['required', 'exists:pps_exams,id'],
            'class_name' => ['required', 'string'],
            'section'    => ['nullable', 'string'],
            'subject_id' => ['nullable', 'integer', 'exists:pps_subjects,id'],
        ]);

        $examId    = (int) $data['exam_id'];
        $className = $data['class_name'];
        $section   = $data['section'] ?? null;
        $subjectId = isset($data['subject_id']) ? (int) $data['subject_id'] : null;

        $query = DB::table('pps_computed_scores as cs')
            ->join('students as s', 's.id', '=', 'cs.student_id')
            ->join('pps_subjects as sub', 'sub.id', '=', 'cs.subject_id')
            ->where('cs.exam_id', $examId)
            ->where('s.class_name', $className);

        if ($section) {
            $query->where('s.section', $section);
        }
        if ($subjectId) {
            $query->where('cs.subject_id', $subjectId);
        }

        $rawRows = $query
            ->select(
                'cs.student_id',
                's.name',
                's.roll_number',
                's.student_code',
                's.class_name',
                's.section',
                'cs.subject_id',
                'sub.name as subject_name',
                'cs.total_obtained',
                'cs.total_possible',
                'cs.letter_grade',
                'cs.grade_point'
            )
            ->orderBy('s.roll_number')
            ->get();

        // Group by student
        $byStudent = $rawRows->groupBy('student_id');

        $result = $byStudent->map(function ($rows) {
            $first = $rows->first();
            $totalObtained = $rows->sum(fn ($r) => (float) $r->total_obtained);
            $totalPossible = $rows->sum(fn ($r) => (float) $r->total_possible);
            $pct = $totalPossible > 0 ? $totalObtained / $totalPossible * 100 : 0;
            [$grade, $gp] = $this->gradeFromPercentage($pct);

            $subjectCols = $rows->map(fn ($r) => [
                'subject_id'   => $r->subject_id,
                'subject_name' => $r->subject_name,
                'obtained'     => round((float) $r->total_obtained, 2),
                'possible'     => round((float) $r->total_possible, 2),
                'grade'        => $r->letter_grade,
            ])->sortBy('subject_name')->values()->all();

            return [
                'student_id'           => $first->student_id,
                'total_marks_obtained' => round($totalObtained, 2),
                'total_marks_full'     => round($totalPossible, 2),
                'gpa'                  => $gp,
                'letter_grade'         => $grade,
                'subjects'             => $subjectCols,
                'student'              => [
                    'id'           => $first->student_id,
                    'name'         => $first->name,
                    'roll_number'  => $first->roll_number,
                    'student_code' => $first->student_code,
                    'class_name'   => $first->class_name,
                    'section'      => $first->section,
                ],
            ];
        });

        // Sort by total obtained desc, assign positions
        $sorted = $result->sortByDesc('total_marks_obtained')->values()->map(function ($row, int $i) {
            $row['class_position'] = $i + 1;
            return $row;
        });

        return response()->json(['data' => $sorted]);
    }

    /**
     * POST /v1/pps/results/compute
     */
    public function compute(Request $request): JsonResponse
    {
        $data = $request->validate([
            'exam_id' => ['required', 'exists:pps_exams,id'],
        ]);

        $examId = (int) $data['exam_id'];

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

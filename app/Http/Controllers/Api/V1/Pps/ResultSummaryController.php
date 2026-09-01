<?php

namespace App\Http\Controllers\Api\V1\Pps;

use App\Http\Controllers\Controller;
use App\Models\Pps\ExamComponent;
use App\Models\Pps\Mark;
use App\Services\Pps\ComputedScoreService;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
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
     * Join a computed-scores query out to the class and section a student sits
     * in. students.class_name / students.section are gone; the only route is
     * the CURRENT academic year's enrollment -> section -> class_level, so the
     * two strings the results screen filters on are now `cl.name` and `sn.name`.
     *
     * student_enrollments is unique on (student_id, academic_year_id) and only
     * one academic year is current, so this join cannot fan a student out into
     * several rows. A student with no current enrollment drops out of the
     * result set — they have no class to be ranked within.
     */
    private function scoresWithTaxonomy(int $examId): Builder
    {
        return DB::table('pps_computed_scores as cs')
            ->join('students as s', 's.id', '=', 'cs.student_id')
            ->join('subjects as sub', 'sub.id', '=', 'cs.subject_id')
            ->join('student_enrollments as se', 'se.student_id', '=', 's.id')
            ->join('academic_years as ay', function (JoinClause $join): void {
                $join->on('ay.id', '=', 'se.academic_year_id')->where('ay.is_current', true);
            })
            ->join('sections as sec', 'sec.id', '=', 'se.section_id')
            ->join('class_levels as cl', 'cl.id', '=', 'sec.class_level_id')
            ->join('section_names as sn', 'sn.id', '=', 'sec.section_name_id')
            ->where('cs.exam_id', $examId);
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

        $rows = $this->scoresWithTaxonomy($examId)
            ->select(
                'cl.name as class_name',
                'sn.name as section',
                'sub.id as subject_id',
                // subjects has no `name` column — full_name behind the same key.
                'sub.full_name as subject_name'
            )
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
            'subject_id' => ['nullable', 'integer', 'exists:subjects,id'],
        ]);

        $examId    = (int) $data['exam_id'];
        $className = $data['class_name'];
        $section   = $data['section'] ?? null;
        $subjectId = isset($data['subject_id']) ? (int) $data['subject_id'] : null;

        $query = $this->scoresWithTaxonomy($examId)->where('cl.name', $className);

        if ($section) {
            $query->where('sn.name', $section);
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
                'cl.name as class_name',
                'sn.name as section',
                'cs.subject_id',
                'sub.full_name as subject_name',
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

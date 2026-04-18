<?php

namespace App\Http\Controllers\Api\V1\Pps;

use App\Http\Controllers\Controller;
use App\Models\Pps\ExamDefinition;
use App\Models\Pps\Subject;
use App\Models\Pps\TermMark;
use App\Models\Student;
use App\Services\Pps\ConMarksService;
use App\Services\Pps\GradeCalculatorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TermMarksController extends Controller
{
    public function __construct(
        private readonly ConMarksService $con,
        private readonly GradeCalculatorService $grader,
    ) {
    }

    /**
     * GET /v1/pps/marks/term?exam_id=&subject_id=
     * Returns all students for the exam's class+section with their current marks (if any).
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'exam_id'    => ['required', 'exists:pps_exam_definitions,id'],
            'subject_id' => ['required', 'exists:pps_subjects,id'],
        ]);

        $exam = ExamDefinition::query()->findOrFail($data['exam_id']);

        $query = Student::query()->where('class_name', $exam->class_name);
        if ($exam->section !== null) {
            $query->where('section', $exam->section);
        }
        $students = $query->orderBy('roll_number')->get(['id', 'name', 'roll_number', 'student_code']);

        $existing = TermMark::query()
            ->where('exam_id', $data['exam_id'])
            ->where('subject_id', $data['subject_id'])
            ->get()
            ->keyBy('student_id');

        $rows = $students->map(fn (Student $s) => [
            'student_id'      => $s->id,
            'name'            => $s->name,
            'roll_number'     => $s->roll_number,
            'student_code'    => $s->student_code,
            'marks'           => $existing->get($s->id),
        ]);

        return response()->json([
            'exam'    => $exam,
            'subject' => Subject::query()->find($data['subject_id'], ['id', 'name', 'code']),
            'rows'    => $rows,
        ]);
    }

    /**
     * POST /v1/pps/marks/term
     * Bulk upsert marks for all students in one grid submission.
     *
     * Body: {
     *   exam_id, subject_id, is_second_term,
     *   rows: [{ student_id, spot_test, class_test2, attendance, term_marks, vt }]
     * }
     */
    public function bulkStore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'exam_id'        => ['required', 'exists:pps_exam_definitions,id'],
            'subject_id'     => ['required', 'exists:pps_subjects,id'],
            'is_second_term' => ['required', 'boolean'],
            'rows'           => ['required', 'array', 'min:1'],
            'rows.*.student_id'  => ['required', 'exists:students,id'],
            'rows.*.spot_test'   => ['nullable', 'numeric', 'min:0', 'max:10'],
            'rows.*.class_test2' => ['nullable', 'numeric', 'min:0', 'max:20'],
            'rows.*.attendance'  => ['nullable', 'numeric', 'min:0', 'max:5'],
            'rows.*.term_marks'  => ['nullable', 'numeric', 'min:0', 'max:100'],
            'rows.*.vt'          => ['nullable', 'numeric', 'min:0', 'max:25'],
        ]);

        $isT2      = (bool) $data['is_second_term'];
        $enteredBy = $request->user()?->id;
        $saved     = [];

        DB::transaction(function () use ($data, $isT2, $enteredBy, &$saved): void {
            foreach ($data['rows'] as $row) {
                $raw = [
                    'spot_test'   => $row['spot_test']   ?? null,
                    'class_test2' => $row['class_test2'] ?? null,
                    'attendance'  => $row['attendance']  ?? null,
                    'term_marks'  => $row['term_marks']  ?? null,
                    'vt'          => $row['vt']          ?? null,
                ];

                $computed = $this->con->computeTermCon($raw, $isT2);

                // Grade is based on total_obtained / full marks
                // Full marks for a term = 100 (max possible total)
                $grade = ['letter_grade' => null, 'grade_point' => null];
                if ($computed['total_obtained'] !== null) {
                    $grade = $this->grader->resolve($computed['total_obtained']); // total IS the percentage here
                }

                $record = TermMark::query()->updateOrCreate(
                    [
                        'exam_id'    => $data['exam_id'],
                        'student_id' => $row['student_id'],
                        'subject_id' => $data['subject_id'],
                    ],
                    array_merge($raw, $computed, $grade, ['entered_by' => $enteredBy])
                );

                $saved[] = $record;
            }

            // Update highest_marks for every saved row in this exam+subject
            $this->updateHighestMarks($data['exam_id'], $data['subject_id']);
        });

        return response()->json(['saved' => count($saved), 'rows' => $saved]);
    }

    private function updateHighestMarks(int $examId, int $subjectId): void
    {
        $max = TermMark::query()
            ->where('exam_id', $examId)
            ->where('subject_id', $subjectId)
            ->max('total_obtained');

        if ($max !== null) {
            TermMark::query()
                ->where('exam_id', $examId)
                ->where('subject_id', $subjectId)
                ->update(['highest_marks' => $max]);
        }
    }
}

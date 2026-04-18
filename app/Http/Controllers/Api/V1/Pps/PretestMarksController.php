<?php

namespace App\Http\Controllers\Api\V1\Pps;

use App\Http\Controllers\Controller;
use App\Models\Pps\ExamDefinition;
use App\Models\Pps\PretestMark;
use App\Models\Pps\Subject;
use App\Models\Student;
use App\Services\Pps\ConMarksService;
use App\Services\Pps\GradeCalculatorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PretestMarksController extends Controller
{
    public function __construct(
        private readonly ConMarksService $con,
        private readonly GradeCalculatorService $grader,
    ) {
    }

    /**
     * GET /v1/pps/marks/pretest?exam_id=&subject_id=
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
        $students = $query->orderBy('roll_number')->get(['id', 'name', 'roll_number', 'student_code', 'stream_id']);

        $existing = PretestMark::query()
            ->where('exam_id', $data['exam_id'])
            ->where('subject_id', $data['subject_id'])
            ->get()
            ->keyBy('student_id');

        $rows = $students->map(fn (Student $s) => [
            'student_id'   => $s->id,
            'name'         => $s->name,
            'roll_number'  => $s->roll_number,
            'student_code' => $s->student_code,
            'stream_id'    => $s->stream_id,
            'marks'        => $existing->get($s->id),
        ]);

        return response()->json([
            'exam'    => $exam,
            'subject' => Subject::query()->find($data['subject_id'], ['id', 'name', 'code']),
            'rows'    => $rows,
        ]);
    }

    /**
     * POST /v1/pps/marks/pretest
     *
     * Body: {
     *   exam_id, subject_id,
     *   rows: [{ student_id, ct, attendance, cq, mcq }]
     * }
     */
    public function bulkStore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'exam_id'            => ['required', 'exists:pps_exam_definitions,id'],
            'subject_id'         => ['required', 'exists:pps_subjects,id'],
            'rows'               => ['required', 'array', 'min:1'],
            'rows.*.student_id'  => ['required', 'exists:students,id'],
            'rows.*.ct'          => ['nullable', 'numeric', 'min:0'],
            'rows.*.attendance'  => ['nullable', 'numeric', 'min:0', 'max:5'],
            'rows.*.cq'          => ['nullable', 'numeric', 'min:0'],
            'rows.*.mcq'         => ['nullable', 'numeric', 'min:0'],
        ]);

        $enteredBy = $request->user()?->id;
        $saved     = [];

        DB::transaction(function () use ($data, $enteredBy, &$saved): void {
            foreach ($data['rows'] as $row) {
                $raw = [
                    'ct'         => $row['ct']         ?? null,
                    'attendance' => $row['attendance'] ?? null,
                    'cq'         => $row['cq']         ?? null,
                    'mcq'        => $row['mcq']        ?? null,
                ];

                $computed = $this->con->computePretestCon($raw);

                $grade = ['letter_grade' => null, 'grade_point' => null];
                if ($computed['total_obtained'] !== null) {
                    $grade = $this->grader->resolve($computed['total_obtained']);
                }

                $record = PretestMark::query()->updateOrCreate(
                    [
                        'exam_id'    => $data['exam_id'],
                        'student_id' => $row['student_id'],
                        'subject_id' => $data['subject_id'],
                    ],
                    array_merge($raw, $computed, $grade, ['entered_by' => $enteredBy])
                );

                $saved[] = $record;
            }

            $this->updateHighestMarks($data['exam_id'], $data['subject_id']);
        });

        return response()->json(['saved' => count($saved), 'rows' => $saved]);
    }

    private function updateHighestMarks(int $examId, int $subjectId): void
    {
        $max = PretestMark::query()
            ->where('exam_id', $examId)
            ->where('subject_id', $subjectId)
            ->max('total_obtained');

        if ($max !== null) {
            PretestMark::query()
                ->where('exam_id', $examId)
                ->where('subject_id', $subjectId)
                ->update(['highest_marks' => $max]);
        }
    }
}

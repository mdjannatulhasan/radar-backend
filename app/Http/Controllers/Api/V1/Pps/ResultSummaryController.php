<?php

namespace App\Http\Controllers\Api\V1\Pps;

use App\Http\Controllers\Controller;
use App\Models\Pps\ExamDefinition;
use App\Models\Pps\PretestMark;
use App\Models\Pps\ResultSummary;
use App\Models\Pps\TermMark;
use App\Models\Student;
use App\Services\Pps\GradeCalculatorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ResultSummaryController extends Controller
{
    public function __construct(
        private readonly GradeCalculatorService $grader,
    ) {
    }

    /**
     * GET /v1/pps/results/summary?exam_id=&class_name=&section=
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'exam_id' => ['required', 'exists:pps_exam_definitions,id'],
        ]);

        $summaries = ResultSummary::query()
            ->where('exam_id', $data['exam_id'])
            ->with('student:id,name,roll_number,student_code,class_name,section')
            ->orderBy('class_position')
            ->get();

        return response()->json(['data' => $summaries]);
    }

    /**
     * POST /v1/pps/results/compute
     * Compute and store GPA + result summary for all students in an exam.
     *
     * Body: { exam_id }
     */
    public function compute(Request $request): JsonResponse
    {
        $data = $request->validate([
            'exam_id' => ['required', 'exists:pps_exam_definitions,id'],
        ]);

        $exam = ExamDefinition::query()->findOrFail($data['exam_id']);
        $isPretest = str_contains(strtolower($exam->assessment_type ?? ''), 'pretest')
            || (int) $exam->class_name >= 11;

        $query = Student::query()->where('class_name', $exam->class_name);
        if ($exam->section !== null) {
            $query->where('section', $exam->section);
        }
        $students = $query->pluck('id');

        $computedBy = $request->user()?->id;
        $results = [];

        DB::transaction(function () use ($data, $students, $isPretest, $computedBy, &$results): void {
            foreach ($students as $studentId) {
                $summary = $isPretest
                    ? $this->computeFromPretest($data['exam_id'], $studentId)
                    : $this->computeFromTermMarks($data['exam_id'], $studentId);

                $record = ResultSummary::query()->updateOrCreate(
                    ['exam_id' => $data['exam_id'], 'student_id' => $studentId],
                    array_merge($summary, [
                        'computed_at' => now(),
                        'computed_by' => $computedBy,
                    ])
                );

                $results[] = $record;
            }

            // Compute class positions based on total_marks_obtained descending
            $this->updateClassPositions($data['exam_id'], count($students));
        });

        return response()->json(['computed' => count($results)]);
    }

    private function computeFromTermMarks(int $examId, int $studentId): array
    {
        $marks = TermMark::query()
            ->where('exam_id', $examId)
            ->where('student_id', $studentId)
            ->get();

        if ($marks->isEmpty()) {
            return $this->emptyResult();
        }

        $subjectData = $marks->map(fn (TermMark $m) => [
            'grade_point' => $m->grade_point ?? 0.0,
            'is_core'     => true, // all subjects treated as core unless flagged otherwise
        ])->all();

        $gpa   = $this->grader->calculateGpa($subjectData);
        $total = $marks->sum('total_obtained');
        $full  = $marks->count() * 100; // 100 per subject

        $gradeResult = $full > 0 ? $this->grader->resolve(($total / $full) * 100) : ['letter_grade' => 'F', 'grade_point' => 0];

        return [
            'total_marks_obtained' => $total,
            'total_marks_full'     => $full,
            'gpa'                  => $gpa,
            'letter_grade'         => $gradeResult['letter_grade'],
        ];
    }

    private function computeFromPretest(int $examId, int $studentId): array
    {
        $marks = PretestMark::query()
            ->where('exam_id', $examId)
            ->where('student_id', $studentId)
            ->get();

        if ($marks->isEmpty()) {
            return $this->emptyResult();
        }

        $subjectData = $marks->map(fn (PretestMark $m) => [
            'grade_point' => $m->grade_point ?? 0.0,
            'is_core'     => true,
        ])->all();

        $gpa   = $this->grader->calculateGpa($subjectData);
        $total = $marks->sum('total_obtained');
        $full  = $marks->count() * 100;

        $gradeResult = $full > 0 ? $this->grader->resolve(($total / $full) * 100) : ['letter_grade' => 'F', 'grade_point' => 0];

        return [
            'total_marks_obtained' => $total,
            'total_marks_full'     => $full,
            'gpa'                  => $gpa,
            'letter_grade'         => $gradeResult['letter_grade'],
        ];
    }

    private function updateClassPositions(int $examId, int $totalStudents): void
    {
        $rows = ResultSummary::query()
            ->where('exam_id', $examId)
            ->orderByDesc('total_marks_obtained')
            ->pluck('id');

        foreach ($rows as $rank => $id) {
            ResultSummary::query()->where('id', $id)->update([
                'class_position'          => $rank + 1,
                'total_students_in_class' => $totalStudents,
            ]);
        }
    }

    private function emptyResult(): array
    {
        return [
            'total_marks_obtained' => null,
            'total_marks_full'     => null,
            'gpa'                  => null,
            'letter_grade'         => null,
        ];
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Pps;

use App\Http\Controllers\Controller;
use App\Models\Pps\ExamDefinition;
use App\Models\Pps\ExamScope;
use App\Models\Pps\Subject;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Simple assessment marks grid — reads/writes pps_assessments.
 * Used for exam types other than mid_term (quiz, class_test, spot_test, assessment_test, final).
 */
class AssessmentMarksController extends Controller
{
    /**
     * GET /v1/pps/marks/assessment?exam_id=&subject_id=
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'exam_id'    => ['required', 'exists:pps_exam_definitions,id'],
            'subject_id' => ['required', 'exists:pps_subjects,id'],
        ]);

        $exam = ExamDefinition::query()->findOrFail($data['exam_id']);

        $scope = ExamScope::query()
            ->where('exam_id', $data['exam_id'])
            ->where('subject_id', $data['subject_id'])
            ->first();

        $className = $scope?->class_name ?? null;
        $section   = $scope?->section   ?? null;

        $query = Student::query();
        if ($className) {
            $query->where('class_name', $className);
        }
        if ($section !== null) {
            $query->where('section', $section);
        }
        $students = $query->orderBy('roll_number')->get(['id', 'name', 'roll_number', 'student_code']);

        $subject = Subject::query()->find($data['subject_id'], ['id', 'name', 'code']);

        // Existing marks from pps_assessments
        $existing = DB::table('pps_assessments')
            ->whereIn('student_id', $students->pluck('id'))
            ->where('subject', $subject->name)
            ->where('assessment_type', $exam->assessment_type)
            ->where('term', $exam->term)
            ->get()
            ->keyBy('student_id');

        $rows = $students->map(fn (Student $s) => [
            'student_id'    => $s->id,
            'name'          => $s->name,
            'roll_number'   => $s->roll_number,
            'student_code'  => $s->student_code,
            'marks_obtained' => $existing->get($s->id)?->marks_obtained,
            'total_marks'    => $existing->get($s->id)?->total_marks ?? $exam->total_marks,
            'percentage'     => $existing->get($s->id)?->percentage,
            'assessment_id'  => $existing->get($s->id)?->id,
        ]);

        return response()->json([
            'exam'        => $exam,
            'subject'     => $subject,
            'total_marks' => $exam->total_marks,
            'rows'        => $rows,
        ]);
    }

    /**
     * POST /v1/pps/marks/assessment
     * Body: { exam_id, subject_id, rows: [{ student_id, marks_obtained, total_marks }] }
     */
    public function bulkStore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'exam_id'                  => ['required', 'exists:pps_exam_definitions,id'],
            'subject_id'               => ['required', 'exists:pps_subjects,id'],
            'rows'                     => ['required', 'array', 'min:1'],
            'rows.*.student_id'        => ['required', 'exists:students,id'],
            'rows.*.marks_obtained'    => ['nullable', 'numeric', 'min:0'],
            'rows.*.total_marks'       => ['nullable', 'numeric', 'min:0'],
        ]);

        $exam    = ExamDefinition::query()->findOrFail($data['exam_id']);
        $subject = Subject::query()->findOrFail($data['subject_id'], ['id', 'name']);
        $teacher = $request->user()?->id;
        $saved   = 0;

        // Build the authorised student set from the exam scope — reject any student_id outside it
        $scope = ExamScope::query()
            ->where('exam_id', $data['exam_id'])
            ->where('subject_id', $data['subject_id'])
            ->firstOrFail();

        $authorisedIds = Student::query()
            ->where('class_name', $scope->class_name)
            ->when($scope->section !== null, fn ($q) => $q->where('section', $scope->section))
            ->pluck('id')
            ->flip()
            ->toArray();

        DB::transaction(function () use ($data, $exam, $subject, $teacher, $authorisedIds, &$saved): void {
            foreach ($data['rows'] as $row) {
                if (!array_key_exists($row['student_id'], $authorisedIds)) {
                    abort(403, 'Student not in exam scope.');
                }

                if ($row['marks_obtained'] === null && $row['marks_obtained'] !== '0') {
                    continue;
                }

                $marksObtained = $row['marks_obtained'] !== null ? (float) $row['marks_obtained'] : null;
                $totalMarks    = isset($row['total_marks']) && $row['total_marks'] !== null
                    ? (float) $row['total_marks']
                    : (float) $exam->total_marks;

                $percentage = ($totalMarks > 0 && $marksObtained !== null)
                    ? round(($marksObtained / $totalMarks) * 100, 2)
                    : null;

                DB::table('pps_assessments')->updateOrInsert(
                    [
                        'student_id'      => $row['student_id'],
                        'subject'         => $subject->name,
                        'assessment_type' => $exam->assessment_type,
                        'term'            => $exam->term,
                    ],
                    [
                        'marks_obtained' => $marksObtained,
                        'total_marks'    => $totalMarks,
                        'percentage'     => $percentage,
                        'teacher_id'     => $teacher,
                        'updated_at'     => now(),
                        'created_at'     => now(),
                    ]
                );
                $saved++;
            }
        });

        return response()->json(['saved' => $saved]);
    }
}

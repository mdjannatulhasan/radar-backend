<?php

namespace App\Http\Controllers\Api\V1\Pps;

use App\Http\Controllers\Controller;
use App\Models\Pps\Assessment;
use App\Models\Student;
use App\Models\User;
use App\Services\Pps\ScoreCalculatorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AssessmentController extends Controller
{
    public function __construct(
        private readonly ScoreCalculatorService $scoreCalculator,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User|null $viewer */
        $viewer = $request->user();
        $data = $request->validate([
            'student_id' => ['required', 'exists:students,id'],
            'subject' => ['required', 'string', 'max:100'],
            'assessment_type' => ['required', 'in:class_test,assessment_test,quiz,spot_test,mid_term,final,assignment,practical'],
            'term' => ['required', 'string', 'max:20'],
            'marks_obtained' => ['required', 'numeric', 'min:0'],
            'total_marks' => ['required', 'numeric', 'gt:0'],
            'exam_date' => ['nullable', 'date'],
            'remarks' => ['nullable', 'string', 'max:500'],
            'teacher_id' => ['nullable', 'exists:users,id'],
        ]);

        $student = Student::query()->findOrFail($data['student_id']);
        $teacherId = $data['teacher_id'] ?? $viewer?->id;

        if ($viewer?->hasAnyRole('teacher')) {
            if (! $viewer->canAccessStudent($student)) {
                abort(Response::HTTP_FORBIDDEN, 'You are not assigned to this student.');
            }

            if (! in_array($data['subject'], $viewer->assignedSubjectsForClass($student->class_name, $student->section), true)) {
                abort(Response::HTTP_FORBIDDEN, 'You are not assigned to this subject for the selected class.');
            }

            $teacherId = $viewer->id;
        }

        $assessment = Assessment::query()->create([
            ...$data,
            'percentage' => round(($data['marks_obtained'] / $data['total_marks']) * 100, 2),
            'teacher_id' => $teacherId,
        ]);

        $period = $assessment->exam_date?->format('Y-m') ?? now()->format('Y-m');
        $snapshot = $this->scoreCalculator->calculateForStudent($assessment->student_id, $period);

        return response()->json([
            'assessment' => $assessment->fresh(),
            'snapshot' => $snapshot,
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        /** @var User|null $viewer */
        $viewer = $request->user();
        $assessments = Assessment::query()
            ->with('student:id,name,class_name,section,roll_number', 'teacher:id,name')
            ->when($viewer?->hasAnyRole('teacher'), fn ($query) => $query->where('teacher_id', $viewer->id))
            ->when($request->filled('student_id'), fn ($query) => $query->where('student_id', $request->integer('student_id')))
            ->when($request->filled('subject'), fn ($query) => $query->where('subject', $request->string('subject')->toString()))
            ->when($request->filled('term'), fn ($query) => $query->where('term', $request->string('term')->toString()))
            ->orderByDesc('exam_date')
            ->limit(200)
            ->get();

        return response()->json(['data' => $assessments]);
    }

    public function bulkStore(Request $request): JsonResponse
    {
        /** @var User|null $viewer */
        $viewer = $request->user();
        $request->validate([
            'rows' => ['nullable', 'array', 'min:1'],
            'file' => ['nullable', 'file'],
            'subject' => ['nullable', 'string', 'max:100'],
            'assessment_type' => ['nullable', 'in:class_test,assessment_test,quiz,spot_test,mid_term,final,assignment,practical'],
            'term' => ['nullable', 'string', 'max:20'],
            'exam_date' => ['nullable', 'date'],
        ]);

        $rows = collect($request->input('rows', []));

        if ($request->hasFile('file')) {
            $handle = fopen($request->file('file')->getRealPath(), 'r');
            if ($handle !== false) {
                $headers = fgetcsv($handle) ?: [];
                while (($record = fgetcsv($handle)) !== false) {
                    $rows->push(array_combine($headers, $record));
                }
                fclose($handle);
            }
        }

        $errors = [];
        $inserted = collect();
        foreach ($rows->values() as $index => $row) {
            $rowNum = $index + 2;
            $student = $this->resolveStudentFromRow($row);

            if (! $student) {
                $errors[] = ['row' => $rowNum, 'message' => 'Each row needs a valid student_id or student_code.'];
                continue;
            }

            $subject = trim((string) ($row['subject'] ?? $request->input('subject', '')));
            $assessmentType = trim((string) ($row['assessment_type'] ?? $request->input('assessment_type', '')));
            $term = trim((string) ($row['term'] ?? $request->input('term', '')));
            $marksObtained = is_numeric($row['marks_obtained'] ?? null) ? (float) $row['marks_obtained'] : null;
            $totalMarks = is_numeric($row['total_marks'] ?? null) ? (float) $row['total_marks'] : null;
            $examDate = $row['exam_date'] ?? $request->input('exam_date');

            if ($subject === '' || $assessmentType === '' || $term === '' || $marksObtained === null || $totalMarks === null) {
                $errors[] = ['row' => $rowNum, 'message' => 'subject, assessment_type, term, marks_obtained, and total_marks are required.'];
                continue;
            }

            if (! in_array($assessmentType, ['class_test', 'assessment_test', 'quiz', 'spot_test', 'mid_term', 'final', 'assignment', 'practical'], true)) {
                $errors[] = ['row' => $rowNum, 'message' => 'assessment_type is invalid.'];
                continue;
            }

            if ($marksObtained < 0 || $totalMarks <= 0 || $marksObtained > $totalMarks) {
                $errors[] = ['row' => $rowNum, 'message' => 'marks_obtained must be between 0 and total_marks.'];
                continue;
            }

            if ($viewer?->hasAnyRole('teacher')) {
                if (! $viewer->canAccessStudent($student)) {
                    $errors[] = ['row' => $rowNum, 'message' => 'You are not assigned to this student or class.'];
                    continue;
                }

                if (! in_array($subject, $viewer->assignedSubjectsForClass($student->class_name, $student->section), true)) {
                    $errors[] = ['row' => $rowNum, 'message' => 'The subject is outside your assigned teaching scope.'];
                    continue;
                }
            }

            $assessment = Assessment::query()->create([
                'student_id' => $student->id,
                'teacher_id' => $viewer?->hasAnyRole('teacher') ? $viewer->id : ($row['teacher_id'] ?? $viewer?->id),
                'subject' => $subject,
                'assessment_type' => $assessmentType,
                'term' => $term,
                'marks_obtained' => $marksObtained,
                'total_marks' => $totalMarks,
                'percentage' => round(($marksObtained / $totalMarks) * 100, 2),
                'exam_date' => $examDate ?: null,
                'remarks' => $row['remarks'] ?? null,
            ]);

            $inserted->push($assessment);
        }

        $inserted->groupBy(fn (Assessment $assessment) => $assessment->exam_date?->format('Y-m') ?? now()->format('Y-m'))
            ->each(function ($group, $period): void {
                $group->pluck('student_id')->unique()->each(
                    fn (int $studentId) => $this->scoreCalculator->calculateForStudent($studentId, $period)
                );
            });

        return response()->json([
            'imported' => $inserted->count(),
            'failed' => count($errors),
            'errors' => $errors,
            'student_ids' => $inserted->pluck('student_id')->unique()->values(),
        ], 201);
    }

    private function resolveStudentFromRow(array $row): ?Student
    {
        $studentId = isset($row['student_id']) && is_numeric($row['student_id']) ? (int) $row['student_id'] : null;
        $studentCode = trim((string) ($row['student_code'] ?? ''));

        return Student::query()
            ->when(
                $studentId,
                fn ($query) => $query->whereKey($studentId),
                fn ($query) => $query->where('student_code', $studentCode)
            )
            ->first();
    }
}

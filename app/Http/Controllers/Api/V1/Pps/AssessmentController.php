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
            'assessment_type' => ['required', 'in:class_test,mid_term,final,assignment,quiz,practical'],
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
            'rows.*.student_id' => ['required_with:rows', 'exists:students,id'],
            'rows.*.subject' => ['required_with:rows', 'string', 'max:100'],
            'rows.*.assessment_type' => ['required_with:rows', 'in:class_test,mid_term,final,assignment,quiz,practical'],
            'rows.*.term' => ['required_with:rows', 'string', 'max:20'],
            'rows.*.marks_obtained' => ['required_with:rows', 'numeric', 'min:0'],
            'rows.*.total_marks' => ['required_with:rows', 'numeric', 'gt:0'],
            'rows.*.exam_date' => ['nullable', 'date'],
            'file' => ['nullable', 'file'],
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

        $inserted = collect();
        foreach ($rows as $row) {
            $student = Student::query()->findOrFail((int) $row['student_id']);

            if ($viewer?->hasAnyRole('teacher')) {
                if (! $viewer->canAccessStudent($student)) {
                    abort(Response::HTTP_FORBIDDEN, 'You are not assigned to one or more selected students.');
                }

                if (! in_array((string) $row['subject'], $viewer->assignedSubjectsForClass($student->class_name, $student->section), true)) {
                    abort(Response::HTTP_FORBIDDEN, 'One or more assessment rows target an unassigned subject.');
                }
            }

            $assessment = Assessment::query()->create([
                'student_id' => (int) $row['student_id'],
                'teacher_id' => $viewer?->hasAnyRole('teacher') ? $viewer->id : ($row['teacher_id'] ?? $viewer?->id),
                'subject' => $row['subject'],
                'assessment_type' => $row['assessment_type'],
                'term' => $row['term'],
                'marks_obtained' => (float) $row['marks_obtained'],
                'total_marks' => (float) $row['total_marks'],
                'percentage' => round(((float) $row['marks_obtained'] / (float) $row['total_marks']) * 100, 2),
                'exam_date' => $row['exam_date'] ?? null,
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
            'student_ids' => $inserted->pluck('student_id')->unique()->values(),
        ], 201);
    }
}

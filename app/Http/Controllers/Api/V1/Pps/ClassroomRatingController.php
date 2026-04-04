<?php

namespace App\Http\Controllers\Api\V1\Pps;

use App\Http\Controllers\Controller;
use App\Models\Pps\ClassroomRating;
use App\Models\Student;
use App\Models\User;
use App\Services\Pps\ScoreCalculatorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ClassroomRatingController extends Controller
{
    public function __construct(
        private readonly ScoreCalculatorService $scoreCalculator,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        /** @var User|null $viewer */
        $viewer = $request->user();
        $ratings = ClassroomRating::query()
            ->with('student:id,name,class_name,section,roll_number', 'teacher:id,name')
            ->when($viewer?->hasAnyRole('teacher'), fn ($query) => $query->where('teacher_id', $viewer->id))
            ->when($request->filled('student_id'), fn ($query) => $query->where('student_id', $request->integer('student_id')))
            ->when($request->filled('teacher_id'), fn ($query) => $query->where('teacher_id', $request->integer('teacher_id')))
            ->when($request->filled('subject'), fn ($query) => $query->where('subject', $request->string('subject')->toString()))
            ->orderByDesc('rating_period')
            ->limit(200)
            ->get();

        return response()->json(['data' => $ratings]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User|null $viewer */
        $viewer = $request->user();
        $data = $request->validate([
            'student_id' => ['required', 'exists:students,id'],
            'subject' => ['nullable', 'string', 'max:100'],
            'rating_period' => ['required', 'date'],
            'period_type' => ['nullable', 'in:weekly,monthly'],
            'participation' => ['nullable', 'integer', 'min:1', 'max:5'],
            'attentiveness' => ['nullable', 'integer', 'min:1', 'max:5'],
            'group_work' => ['nullable', 'integer', 'min:1', 'max:5'],
            'creativity' => ['nullable', 'integer', 'min:1', 'max:5'],
            'behavioral_flag' => ['nullable', 'string', 'max:100'],
            'free_comment' => ['nullable', 'string'],
            'teacher_id' => ['nullable', 'exists:users,id'],
        ]);

        $student = Student::query()->findOrFail($data['student_id']);
        $teacherId = $data['teacher_id'] ?? $viewer?->id;

        if ($viewer?->hasAnyRole('teacher')) {
            if (! $viewer->canAccessStudent($student)) {
                abort(Response::HTTP_FORBIDDEN, 'You are not assigned to this student.');
            }

            $subject = $data['subject'] ?? '';
            if ($subject === '' || ! in_array($subject, $viewer->assignedSubjectsForClass($student->class_name, $student->section), true)) {
                abort(Response::HTTP_FORBIDDEN, 'You are not assigned to this subject for the selected class.');
            }

            $teacherId = $viewer->id;
        }

        $rating = ClassroomRating::query()->updateOrCreate(
            [
                'student_id' => $data['student_id'],
                'teacher_id' => $teacherId,
                'subject' => $data['subject'] ?? null,
                'rating_period' => $data['rating_period'],
            ],
            [
                ...$data,
                'teacher_id' => $teacherId,
                'period_type' => $data['period_type'] ?? 'weekly',
            ]
        );

        $this->scoreCalculator->calculateForStudent($rating->student_id, $rating->rating_period->format('Y-m'));

        return response()->json($rating->fresh(), 201);
    }
}

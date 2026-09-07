<?php

namespace App\Http\Controllers\Api\V1\Pps;

use App\Http\Controllers\Controller;
use App\Models\Pps\ClassroomRating;
use SmsCore\Models\Student;
use SmsCore\Models\Subject;
use SmsCore\Models\User;
use App\Services\Pps\ScoreCalculatorService;
use App\Support\StudentTaxonomyFilter;
use App\Support\TeacherScope;
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
            ->with(array_merge(
                [
                    'student:id,name,roll_number',
                    // pps_classroom_ratings.teacher_id points at teachers now,
                    // and teachers carry full_name — aliased so the payload key
                    // stays `name`.
                    'teacher' => fn ($query) => $query->select(['id'])->selectRaw('full_name as name'),
                ],
                StudentTaxonomyFilter::eagerLoadVia('student'),
            ))
            ->when(
                $viewer?->hasAnyRole('teacher'),
                // A login is not a teacher id. A teacher account with no staff
                // record sees nothing rather than everything.
                fn ($query) => $query->where('teacher_id', TeacherScope::teacherId($viewer) ?? 0)
            )
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
            'teacher_id' => ['nullable', 'exists:teachers,id'],
        ]);

        $student = Student::query()->findOrFail($data['student_id']);
        $teacherId = $data['teacher_id'] ?? TeacherScope::teacherId($viewer);

        if ($viewer?->hasAnyRole('teacher')) {
            if (! TeacherScope::canAccessStudent($viewer, $student)) {
                abort(Response::HTTP_FORBIDDEN, 'You are not assigned to this student.');
            }

            // Assignments name subject ids now; the rating still stores a free
            // text subject, so compare against the assigned subjects' names.
            // No assigned subject ids means an empty list, which denies.
            $assignedSubjectNames = Subject::query()
                ->whereIn('id', TeacherScope::assignedSubjectIdsForSection($viewer, $student->section_id))
                ->get(['id', 'full_name', 'short_name'])
                ->flatMap(fn (Subject $s) => array_filter([$s->full_name, $s->short_name]))
                ->all();

            $subject = $data['subject'] ?? '';
            if ($subject === '' || ! in_array($subject, $assignedSubjectNames, true)) {
                abort(Response::HTTP_FORBIDDEN, 'You are not assigned to this subject for the selected class.');
            }

            $teacherId = TeacherScope::teacherId($viewer);
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

<?php

namespace App\Http\Controllers\Api\V1\Pps;

use App\Http\Controllers\Controller;
use App\Models\Pps\BehaviorCard;
use App\Models\Student;
use App\Models\User;
use App\Services\Pps\ScoreCalculatorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BehaviorController extends Controller
{
    public function __construct(
        private readonly ScoreCalculatorService $scoreCalculator,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        /** @var User|null $viewer */
        $viewer = $request->user();
        $cards = BehaviorCard::query()
            ->with('student:id,name,class_name,section,roll_number')
            ->when($viewer?->hasAnyRole('teacher'), fn ($query) => $query->where('issued_by', $viewer->id))
            ->when($request->filled('student_id'), fn ($query) => $query->where('student_id', $request->integer('student_id')))
            ->orderByDesc('issued_at')
            ->limit(50)
            ->get();

        return response()->json(['data' => $cards]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User|null $viewer */
        $viewer = $request->user();
        $data = $request->validate([
            'student_id' => ['required', 'exists:students,id'],
            'card_type' => ['required', 'in:green,yellow,red'],
            'reason' => ['required', 'string'],
            'notes' => ['nullable', 'string'],
            'is_integrity_violation' => ['nullable', 'boolean'],
            'issued_at' => ['nullable', 'date'],
        ]);

        $student = Student::query()->findOrFail($data['student_id']);

        if ($viewer?->hasAnyRole('teacher') && ! $viewer->isAssignedToClass($student->class_name, $student->section)) {
            abort(Response::HTTP_FORBIDDEN, 'You are not assigned to this class.');
        }

        $card = BehaviorCard::query()->create([
            ...$data,
            'issued_by' => $request->user()?->id,
            'issued_at' => $data['issued_at'] ?? now(),
            'is_integrity_violation' => $data['is_integrity_violation'] ?? false,
        ]);

        $this->scoreCalculator->calculateForStudent($card->student_id, $card->issued_at->format('Y-m'));

        return response()->json($card->fresh(), 201);
    }
}

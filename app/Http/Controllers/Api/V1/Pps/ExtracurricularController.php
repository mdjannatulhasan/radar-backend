<?php

namespace App\Http\Controllers\Api\V1\Pps;

use App\Http\Controllers\Controller;
use App\Models\Pps\Extracurricular;
use App\Services\Pps\ScoreCalculatorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExtracurricularController extends Controller
{
    public function __construct(
        private readonly ScoreCalculatorService $scoreCalculator,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $records = Extracurricular::query()
            ->with('student:id,name,class_name,section,roll_number')
            ->when($request->filled('student_id'), fn ($query) => $query->where('student_id', $request->integer('student_id')))
            ->orderByDesc('event_date')
            ->limit(100)
            ->get();

        return response()->json(['data' => $records]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'student_id' => ['required', 'exists:students,id'],
            'activity_name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:50'],
            'role' => ['nullable', 'string', 'max:100'],
            'achievement' => ['nullable', 'string', 'max:255'],
            'achievement_level' => ['nullable', 'integer', 'min:1', 'max:5'],
            'event_date' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $record = Extracurricular::query()->create([
            ...$data,
            'achievement_level' => $data['achievement_level'] ?? 1,
        ]);

        $this->scoreCalculator->calculateForStudent($record->student_id, $record->event_date->format('Y-m'));

        return response()->json($record->fresh(), 201);
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Pps;

use App\Http\Controllers\Controller;
use App\Models\Pps\PpsAlert;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AlertController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        $alerts = PpsAlert::query()
            ->with('student:id,name,class_name,section,roll_number')
            ->when(
                $request->boolean('active', true),
                fn ($query) => $query->whereNull('resolved_at')
            )
            ->when($user?->hasAnyRole('teacher'), function ($query) use ($user): void {
                $assignments = $user->teacherAssignments()
                    ->get(['class_name', 'section'])
                    ->unique(fn ($assignment) => "{$assignment->class_name}:{$assignment->section}");

                if ($assignments->isEmpty()) {
                    $query->whereRaw('1 = 0');
                    return;
                }

                $query->whereHas('student', function ($studentQuery) use ($assignments): void {
                    $assignments->each(function ($assignment) use ($studentQuery): void {
                        $studentQuery->orWhere(function ($classQuery) use ($assignment): void {
                            $classQuery
                                ->where('class_name', $assignment->class_name)
                                ->where('section', $assignment->section);
                        });
                    });
                });
            })
            ->when($request->filled('alert_level'), fn ($query) => $query->where('alert_level', $request->string('alert_level')->toString()))
            ->orderByRaw("CASE alert_level WHEN 'urgent' THEN 1 WHEN 'warning' THEN 2 ELSE 3 END")
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        return response()->json(['data' => $alerts]);
    }
}

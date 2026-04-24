<?php

namespace App\Http\Controllers\Api\V1\Pps;

use App\Http\Controllers\Controller;
use App\Models\Pps\ExamDefinition;
use App\Models\Pps\Subject;
use App\Models\Pps\TeacherAssignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MarksMetaController extends Controller
{
    /**
     * GET /v1/pps/marks/meta
     *
     * Returns the exams and subjects accessible to the authenticated user for marks entry.
     * Admins/principals see all. Teachers see only their assigned classes+subjects.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $assignments = TeacherAssignment::query()
            ->where('teacher_id', $user->id)
            ->get(['class_name', 'section', 'subject']);

        if ($assignments->isEmpty()) {
            // Admin/principal path — return everything
            $exams = ExamDefinition::query()
                ->where('is_active', true)
                ->orderBy('class_name')
                ->orderBy('title')
                ->get(['id', 'title', 'class_name', 'section', 'term', 'assessment_type']);

            $subjects = Subject::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'code']);
        } else {
            // Teacher path — scope to their assignments
            $classSectionPairs = $assignments->map(fn ($a) => [
                'class_name' => $a->class_name,
                'section'    => $a->section,
            ])->unique(fn ($item) => $item['class_name'] . ':' . $item['section'])->values();

            $examsQuery = ExamDefinition::query()->where('is_active', true);
            $examsQuery->where(function ($q) use ($classSectionPairs) {
                foreach ($classSectionPairs as $pair) {
                    $q->orWhere(function ($inner) use ($pair) {
                        $inner->where('class_name', $pair['class_name'])
                              ->where(fn ($s) => $s->where('section', $pair['section'])->orWhereNull('section'));
                    });
                }
            });

            $exams = $examsQuery->orderBy('class_name')->orderBy('title')
                ->get(['id', 'title', 'class_name', 'section', 'term', 'assessment_type']);

            $assignedSubjectNames = $assignments->pluck('subject')->unique()->values();

            $subjects = Subject::query()
                ->where('is_active', true)
                ->where(fn ($q) => $q->whereIn('name', $assignedSubjectNames)
                                     ->orWhereIn('code', $assignedSubjectNames))
                ->orderBy('name')
                ->get(['id', 'name', 'code']);
        }

        return response()->json([
            'exams'    => $exams,
            'subjects' => $subjects,
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Pps;

use App\Http\Controllers\Controller;
use App\Models\Pps\ExamDefinition;
use Illuminate\Http\JsonResponse;

class ExamListController extends Controller
{
    public function index(): JsonResponse
    {
        $exams = ExamDefinition::query()
            ->with('scopes:id,exam_id,class_name,section,subject_id,department_id')
            ->where('is_active', true)
            ->orderBy('title')
            ->get(['id', 'title', 'term', 'assessment_type']);

        // Flatten to one row per scope for backwards-compat with marks entry selectors.
        // Exams with no scopes appear once with null class/section.
        $flat = [];
        foreach ($exams as $exam) {
            if ($exam->scopes->isEmpty()) {
                $flat[] = [
                    'id'              => $exam->id,
                    'title'           => $exam->title,
                    'class_name'      => null,
                    'section'         => null,
                    'term'            => $exam->term,
                    'assessment_type' => $exam->assessment_type,
                ];
            } else {
                foreach ($exam->scopes as $scope) {
                    $flat[] = [
                        'id'              => $exam->id,
                        'title'           => $exam->title,
                        'class_name'      => $scope->class_name,
                        'section'         => $scope->section,
                        'term'            => $exam->term,
                        'assessment_type' => $exam->assessment_type,
                    ];
                }
            }
        }

        return response()->json(['exams' => $flat]);
    }
}

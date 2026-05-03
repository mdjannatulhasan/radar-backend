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
            ->where('is_active', true)
            ->orderBy('class_name')->orderBy('title')
            ->get(['id', 'title', 'class_name', 'section', 'term', 'assessment_type']);
        return response()->json(['exams' => $exams]);
    }
}

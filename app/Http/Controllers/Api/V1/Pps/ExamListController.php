<?php

namespace App\Http\Controllers\Api\V1\Pps;

use App\Http\Controllers\Controller;
use App\Models\Pps\Exam;
use Illuminate\Http\JsonResponse;

class ExamListController extends Controller
{
    public function index(): JsonResponse
    {
        $exams = Exam::query()
            ->with([
                'examType:id,code,is_terminal',
                'components:id,exam_id,name,code,max_raw_marks,max_contribution,sort_order',
            ])
            ->where('is_active', true)
            ->orderBy('title')
            ->get(['id', 'title', 'term', 'academic_year', 'scope', 'exam_type_id']);

        $flat = $exams->map(fn (Exam $exam) => [
            'id'            => $exam->id,
            'title'         => $exam->title,
            'term'          => $exam->term,
            'academic_year' => $exam->academic_year,
            'scope'         => $exam->scope,
            'is_terminal'   => (bool) ($exam->examType?->is_terminal),
            'components'    => $exam->components->map(fn ($c) => [
                'id'               => $c->id,
                'code'             => $c->code,
                'label'            => $c->name,
                'max_raw_marks'    => $c->max_raw_marks,
                'max_contribution' => $c->max_contribution,
            ])->values()->all(),
        ])->values()->all();

        return response()->json(['exams' => $flat]);
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Pps;

use App\Http\Controllers\Controller;
use App\Models\Pps\Exam;
use App\Models\Pps\SchoolClass;
use App\Models\Pps\Section;
use App\Models\Pps\Subject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MarksMetaController extends Controller
{
    /**
     * GET /v1/pps/marks/meta
     *
     * Returns all active non-draft exams with components and full subject list.
     */
    public function index(Request $request): JsonResponse
    {
        $exams = Exam::with(['examType', 'components'])
            ->where('is_active', true)
            ->where('status', '!=', 'draft')
            ->orderBy('academic_year')
            ->orderBy('term')
            ->orderBy('title')
            ->get();

        $subjects = Subject::where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']);
        $classes  = SchoolClass::where('is_active', true)->orderBy('numeric_order')->orderBy('name')->get(['id', 'name']);
        $sections = Section::where('is_active', true)->orderBy('name')->get(['id', 'name']);

        return response()->json([
            'classes'  => $classes,
            'sections' => $sections,
            'exams'    => $exams->map(fn ($e) => [
                'id'            => $e->id,
                'title'         => $e->title,
                'term'          => $e->term,
                'academic_year' => $e->academic_year,
                'scope'         => $e->scope,
                'is_terminal'   => $e->examType->is_terminal,
                'components'    => $e->components->map(fn ($c) => [
                    'id'               => $c->id,
                    'name'             => $c->name,
                    'code'             => $c->code,
                    'max_raw_marks'    => $c->max_raw_marks,
                    'max_contribution' => $c->max_contribution,
                ]),
            ]),
            'subjects' => $subjects,
        ]);
    }
}

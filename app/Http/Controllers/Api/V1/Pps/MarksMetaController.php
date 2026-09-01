<?php

namespace App\Http\Controllers\Api\V1\Pps;

use App\Http\Controllers\Controller;
use App\Models\Pps\Exam;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use SmsCore\Models\ClassLevel;
use SmsCore\Models\SectionName;
use SmsCore\Models\Subject;

class MarksMetaController extends Controller
{
    /**
     * GET /v1/pps/marks/meta
     *
     * Returns all active non-draft exams with components and full subject list.
     *
     * `classes` and `sections` are the vocabulary the marks grid puts in its two
     * dropdowns and sends back to GET /marks as the `class_name` / `section`
     * strings. Classes come from class_levels. Sections come from section_names
     * — the flat name vocabulary that replaced pps_sections — and NOT from the
     * `sections` table, whose rows are one-per-class-and-name and would put "A"
     * in the list once per class while carrying an id the string filter cannot
     * use. Both payload shapes are unchanged: { id, name }.
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

        // subjects.full_name / short_name are the new columns behind the
        // unchanged `name` / `code` payload keys.
        $subjects = Subject::where('is_active', true)
            ->orderBy('full_name')
            ->get(['id', 'full_name', 'short_name'])
            ->map(fn (Subject $subject) => [
                'id'   => $subject->id,
                'name' => $subject->full_name,
                'code' => $subject->short_name,
            ])
            ->values();

        $classes  = ClassLevel::where('is_active', true)->orderBy('numeric_order')->orderBy('name')->get(['id', 'name']);
        $sections = SectionName::query()->orderBy('name')->get(['id', 'name']);

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

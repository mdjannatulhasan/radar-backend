<?php

namespace App\Http\Controllers\Api\V1\Pps;

use App\Http\Controllers\Controller;
use App\Models\Pps\Exam;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use SmsCore\Models\ClassLevel;
use SmsCore\Models\Subject;

class MarksMetaController extends Controller
{
    /**
     * GET /v1/pps/marks/meta
     *
     * Returns all active non-draft exams with components, the full subject
     * list, and the class vocabulary the marks grid puts in its dropdowns.
     *
     * `classes` carries the level and version dimensions and nests its own
     * sections, matching ClassStructureController's shape. It used to be a bare
     * {id, name} list beside a flat SectionName vocabulary, which was wrong in
     * both directions: "Class 9" exists in the Bangla version AND the English
     * version and they are different cohorts, so the list showed the same label
     * twice with nothing to tell them apart; and the section list was the
     * school-wide name vocabulary, so it offered "A" for a junior class whose
     * only sections are Daffodil, Dahlia, Dolon, Mohua and Shapla.
     *
     * The grid sends `class_level_id` / `section_id` back to GET /marks from
     * these rows. Nothing derives a class from a name string any more.
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

        $classes = ClassLevel::query()
            ->where('is_active', true)
            ->with([
                'level:id,name',
                'version:id,name',
                'sections' => fn ($q) => $q->where('is_active', true)->with('sectionName:id,name,sort_order'),
            ])
            ->orderBy('numeric_order')
            ->orderBy('name')
            ->get()
            ->map(fn (ClassLevel $cl): array => [
                'id'           => $cl->id,
                'name'         => $cl->name,
                'group'        => $cl->group,
                'level_id'     => $cl->level_id,
                'level_name'   => $cl->level?->name,
                'version_id'   => $cl->version_id,
                'version_name' => $cl->version?->name,
                'full_label'   => $cl->full_label,
                'sections'     => $cl->sections
                    ->sortBy(fn ($s) => [$s->sectionName?->sort_order ?? 0, $s->sectionName?->name ?? ''])
                    ->map(fn ($s): array => [
                        'id'   => $s->id,
                        'name' => $s->sectionName?->name,
                    ])
                    ->values(),
            ])
            ->values();

        return response()->json([
            'classes'  => $classes,
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

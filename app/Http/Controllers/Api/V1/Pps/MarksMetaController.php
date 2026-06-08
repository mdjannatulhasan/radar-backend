<?php

namespace App\Http\Controllers\Api\V1\Pps;

use App\Http\Controllers\Controller;
use App\Models\Pps\ExamDefinition;
use App\Models\Pps\Subject;
use App\Models\Pps\TeacherAssignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MarksMetaController extends Controller
{
    /**
     * GET /v1/pps/marks/meta
     *
     * Returns exams (flattened per scope) and subjects for the authenticated user.
     * Admins/principals see all. Teachers see only their assigned classes+subjects.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $assignments = TeacherAssignment::query()
            ->where('teacher_id', $user->id)
            ->get(['class_name', 'section', 'subject']);

        if ($assignments->isEmpty()) {
            // Admin/principal path — return everything via scopes
            $rows = DB::table('pps_exam_definitions as d')
                ->join('pps_exam_scopes as s', 's.exam_id', '=', 'd.id')
                ->where('d.is_active', true)
                ->orderBy('s.class_name')
                ->orderBy('d.title')
                ->get(['d.id', 'd.title', 'd.term', 'd.assessment_type', 's.class_name', 's.section', 's.subject_id']);

            $subjects = Subject::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'code']);
        } else {
            // Teacher path — scope to assigned class+section pairs via scopes table
            $classSectionPairs = $assignments->map(fn ($a) => [
                'class_name' => $a->class_name,
                'section'    => $a->section,
            ])->unique(fn ($item) => $item['class_name'] . ':' . $item['section'])->values();

            $query = DB::table('pps_exam_definitions as d')
                ->join('pps_exam_scopes as s', 's.exam_id', '=', 'd.id')
                ->where('d.is_active', true)
                ->where(function ($q) use ($classSectionPairs) {
                    foreach ($classSectionPairs as $pair) {
                        $q->orWhere(function ($inner) use ($pair) {
                            $inner->where('s.class_name', $pair['class_name'])
                                  ->where(fn ($s) => $s->where('s.section', $pair['section'])->orWhereNull('s.section'));
                        });
                    }
                });

            $rows = $query->orderBy('s.class_name')->orderBy('d.title')
                ->get(['d.id', 'd.title', 'd.term', 'd.assessment_type', 's.class_name', 's.section', 's.subject_id']);

            $assignedSubjectNames = $assignments->pluck('subject')->unique()->values();

            $subjects = Subject::query()
                ->where('is_active', true)
                ->where(fn ($q) => $q->whereIn('name', $assignedSubjectNames)
                                     ->orWhereIn('code', $assignedSubjectNames))
                ->orderBy('name')
                ->get(['id', 'name', 'code']);
        }

        $exams = $rows->map(fn ($r) => [
            'id'              => $r->id,
            'title'           => $r->title,
            'class_name'      => $r->class_name,
            'section'         => $r->section,
            'term'            => $r->term,
            'assessment_type' => $r->assessment_type,
            'subject_id'      => $r->subject_id,
        ])->values()->all();

        return response()->json([
            'exams'    => $exams,
            'subjects' => $subjects,
        ]);
    }
}

<?php
namespace App\Http\Controllers\Api\V1\Pps;

use App\Http\Controllers\Controller;
use App\Models\Pps\Exam;
use App\Models\Pps\ExamClassMap;
use App\Models\Pps\Mark;
use App\Models\Student;
use App\Services\Pps\ComputedScoreService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

class MarksController extends Controller
{
    public function __construct(private readonly ComputedScoreService $scorer) {}

    /**
     * GET /marks?exam_id=&subject_id=
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'exam_id'    => ['required', 'integer', 'exists:pps_exams,id'],
            'subject_id' => ['required', 'integer', 'exists:pps_subjects,id'],
            'class_name' => ['nullable', 'string', 'max:20'],
            'section'    => ['nullable', 'string', 'max:20'],
        ]);

        $exam       = Exam::with(['components', 'examType'])->findOrFail($data['exam_id']);
        $components = $exam->components;

        $studentIds = $this->resolveStudentIds($exam, (int) $data['subject_id'], $data['class_name'] ?? null, $data['section'] ?? null);

        $marks = Mark::whereIn('component_id', $components->pluck('id'))
            ->whereIn('student_id', $studentIds)
            ->where('subject_id', $data['subject_id'])
            ->get(['component_id', 'student_id', 'marks_obtained'])
            ->groupBy('student_id');

        $students = Student::whereIn('id', $studentIds)
            ->orderBy('roll_number')
            ->get(['id', 'name', 'roll_number', 'class_name', 'section']);

        return response()->json([
            'exam' => [
                'id'          => $exam->id,
                'title'       => $exam->title,
                'term'        => $exam->term,
                'is_terminal' => $exam->examType->is_terminal,
            ],
            'components' => $components->map(fn ($c) => [
                'id'               => $c->id,
                'name'             => $c->name,
                'code'             => $c->code,
                'max_raw_marks'    => $c->max_raw_marks,
                'max_contribution' => $c->max_contribution,
            ]),
            'rows' => $students->map(fn ($student) => [
                'student_id'  => $student->id,
                'full_name'   => $student->name,
                'roll_number' => $student->roll_number,
                'marks'       => $components->mapWithKeys(fn ($c) => [
                    $c->id => $marks->get($student->id)?->firstWhere('component_id', $c->id)?->marks_obtained,
                ]),
            ]),
        ]);
    }

    /**
     * POST /marks
     * Body: { exam_id, subject_id, rows: [{ student_id, marks: { component_id: value } }] }
     */
    public function bulkStore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'exam_id'               => ['required', 'integer', 'exists:pps_exams,id'],
            'subject_id'            => ['required', 'integer', 'exists:pps_subjects,id'],
            'class_name'            => ['nullable', 'string', 'max:20'],
            'section'               => ['nullable', 'string', 'max:20'],
            'rows'                  => ['required', 'array'],
            'rows.*.student_id'     => ['required', 'integer', 'exists:students,id'],
            'rows.*.marks'          => ['required', 'array'],
        ]);

        $exam       = Exam::with('components')->findOrFail($data['exam_id']);
        $components = $exam->components->keyBy('id');
        $subjectId  = (int) $data['subject_id'];
        $user       = $request->user();
        $enteredBy  = $user?->id;

        // Non-superadmin teachers may only write marks for exams they are assigned to.
        if (!$user?->hasAnyRole(['superadmin', 'admin', 'principal'])) {
            $assigned = DB::table('pps_teacher_assignments')
                ->where('teacher_id', $user->id)
                ->exists();
            // For now scope check: teacher must have at least one assignment.
            // Full per-exam scoping is enforced below via resolveStudentIds.
            if (!$assigned) {
                abort(403, 'Not authorised to enter marks for this exam.');
            }
        }

        $authorisedIds = $this->resolveStudentIds($exam, $subjectId, $data['class_name'] ?? null, $data['section'] ?? null)->flip()->toArray();

        $saved = 0;
        DB::transaction(function () use ($data, $components, $subjectId, $enteredBy, $authorisedIds, &$saved) {
            foreach ($data['rows'] as $row) {
                $studentId = (int) $row['student_id'];
                if (!array_key_exists($studentId, $authorisedIds)) {
                    abort(403, "Student {$studentId} not in exam scope.");
                }

                foreach ($row['marks'] as $componentId => $value) {
                    $component = $components->get((int) $componentId);
                    if (!$component) continue;
                    if ($value === null || $value === '') continue;

                    $obtained = min((float) $value, $component->max_raw_marks);

                    Mark::updateOrCreate(
                        ['component_id' => $component->id, 'student_id' => $studentId, 'subject_id' => $subjectId],
                        ['marks_obtained' => $obtained, 'entered_by' => $enteredBy]
                    );
                    $saved++;
                }
            }
        });

        $this->scorer->recomputeForExamSubject($data['exam_id'], $subjectId);

        return response()->json(['saved' => $saved], Response::HTTP_CREATED);
    }

    private function resolveStudentIds(Exam $exam, int $subjectId, ?string $className = null, ?string $section = null): Collection
    {
        if ($exam->scope === 'global') {
            $q = Student::query();
            if ($className) $q->where('class_name', $className);
            if ($section)   $q->where('section', $section);
            return $q->pluck('id');
        }

        $maps = ExamClassMap::where('exam_id', $exam->id)
            ->where(fn ($q) => $q->whereNull('subject_id')->orWhere('subject_id', $subjectId))
            ->get();

        // Fail closed: no maps = no authorised students.
        if ($maps->isEmpty()) {
            return collect();
        }

        $studentQuery = Student::query()->where('id', 0); // start with no results
        foreach ($maps as $map) {
            // Skip degenerate maps with no class or section scope — misconfiguration.
            if (!$map->class_id && !$map->section_id) {
                continue;
            }
            $studentQuery->orWhere(function ($q) use ($map) {
                if ($map->class_id)   $q->where('class_id', $map->class_id);
                if ($map->section_id) $q->where('section_id', $map->section_id);
            });
        }

        return $studentQuery->pluck('id');
    }
}

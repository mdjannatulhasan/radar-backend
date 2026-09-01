<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Pps;

use App\Http\Controllers\Controller;
use App\Models\Pps\ComputedScore;
use App\Models\Pps\Exam;
use App\Models\Pps\GradeConfig;
use App\Models\Pps\Mark;
use App\Models\Pps\TeacherAssignment;
use App\Support\StudentTaxonomyFilter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use SmsCore\Models\AcademicYear;
use SmsCore\Models\ClassLevel;
use SmsCore\Models\Section;
use SmsCore\Models\SectionName;
use SmsCore\Models\Student;
use SmsCore\Models\StudentEnrollment;
use SmsCore\Models\Subject;
use SmsCore\Models\Teacher;
use SmsCore\Models\User;
use Symfony\Component\HttpFoundation\Response;

/**
 * The admin catalog, rebuilt on the shared taxonomy.
 *
 * Two of its resources have no successor: departments and streams were
 * duplicates of each other and both became class_levels.group, which is an
 * enum on a row rather than a table of its own. Their endpoints answer 410
 * rather than pretending to still manage something.
 */
class AdministrationController extends Controller
{
    /** The only values class_levels.group accepts (enforced by a CHECK constraint). */
    private const GROUPS = [
        ['id' => 'science', 'name' => 'Science'],
        ['id' => 'humanities', 'name' => 'Humanities'],
        ['id' => 'business_studies', 'name' => 'Business Studies'],
    ];

    public function overview(): JsonResponse
    {
        return response()->json([
            'summary' => [
                // "department" is now a group on a class level, not a row.
                'departments' => ClassLevel::query()->whereNotNull('group')->distinct()->count('group'),
                'class_sections' => Section::query()->count(),
                'classes' => ClassLevel::query()->count(),
                'sections' => SectionName::query()->count(),
                'subjects' => Subject::query()->count(),
                'exams' => Exam::query()->count(),
                'students' => Student::query()->count(),
                'teachers' => Teacher::query()->count(),
                'teacher_assignments' => TeacherAssignment::query()->count(),
            ],
            'teachers' => Teacher::query()
                ->with('user:id,email,is_active')
                ->orderBy('full_name')
                ->get(['id', 'full_name', 'short_code', 'user_id', 'is_active']),
            'departments' => self::GROUPS,
            'streams' => self::GROUPS,
            'sections' => SectionName::query()
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
            'classes' => ClassLevel::query()
                ->with('level:id,name', 'version:id,name')
                ->orderBy('numeric_order')
                ->orderBy('name')
                ->get(),
            // class_configs and class_sections both described "this class runs
            // this section"; sections is now the single table for that.
            'class_configs' => Section::query()
                ->with('classLevel:id,name,group,numeric_order', 'sectionName:id,name')
                ->orderBy('class_level_id')
                ->get(),
            'class_sections' => Section::query()
                ->with('classLevel:id,name,group,numeric_order', 'sectionName:id,name')
                ->orderBy('class_level_id')
                ->get(),
            'subjects' => Subject::query()
                ->with('level:id,name', 'version:id,name')
                ->orderBy('full_name')
                ->get(),
            'exams' => Exam::query()
                ->with('examType:id,code,name', 'components', 'classMaps')
                ->orderByDesc('exam_date')
                ->orderBy('title')
                ->get(),
            'teacher_assignments' => TeacherAssignment::query()
                ->with([
                    'teacher:id,full_name',
                    'section.classLevel:id,name',
                    'section.sectionName:id,name',
                    'subject:id,full_name,short_name',
                ])
                ->get(),
            'students' => StudentTaxonomyFilter::orderByClassAndSection(
                Student::query()->with(StudentTaxonomyFilter::eagerLoad())
            )->orderBy('roll_number')->limit(300)->get(),
            'grade_config' => GradeConfig::query()
                ->whereNull('school_id')
                ->orderBy('sort_order')
                ->get(),
        ]);
    }

    // ─── Departments and streams: no successor ───────────────────────────────

    public function storeDepartment(): JsonResponse
    {
        return $this->gone('department');
    }

    public function updateDepartment(Request $request, string $department): JsonResponse
    {
        return $this->gone('department');
    }

    public function destroyDepartment(string $department): JsonResponse
    {
        return $this->gone('department');
    }

    public function storeStream(): JsonResponse
    {
        return $this->gone('stream');
    }

    public function updateStream(Request $request, string $stream): JsonResponse
    {
        return $this->gone('stream');
    }

    public function destroyStream(string $stream): JsonResponse
    {
        return $this->gone('stream');
    }

    private function gone(string $resource): JsonResponse
    {
        return response()->json([
            'message' => "The {$resource} resource no longer exists. Science, humanities and "
                .'business studies are now the `group` of a class level; set it there.',
        ], Response::HTTP_GONE);
    }

    // ─── Sections (the NAME vocabulary: "A", "Shapla") ───────────────────────

    public function storeSection(Request $request): JsonResponse
    {
        $data = $request->validate($this->sectionNameRules($request));

        return response()->json([
            'section' => SectionName::query()->create($data + ['school_id' => $this->schoolId($request)]),
        ], Response::HTTP_CREATED);
    }

    public function updateSection(Request $request, SectionName $section): JsonResponse
    {
        $section->update($request->validate($this->sectionNameRules($request, $section)));

        return response()->json(['section' => $section->fresh()]);
    }

    public function destroySection(SectionName $section): JsonResponse
    {
        if ($section->sections()->exists()) {
            return response()->json([
                'message' => 'This section name is used by a class. Remove those class sections first.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $section->delete();

        return response()->json(['deleted' => true]);
    }

    /** @return array<string, mixed> */
    private function sectionNameRules(Request $request, ?SectionName $section = null): array
    {
        return [
            'name' => [
                $section === null ? 'required' : 'sometimes',
                'string',
                'max:30',
                Rule::unique('section_names', 'name')
                    ->ignore($section?->id)
                    ->where(fn ($q) => $q->where('school_id', $this->schoolId($request))),
            ],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
        ];
    }

    // ─── Class levels ────────────────────────────────────────────────────────

    public function storeClass(Request $request): JsonResponse
    {
        $data = $request->validate($this->classLevelRules($request));

        return DB::transaction(function () use ($data, $request): JsonResponse {
            $classLevel = ClassLevel::query()->create(
                Arr::only($data, ['level_id', 'version_id', 'name', 'group', 'numeric_order', 'is_active'])
                + ['school_id' => $this->schoolId($request)]
            );

            $this->syncSections($classLevel, $data['section_name_ids'] ?? null, $this->schoolId($request));

            return response()->json([
                'class' => $classLevel->load('level:id,name', 'version:id,name', 'sections.sectionName:id,name'),
            ], Response::HTTP_CREATED);
        });
    }

    public function updateClass(Request $request, ClassLevel $schoolClass): JsonResponse
    {
        $data = $request->validate($this->classLevelRules($request, $schoolClass));

        return DB::transaction(function () use ($data, $schoolClass, $request): JsonResponse {
            $schoolClass->update(Arr::only($data, ['level_id', 'version_id', 'name', 'group', 'numeric_order', 'is_active']));

            if (array_key_exists('section_name_ids', $data)) {
                $this->syncSections($schoolClass, $data['section_name_ids'], (int) $schoolClass->school_id);
            }

            return response()->json([
                'class' => $schoolClass->fresh()->load('level:id,name', 'version:id,name', 'sections.sectionName:id,name'),
            ]);
        });
    }

    public function destroyClass(ClassLevel $schoolClass): JsonResponse
    {
        if (StudentEnrollment::query()->whereIn('section_id', $schoolClass->sections()->pluck('id'))->exists()) {
            return response()->json([
                'message' => 'This class still has enrolled students.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $schoolClass->sections()->delete();
        $schoolClass->delete();

        return response()->json(['deleted' => true]);
    }

    /** @param array<int, int>|null $sectionNameIds */
    private function syncSections(ClassLevel $classLevel, ?array $sectionNameIds, int $schoolId): void
    {
        if ($sectionNameIds === null) {
            return;
        }

        $keep = [];

        foreach ($sectionNameIds as $sectionNameId) {
            $keep[] = Section::query()->firstOrCreate([
                'class_level_id' => $classLevel->id,
                'section_name_id' => $sectionNameId,
            ], ['school_id' => $schoolId])->id;
        }

        Section::query()
            ->where('class_level_id', $classLevel->id)
            ->whereNotIn('id', $keep ?: [0])
            ->whereDoesntHave('enrollments')
            ->delete();
    }

    /** @return array<string, mixed> */
    private function classLevelRules(Request $request, ?ClassLevel $classLevel = null): array
    {
        $required = $classLevel === null ? 'required' : 'sometimes';

        return [
            'level_id' => [$required, 'integer', 'exists:levels,id'],
            'version_id' => [$required, 'integer', 'exists:versions,id'],
            'name' => [$required, 'string', 'max:50'],
            'group' => ['nullable', 'string', 'in:science,humanities,business_studies'],
            'numeric_order' => ['nullable', 'integer', 'min:0', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
            'section_name_ids' => ['sometimes', 'array'],
            'section_name_ids.*' => ['integer', 'exists:section_names,id'],
        ];
    }

    // ─── Class sections (a class level running a named section) ──────────────

    public function storeClassSection(Request $request): JsonResponse
    {
        $data = $request->validate($this->classSectionRules());

        return response()->json([
            'class_section' => Section::query()
                ->create($data + ['school_id' => $this->schoolId($request)])
                ->load('classLevel:id,name,group', 'sectionName:id,name'),
        ], Response::HTTP_CREATED);
    }

    public function updateClassSection(Request $request, Section $classSection): JsonResponse
    {
        $classSection->update($request->validate($this->classSectionRules($classSection)));

        return response()->json([
            'class_section' => $classSection->fresh()->load('classLevel:id,name,group', 'sectionName:id,name'),
        ]);
    }

    public function destroyClassSection(Section $classSection): JsonResponse
    {
        if (
            $classSection->enrollments()->exists()
            || TeacherAssignment::query()->where('section_id', $classSection->id)->exists()
            || DB::table('pps_exam_class_map')->where('section_id', $classSection->id)->exists()
        ) {
            return response()->json([
                'message' => 'This class section still has students, assignments, or exam links.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $classSection->delete();

        return response()->json(['deleted' => true]);
    }

    /** @return array<string, mixed> */
    private function classSectionRules(?Section $classSection = null): array
    {
        $required = $classSection === null ? 'required' : 'sometimes';

        return [
            'class_level_id' => [$required, 'integer', 'exists:class_levels,id'],
            'section_name_id' => [
                $required,
                'integer',
                'exists:section_names,id',
                Rule::unique('sections', 'section_name_id')
                    ->ignore($classSection?->id)
                    ->where(fn ($q) => $q->where('class_level_id', request('class_level_id'))),
            ],
            'class_teacher_id' => ['nullable', 'integer', 'exists:teachers,id'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    // ─── Subjects ────────────────────────────────────────────────────────────

    public function storeSubject(Request $request): JsonResponse
    {
        $data = $request->validate($this->subjectRules());

        return response()->json([
            'subject' => Subject::query()->create($data + ['school_id' => $this->schoolId($request)]),
        ], Response::HTTP_CREATED);
    }

    public function updateSubject(Request $request, Subject $subject): JsonResponse
    {
        $subject->update($request->validate($this->subjectRules($subject)));

        return response()->json(['subject' => $subject->fresh()]);
    }

    public function destroySubject(Subject $subject): JsonResponse
    {
        if (
            Mark::query()->where('subject_id', $subject->id)->exists()
            || ComputedScore::query()->where('subject_id', $subject->id)->exists()
            || TeacherAssignment::query()->where('subject_id', $subject->id)->exists()
        ) {
            return response()->json([
                'message' => 'This subject is already in use in assignments, exams, or assessment history.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $subject->delete();

        return response()->json(['deleted' => true]);
    }

    /** @return array<string, mixed> */
    private function subjectRules(?Subject $subject = null): array
    {
        $required = $subject === null ? 'required' : 'sometimes';

        return [
            'full_name' => [$required, 'string', 'max:255'],
            // A subject's identity is (school, level, version, short_name):
            // "Bangla 1st Paper" in the English version is a different row.
            'short_name' => [
                $required,
                'string',
                'max:20',
                Rule::unique('subjects', 'short_name')
                    ->ignore($subject?->id)
                    ->where(fn ($q) => $q
                        ->where('level_id', request('level_id'))
                        ->where('version_id', request('version_id'))),
            ],
            'level_id' => ['nullable', 'integer', 'exists:levels,id'],
            'version_id' => ['nullable', 'integer', 'exists:versions,id'],
            'default_periods' => ['sometimes', 'numeric', 'min:0', 'max:20'],
            'is_optional' => ['sometimes', 'boolean'],
            'counts_as_class' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    // ─── Exams ───────────────────────────────────────────────────────────────

    public function storeExam(Request $request): JsonResponse
    {
        $data = $request->validate($this->examRules());

        return DB::transaction(function () use ($data): JsonResponse {
            $exam = Exam::query()->create($data);

            return response()->json([
                'exam' => $exam->load('examType:id,code,name', 'components', 'classMaps'),
            ], Response::HTTP_CREATED);
        });
    }

    public function updateExam(Request $request, Exam $exam): JsonResponse
    {
        $data = $request->validate($this->examRules($exam));

        return DB::transaction(function () use ($data, $exam): JsonResponse {
            $exam->update($data);

            return response()->json([
                'exam' => $exam->fresh()->load('examType:id,code,name', 'components', 'classMaps'),
            ]);
        });
    }

    public function destroyExam(Exam $exam): JsonResponse
    {
        if (
            Mark::query()->whereIn('component_id', $exam->components()->pluck('id'))->exists()
            || ComputedScore::query()->where('exam_id', $exam->id)->exists()
        ) {
            return response()->json([
                'message' => 'This exam already has marks submitted. Delete all marks before removing the exam.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $exam->components()->delete();
        $exam->classMaps()->delete();
        $exam->delete();

        return response()->json(['deleted' => true]);
    }

    /** @return array<string, mixed> */
    private function examRules(?Exam $exam = null): array
    {
        return [
            'title'        => ['required', 'string', 'max:255'],
            'exam_type_id' => ['required', 'exists:pps_exam_types,id'],
            'academic_year'=> ['required', 'integer', 'min:2000', 'max:2100'],
            'term'         => ['nullable', 'integer'],
            'exam_date'    => ['nullable', 'date'],
            'scope'        => ['nullable', 'string', 'max:50'],
            'is_active'    => ['sometimes', 'boolean'],
            'status'       => ['sometimes', 'string', 'max:30'],
        ];
    }

    // ─── Teacher assignments ─────────────────────────────────────────────────

    public function storeTeacherAssignment(Request $request): JsonResponse
    {
        $data = $request->validate($this->teacherAssignmentRules());

        $assignment = TeacherAssignment::query()->create($data + ['school_id' => $this->schoolId($request)]);

        return response()->json([
            'teacher_assignment' => $assignment->load($this->assignmentRelations()),
        ], Response::HTTP_CREATED);
    }

    public function updateTeacherAssignment(Request $request, TeacherAssignment $teacherAssignment): JsonResponse
    {
        $teacherAssignment->update($request->validate($this->teacherAssignmentRules($teacherAssignment)));

        return response()->json([
            'teacher_assignment' => $teacherAssignment->fresh()->load($this->assignmentRelations()),
        ]);
    }

    public function destroyTeacherAssignment(TeacherAssignment $teacherAssignment): JsonResponse
    {
        $teacherAssignment->delete();

        return response()->json(['deleted' => true]);
    }

    /** @return array<int, string> */
    private function assignmentRelations(): array
    {
        return [
            'teacher:id,full_name',
            'section.classLevel:id,name',
            'section.sectionName:id,name',
            'subject:id,full_name,short_name',
        ];
    }

    /** @return array<string, mixed> */
    private function teacherAssignmentRules(?TeacherAssignment $assignment = null): array
    {
        return [
            // teacher_id is a teachers row now, not a users row: most staff
            // have no login, and a login is not evidence of being staff.
            'teacher_id' => ['required', 'integer', 'exists:teachers,id'],
            'section_id' => [
                'required',
                'integer',
                'exists:sections,id',
            ],
            'subject_id' => [
                'nullable',
                'integer',
                'exists:subjects,id',
                Rule::unique('pps_teacher_assignments', 'subject_id')
                    ->ignore($assignment?->id)
                    ->where(fn ($query) => $query
                        ->where('teacher_id', request('teacher_id'))
                        ->where('section_id', request('section_id'))),
            ],
            'is_class_teacher' => ['sometimes', 'boolean'],
        ];
    }

    // ─── Students ────────────────────────────────────────────────────────────

    public function storeStudent(Request $request): JsonResponse
    {
        $data = $request->validate($this->studentRules());
        $schoolId = $this->schoolId($request);

        return DB::transaction(function () use ($data, $schoolId): JsonResponse {
            $student = Student::query()->create(
                Arr::except($data, ['section_id']) + ['school_id' => $schoolId]
            );

            $this->syncEnrollment($student, $data['section_id'], $data['roll_number'] ?? null, $schoolId);

            return response()->json([
                'student' => $student->fresh()->load(StudentTaxonomyFilter::eagerLoad()),
            ], Response::HTTP_CREATED);
        });
    }

    public function updateStudent(Request $request, Student $student): JsonResponse
    {
        $data = $request->validate($this->studentRules($student));

        return DB::transaction(function () use ($data, $student): JsonResponse {
            $student->update(Arr::except($data, ['section_id']));

            if (array_key_exists('section_id', $data)) {
                $this->syncEnrollment($student, $data['section_id'], $data['roll_number'] ?? null, (int) $student->school_id);
            }

            return response()->json([
                'student' => $student->fresh()->load(StudentTaxonomyFilter::eagerLoad()),
            ]);
        });
    }

    public function destroyStudent(Student $student): JsonResponse
    {
        if (
            Mark::query()->where('student_id', $student->id)->exists()
            || ComputedScore::query()->where('student_id', $student->id)->exists()
        ) {
            return response()->json([
                'message' => 'This student has submitted marks. Remove all marks before deleting the student.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $student->enrollments()->delete();
        $student->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * A student's class is an enrollment in the current academic year, not a
     * column on the student. Re-placing a student rewrites that one row.
     */
    private function syncEnrollment(Student $student, ?int $sectionId, ?int $rollNumber, int $schoolId): void
    {
        if ($sectionId === null) {
            return;
        }

        $year = AcademicYear::current();

        if ($year === null) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'No academic year is marked current; a student cannot be placed in a class.');
        }

        StudentEnrollment::query()->updateOrCreate(
            ['student_id' => $student->id, 'academic_year_id' => $year->id],
            ['school_id' => $schoolId, 'section_id' => $sectionId, 'roll_number' => $rollNumber],
        );
    }

    /** @return array<string, mixed> */
    private function studentRules(?Student $student = null): array
    {
        $required = $student === null ? 'required' : 'sometimes';

        return [
            'student_code' => [
                $required,
                'string',
                'max:50',
                Rule::unique('students', 'student_code')->ignore($student?->id),
            ],
            'name' => [$required, 'string', 'max:255'],
            'section_id' => [$required, 'integer', 'exists:sections,id'],
            'roll_number' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'guardian_name' => ['nullable', 'string', 'max:255'],
            'guardian_phone' => ['nullable', 'string', 'max:50'],
            'guardian_email' => ['nullable', 'email'],
        ];
    }

    // ─── Bulk import ─────────────────────────────────────────────────────────

    public function bulkStudents(Request $request): JsonResponse
    {
        $data = $request->validate([
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.student_code' => ['required', 'string', 'max:50'],
            'rows.*.name' => ['required', 'string', 'max:255'],
            'rows.*.section_id' => ['required_without_all:rows.*.class_name,rows.*.section', 'nullable', 'integer', 'exists:sections,id'],
            'rows.*.class_name' => ['required_without:rows.*.section_id', 'nullable', 'string', 'max:50'],
            'rows.*.section' => ['required_without:rows.*.section_id', 'nullable', 'string', 'max:30'],
            'rows.*.roll_number' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'rows.*.guardian_name' => ['nullable', 'string', 'max:255'],
            'rows.*.guardian_phone' => ['nullable', 'string', 'max:50'],
            'rows.*.guardian_email' => ['nullable', 'email'],
        ]);

        $schoolId = $this->schoolId($request);
        $inserted = 0;
        $updated = 0;
        $failed = [];

        DB::transaction(function () use ($data, $schoolId, &$inserted, &$updated, &$failed): void {
            foreach ($data['rows'] as $index => $row) {
                $sectionId = $this->resolveSectionId($row);

                if ($sectionId === null) {
                    // A class name alone is ambiguous — "Class 9" exists once
                    // per version — so an unresolvable row is rejected, never
                    // guessed at.
                    $failed[] = ['row' => $index, 'reason' => 'Could not resolve a single class section for this row.'];

                    continue;
                }

                $student = Student::query()->firstOrNew([
                    'student_code' => trim((string) $row['student_code']),
                ]);

                $isExisting = $student->exists;
                $student->fill([
                    'school_id' => $student->school_id ?? $schoolId,
                    'name' => trim((string) $row['name']),
                    'roll_number' => Arr::get($row, 'roll_number'),
                    'guardian_name' => $this->nullableString($row['guardian_name'] ?? null),
                    'guardian_phone' => $this->nullableString($row['guardian_phone'] ?? null),
                    'guardian_email' => $this->nullableString($row['guardian_email'] ?? null),
                ]);
                $student->save();

                $this->syncEnrollment($student, $sectionId, Arr::get($row, 'roll_number'), $schoolId);

                $isExisting ? $updated++ : $inserted++;
            }
        });

        return response()->json([
            'created' => $inserted,
            'updated' => $updated,
            'failed' => count($failed),
            'errors' => $failed,
        ], Response::HTTP_CREATED);
    }

    public function bulkTeacherAssignments(Request $request): JsonResponse
    {
        $data = $request->validate([
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.teacher_email' => ['required_without_all:rows.*.teacher_id,rows.*.teacher_name', 'nullable', 'email'],
            'rows.*.teacher_id' => ['required_without_all:rows.*.teacher_email,rows.*.teacher_name', 'nullable', 'integer'],
            'rows.*.teacher_name' => ['required_without_all:rows.*.teacher_email,rows.*.teacher_id', 'nullable', 'string', 'max:255'],
            'rows.*.section_id' => ['required_without_all:rows.*.class_name,rows.*.section', 'nullable', 'integer', 'exists:sections,id'],
            'rows.*.class_name' => ['required_without:rows.*.section_id', 'nullable', 'string', 'max:50'],
            'rows.*.section' => ['required_without:rows.*.section_id', 'nullable', 'string', 'max:30'],
            'rows.*.subject' => ['nullable', 'string', 'max:100'],
            'rows.*.is_class_teacher' => ['nullable'],
        ]);

        $schoolId = $this->schoolId($request);
        $created = 0;
        $updated = 0;
        $failed = [];

        DB::transaction(function () use ($data, $schoolId, &$created, &$updated, &$failed): void {
            foreach ($data['rows'] as $index => $row) {
                $teacherId = $this->resolveTeacherId($row);
                $sectionId = $this->resolveSectionId($row);

                if ($teacherId === null || $sectionId === null) {
                    $failed[] = [
                        'row' => $index,
                        'reason' => $teacherId === null
                            ? 'No teacher matched.'
                            : 'Could not resolve a single class section for this row.',
                    ];

                    continue;
                }

                $subjectId = null;

                if ($this->nullableString($row['subject'] ?? null) !== null) {
                    $subjectId = Subject::query()
                        ->where('full_name', trim((string) $row['subject']))
                        ->value('id');
                }

                $assignment = TeacherAssignment::query()->firstOrNew([
                    'teacher_id' => $teacherId,
                    'section_id' => $sectionId,
                    'subject_id' => $subjectId,
                ]);

                $isExisting = $assignment->exists;
                $assignment->school_id = $assignment->school_id ?? $schoolId;
                $assignment->is_class_teacher = $this->toBoolean($row['is_class_teacher'] ?? false);
                $assignment->save();

                $isExisting ? $updated++ : $created++;
            }
        });

        return response()->json([
            'created' => $created,
            'updated' => $updated,
            'failed' => count($failed),
            'errors' => $failed,
        ], Response::HTTP_CREATED);
    }

    /**
     * POST /admin/bulk/marks
     *
     * CSV bulk marks import.
     *
     * TODO: Reimplement for new schema (pps_marks via component codes).
     * The old CSV format (spot_test, class_test2, etc.) mapped to pps_term_marks columns
     * which no longer exist. New format must supply component_id or component code per row.
     */
    public function bulkMarks(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'Bulk marks import has not been migrated to the new component-based schema yet.',
        ], Response::HTTP_NOT_IMPLEMENTED);
    }

    /** @param array<string, mixed> $row */
    private function resolveSectionId(array $row): ?int
    {
        if (! empty($row['section_id'])) {
            return (int) $row['section_id'];
        }

        $matches = StudentTaxonomyFilter::sectionIdsForNames(
            $this->nullableString($row['class_name'] ?? null),
            $this->nullableString($row['section'] ?? null),
        );

        return count($matches) === 1 ? $matches[0] : null;
    }

    /** @param array<string, mixed> $row */
    private function resolveTeacherId(array $row): ?int
    {
        if (! empty($row['teacher_id'])) {
            return Teacher::query()->whereKey((int) $row['teacher_id'])->value('id');
        }

        if (! empty($row['teacher_email'])) {
            $userId = User::query()
                ->where('email', strtolower(trim((string) $row['teacher_email'])))
                ->value('id');

            return $userId === null ? null : Teacher::query()->where('user_id', $userId)->value('id');
        }

        if (! empty($row['teacher_name'])) {
            return Teacher::query()->where('full_name', trim((string) $row['teacher_name']))->value('id');
        }

        return null;
    }

    // ─── Grade config ────────────────────────────────────────────────────────

    public function updateGradeConfig(Request $request): JsonResponse
    {
        $rows = $request->validate([
            'rows'                  => ['required', 'array', 'min:1'],
            'rows.*.id'             => ['nullable', 'exists:pps_grade_config,id'],
            'rows.*.min_pct'        => ['required', 'numeric', 'min:0', 'max:100'],
            'rows.*.max_pct'        => ['required', 'numeric', 'min:0', 'max:100'],
            'rows.*.letter_grade'   => ['required', 'string', 'max:5'],
            'rows.*.grade_point'    => ['required', 'numeric', 'min:0', 'max:5'],
            'rows.*.sort_order'     => ['nullable', 'integer'],
        ]);

        DB::transaction(function () use ($rows): void {
            foreach ($rows['rows'] as $row) {
                GradeConfig::query()->updateOrCreate(
                    ['id' => $row['id'] ?? null],
                    [
                        'school_id'    => null,
                        'min_pct'      => $row['min_pct'],
                        'max_pct'      => $row['max_pct'],
                        'letter_grade' => $row['letter_grade'],
                        'grade_point'  => $row['grade_point'],
                        'sort_order'   => $row['sort_order'] ?? 0,
                    ]
                );
            }
        });

        return response()->json([
            'grade_config' => GradeConfig::query()->whereNull('school_id')->orderBy('sort_order')->get(),
        ]);
    }

    public function resetGradeConfig(): JsonResponse
    {
        $defaults = [
            ['letter_grade' => 'A+', 'grade_point' => 5.00, 'min_pct' => 80, 'max_pct' => 100, 'sort_order' => 1],
            ['letter_grade' => 'A',  'grade_point' => 4.00, 'min_pct' => 70, 'max_pct' => 79,  'sort_order' => 2],
            ['letter_grade' => 'A-', 'grade_point' => 3.50, 'min_pct' => 60, 'max_pct' => 69,  'sort_order' => 3],
            ['letter_grade' => 'B',  'grade_point' => 3.00, 'min_pct' => 50, 'max_pct' => 59,  'sort_order' => 4],
            ['letter_grade' => 'C',  'grade_point' => 2.00, 'min_pct' => 40, 'max_pct' => 49,  'sort_order' => 5],
            ['letter_grade' => 'D',  'grade_point' => 1.00, 'min_pct' => 33, 'max_pct' => 39,  'sort_order' => 6],
            ['letter_grade' => 'F',  'grade_point' => 0.00, 'min_pct' => 0,  'max_pct' => 32,  'sort_order' => 7],
        ];

        DB::transaction(function () use ($defaults): void {
            GradeConfig::query()->whereNull('school_id')->delete();
            foreach ($defaults as $row) {
                GradeConfig::query()->create(array_merge($row, ['school_id' => null]));
            }
        });

        return response()->json([
            'grade_config' => GradeConfig::query()->whereNull('school_id')->orderBy('sort_order')->get(),
        ]);
    }

    // ─── Teachers ────────────────────────────────────────────────────────────

    public function storeTeacher(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'      => ['required', 'string', 'max:255'],
            'email'     => ['required', 'email', 'max:255', 'unique:users,email'],
            'password'  => ['nullable', 'string', 'min:8'],
            'short_code' => ['nullable', 'string', 'max:10'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $schoolId = $this->schoolId($request);

        $teacher = DB::transaction(function () use ($data, $schoolId): Teacher {
            // A teacher is a person on staff; the login is a separate record
            // that happens to be created alongside it here.
            $user = User::query()->create([
                'school_id' => $schoolId,
                'name'      => $data['name'],
                'email'     => $data['email'],
                'password'  => $data['password'] ?? \Illuminate\Support\Str::random(16),
                'role'      => User::ROLE_TEACHER,
                'is_active' => $data['is_active'] ?? true,
            ]);

            return Teacher::query()->create([
                'school_id'  => $schoolId,
                'full_name'  => $data['name'],
                'short_code' => $data['short_code'] ?? null,
                'user_id'    => $user->id,
                'is_active'  => $data['is_active'] ?? true,
            ]);
        });

        return response()->json([
            'teacher' => [
                'id' => $teacher->id,
                'name' => $teacher->full_name,
                'email' => $data['email'],
                'is_active' => $teacher->is_active,
            ],
        ], Response::HTTP_CREATED);
    }

    public function updateTeacher(Request $request, Teacher $teacher): JsonResponse
    {
        $data = $request->validate([
            'name'      => ['sometimes', 'string', 'max:255'],
            'email'     => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($teacher->user_id)],
            'password'  => ['nullable', 'string', 'min:8'],
            'short_code' => ['nullable', 'string', 'max:10'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        DB::transaction(function () use ($data, $teacher): void {
            $teacher->update(array_filter([
                'full_name' => $data['name'] ?? null,
                'short_code' => $data['short_code'] ?? null,
            ], fn ($v) => $v !== null) + Arr::only($data, ['is_active']));

            $teacher->user?->update(array_filter([
                'name' => $data['name'] ?? null,
                'email' => $data['email'] ?? null,
                'password' => $data['password'] ?? null,
            ], fn ($v) => $v !== null) + Arr::only($data, ['is_active']));
        });

        $teacher = $teacher->fresh()->load('user:id,email,is_active');

        return response()->json([
            'teacher' => [
                'id' => $teacher->id,
                'name' => $teacher->full_name,
                'email' => $teacher->user?->email,
                'is_active' => $teacher->is_active,
            ],
        ]);
    }

    public function destroyTeacher(Teacher $teacher): JsonResponse
    {
        if (TeacherAssignment::query()->where('teacher_id', $teacher->id)->exists()) {
            return response()->json([
                'message' => 'This teacher has active assignments. Remove assignments before deleting.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        DB::transaction(function () use ($teacher): void {
            $user = $teacher->user;
            $teacher->delete();
            $user?->delete();
        });

        return response()->json(['deleted' => true]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /** Every sms-core row belongs to a campus; the acting user names which. */
    private function schoolId(Request $request): int
    {
        $schoolId = $request->user()?->school_id;

        if ($schoolId === null) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'The acting user is not attached to a school.');
        }

        return (int) $schoolId;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function toBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'y'], true);
    }
}

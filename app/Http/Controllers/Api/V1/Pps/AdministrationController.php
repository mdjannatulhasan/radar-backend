<?php

namespace App\Http\Controllers\Api\V1\Pps;

use App\Http\Controllers\Controller;
use App\Models\Pps\Assessment;
use App\Models\Pps\ClassSection;
use App\Models\Pps\Department;
use App\Models\Pps\ExamDefinition;
use App\Models\Pps\Subject;
use App\Models\Pps\TeacherAssignment;
use App\Models\Student;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class AdministrationController extends Controller
{
    private const ASSESSMENT_TYPES = ['class_test', 'mid_term', 'final', 'assignment', 'quiz', 'practical'];

    public function overview(): JsonResponse
    {
        return response()->json([
            'summary' => [
                'departments' => Department::query()->count(),
                'class_sections' => ClassSection::query()->count(),
                'subjects' => Subject::query()->count(),
                'exams' => ExamDefinition::query()->count(),
                'students' => Student::query()->count(),
                'teachers' => User::query()->where('role', 'teacher')->count(),
                'teacher_assignments' => TeacherAssignment::query()->count(),
            ],
            'teachers' => User::query()
                ->where('role', 'teacher')
                ->orderBy('name')
                ->get(['id', 'name', 'email']),
            'departments' => Department::query()
                ->orderBy('name')
                ->get(),
            'class_sections' => ClassSection::query()
                ->with('department:id,name,code')
                ->orderByRaw('CAST(class_name AS INTEGER) ASC NULLS LAST')
                ->orderBy('section')
                ->get(),
            'subjects' => Subject::query()
                ->with('department:id,name,code')
                ->orderBy('name')
                ->get(),
            'exams' => ExamDefinition::query()
                ->with('department:id,name,code', 'subject:id,name,code')
                ->orderByDesc('exam_date')
                ->orderBy('title')
                ->get(),
            'teacher_assignments' => TeacherAssignment::query()
                ->with('teacher:id,name,email')
                ->orderBy('class_name')
                ->orderBy('section')
                ->orderBy('subject')
                ->get(),
            'students' => Student::query()
                ->orderBy('class_name')
                ->orderBy('section')
                ->orderBy('roll_number')
                ->limit(300)
                ->get(),
        ]);
    }

    public function storeDepartment(Request $request): JsonResponse
    {
        $data = $request->validate($this->departmentRules());

        return response()->json([
            'department' => Department::query()->create($data),
        ], Response::HTTP_CREATED);
    }

    public function updateDepartment(Request $request, Department $department): JsonResponse
    {
        $data = $request->validate($this->departmentRules($department));
        $department->update($data);

        return response()->json([
            'department' => $department->fresh(),
        ]);
    }

    public function destroyDepartment(Department $department): JsonResponse
    {
        if (
            $department->classSections()->exists()
            || $department->subjects()->exists()
            || ExamDefinition::query()->where('department_id', $department->id)->exists()
        ) {
            return response()->json([
                'message' => 'This department is still linked to classes, subjects, or exams.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $department->delete();

        return response()->json(['deleted' => true]);
    }

    public function storeClassSection(Request $request): JsonResponse
    {
        $data = $request->validate($this->classSectionRules());

        return response()->json([
            'class_section' => ClassSection::query()->create($data)->load('department:id,name,code'),
        ], Response::HTTP_CREATED);
    }

    public function updateClassSection(Request $request, ClassSection $classSection): JsonResponse
    {
        $data = $request->validate($this->classSectionRules($classSection));
        $classSection->update($data);

        return response()->json([
            'class_section' => $classSection->fresh()->load('department:id,name,code'),
        ]);
    }

    public function destroyClassSection(ClassSection $classSection): JsonResponse
    {
        if (
            Student::query()->where('class_name', $classSection->class_name)->where('section', $classSection->section)->exists()
            || TeacherAssignment::query()->where('class_name', $classSection->class_name)->where('section', $classSection->section)->exists()
            || ExamDefinition::query()->where('class_name', $classSection->class_name)->where('section', $classSection->section)->exists()
        ) {
            return response()->json([
                'message' => 'This class section still has students, assignments, or exam links.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $classSection->delete();

        return response()->json(['deleted' => true]);
    }

    public function storeSubject(Request $request): JsonResponse
    {
        $data = $request->validate($this->subjectRules());

        return response()->json([
            'subject' => Subject::query()->create($data)->load('department:id,name,code'),
        ], Response::HTTP_CREATED);
    }

    public function updateSubject(Request $request, Subject $subject): JsonResponse
    {
        $data = $request->validate($this->subjectRules($subject));
        $subject->update($data);

        return response()->json([
            'subject' => $subject->fresh()->load('department:id,name,code'),
        ]);
    }

    public function destroySubject(Subject $subject): JsonResponse
    {
        if (
            ExamDefinition::query()->where('subject_id', $subject->id)->exists()
            || TeacherAssignment::query()->where('subject', $subject->name)->exists()
            || Assessment::query()->where('subject', $subject->name)->exists()
        ) {
            return response()->json([
                'message' => 'This subject is already in use in assignments, exams, or assessment history.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $subject->delete();

        return response()->json(['deleted' => true]);
    }

    public function storeExam(Request $request): JsonResponse
    {
        $data = $request->validate($this->examRules());

        return response()->json([
            'exam' => ExamDefinition::query()->create($data)->load('department:id,name,code', 'subject:id,name,code'),
        ], Response::HTTP_CREATED);
    }

    public function updateExam(Request $request, ExamDefinition $exam): JsonResponse
    {
        $data = $request->validate($this->examRules($exam));
        $exam->update($data);

        return response()->json([
            'exam' => $exam->fresh()->load('department:id,name,code', 'subject:id,name,code'),
        ]);
    }

    public function destroyExam(ExamDefinition $exam): JsonResponse
    {
        $exam->delete();

        return response()->json(['deleted' => true]);
    }

    public function storeTeacherAssignment(Request $request): JsonResponse
    {
        $data = $request->validate($this->teacherAssignmentRules());

        $teacher = User::query()
            ->whereKey($data['teacher_id'])
            ->where('role', 'teacher')
            ->first();

        if (! $teacher) {
            return response()->json(['message' => 'The selected user is not a teacher.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $assignment = TeacherAssignment::query()->create($data);

        return response()->json([
            'teacher_assignment' => $assignment->load('teacher:id,name,email'),
        ], Response::HTTP_CREATED);
    }

    public function updateTeacherAssignment(Request $request, TeacherAssignment $teacherAssignment): JsonResponse
    {
        $data = $request->validate($this->teacherAssignmentRules($teacherAssignment));

        $teacher = User::query()
            ->whereKey($data['teacher_id'])
            ->where('role', 'teacher')
            ->first();

        if (! $teacher) {
            return response()->json(['message' => 'The selected user is not a teacher.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $teacherAssignment->update($data);

        return response()->json([
            'teacher_assignment' => $teacherAssignment->fresh()->load('teacher:id,name,email'),
        ]);
    }

    public function destroyTeacherAssignment(TeacherAssignment $teacherAssignment): JsonResponse
    {
        $teacherAssignment->delete();

        return response()->json(['deleted' => true]);
    }

    public function storeStudent(Request $request): JsonResponse
    {
        $data = $request->validate($this->studentRules());

        return response()->json([
            'student' => Student::query()->create($data),
        ], Response::HTTP_CREATED);
    }

    public function updateStudent(Request $request, Student $student): JsonResponse
    {
        $data = $request->validate($this->studentRules($student));
        $student->update($data);

        return response()->json([
            'student' => $student->fresh(),
        ]);
    }

    public function destroyStudent(Student $student): JsonResponse
    {
        $student->delete();

        return response()->json(['deleted' => true]);
    }

    public function bulkStudents(Request $request): JsonResponse
    {
        $data = $request->validate([
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.student_code' => ['required', 'string', 'max:50'],
            'rows.*.name' => ['required', 'string', 'max:255'],
            'rows.*.class_name' => ['required', 'string', 'max:20'],
            'rows.*.section' => ['required', 'string', 'max:10'],
            'rows.*.roll_number' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'rows.*.guardian_name' => ['nullable', 'string', 'max:255'],
            'rows.*.guardian_phone' => ['nullable', 'string', 'max:50'],
            'rows.*.guardian_email' => ['nullable', 'email'],
        ]);

        $inserted = 0;
        $updated = 0;

        DB::transaction(function () use ($data, &$inserted, &$updated): void {
            foreach ($data['rows'] as $row) {
                $student = Student::query()->firstOrNew([
                    'student_code' => trim((string) $row['student_code']),
                ]);

                $isExisting = $student->exists;
                $student->fill([
                    'name' => trim((string) $row['name']),
                    'class_name' => trim((string) $row['class_name']),
                    'section' => trim((string) $row['section']),
                    'roll_number' => Arr::get($row, 'roll_number'),
                    'guardian_name' => $this->nullableString($row['guardian_name'] ?? null),
                    'guardian_phone' => $this->nullableString($row['guardian_phone'] ?? null),
                    'guardian_email' => $this->nullableString($row['guardian_email'] ?? null),
                ]);
                $student->save();

                $isExisting ? $updated++ : $inserted++;
            }
        });

        return response()->json([
            'created' => $inserted,
            'updated' => $updated,
        ], Response::HTTP_CREATED);
    }

    public function bulkTeacherAssignments(Request $request): JsonResponse
    {
        $data = $request->validate([
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.teacher_email' => ['required_without:rows.*.teacher_id', 'nullable', 'email'],
            'rows.*.teacher_id' => ['required_without:rows.*.teacher_email', 'nullable', 'integer'],
            'rows.*.class_name' => ['required', 'string', 'max:20'],
            'rows.*.section' => ['required', 'string', 'max:10'],
            'rows.*.subject' => ['required', 'string', 'max:100'],
            'rows.*.is_class_teacher' => ['nullable'],
        ]);

        $created = 0;
        $updated = 0;

        DB::transaction(function () use ($data, &$created, &$updated): void {
            foreach ($data['rows'] as $row) {
                $teacher = User::query()
                    ->when(
                        ! empty($row['teacher_id']),
                        fn ($query) => $query->whereKey((int) $row['teacher_id']),
                        fn ($query) => $query->where('email', strtolower(trim((string) $row['teacher_email'])))
                    )
                    ->where('role', 'teacher')
                    ->firstOrFail();

                $assignment = TeacherAssignment::query()->firstOrNew([
                    'teacher_id' => $teacher->id,
                    'class_name' => trim((string) $row['class_name']),
                    'section' => trim((string) $row['section']),
                    'subject' => trim((string) $row['subject']),
                ]);

                $isExisting = $assignment->exists;
                $assignment->is_class_teacher = $this->toBoolean($row['is_class_teacher'] ?? false);
                $assignment->save();

                $isExisting ? $updated++ : $created++;
            }
        });

        return response()->json([
            'created' => $created,
            'updated' => $updated,
        ], Response::HTTP_CREATED);
    }

    private function departmentRules(?Department $department = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'nullable',
                'string',
                'max:30',
                Rule::unique('pps_departments', 'code')->ignore($department?->id),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    private function classSectionRules(?ClassSection $classSection = null): array
    {
        return [
            'class_name' => [
                'required',
                'string',
                'max:20',
                Rule::unique('pps_class_sections')
                    ->ignore($classSection?->id)
                    ->where(fn ($query) => $query->where('section', request('section'))),
            ],
            'section' => ['required', 'string', 'max:10'],
            'department_id' => ['nullable', 'exists:pps_departments,id'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    private function subjectRules(?Subject $subject = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'nullable',
                'string',
                'max:30',
                Rule::unique('pps_subjects', 'code')->ignore($subject?->id),
            ],
            'department_id' => ['nullable', 'exists:pps_departments,id'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    private function examRules(?ExamDefinition $exam = null): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'code' => [
                'nullable',
                'string',
                'max:40',
                Rule::unique('pps_exam_definitions', 'code')->ignore($exam?->id),
            ],
            'assessment_type' => ['required', Rule::in(self::ASSESSMENT_TYPES)],
            'term' => ['nullable', 'string', 'max:30'],
            'total_marks' => ['required', 'numeric', 'gt:0'],
            'exam_date' => ['nullable', 'date'],
            'class_name' => ['nullable', 'string', 'max:20'],
            'section' => ['nullable', 'string', 'max:10'],
            'department_id' => ['nullable', 'exists:pps_departments,id'],
            'subject_id' => ['nullable', 'exists:pps_subjects,id'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    private function teacherAssignmentRules(?TeacherAssignment $assignment = null): array
    {
        return [
            'teacher_id' => ['required', 'exists:users,id'],
            'class_name' => ['required', 'string', 'max:20'],
            'section' => ['required', 'string', 'max:10'],
            'subject' => [
                'required',
                'string',
                'max:100',
                Rule::unique('pps_teacher_assignments')
                    ->ignore($assignment?->id)
                    ->where(fn ($query) => $query
                        ->where('teacher_id', request('teacher_id'))
                        ->where('class_name', request('class_name'))
                        ->where('section', request('section'))),
            ],
            'is_class_teacher' => ['sometimes', 'boolean'],
        ];
    }

    private function studentRules(?Student $student = null): array
    {
        return [
            'student_code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('students', 'student_code')->ignore($student?->id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'class_name' => ['required', 'string', 'max:20'],
            'section' => ['required', 'string', 'max:10'],
            'roll_number' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'guardian_name' => ['nullable', 'string', 'max:255'],
            'guardian_phone' => ['nullable', 'string', 'max:50'],
            'guardian_email' => ['nullable', 'email'],
        ];
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

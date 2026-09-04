<?php

namespace App\Http\Controllers\Api\V1\Pps;

use App\Http\Controllers\Controller;
use App\Models\Pps\PerformanceSnapshot;
use App\Models\Pps\PrivateTuition;
use App\Services\Pps\Student360Service;
use App\Support\TeacherScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use SmsCore\Models\Student;
use SmsCore\Models\User;
use Symfony\Component\HttpFoundation\Response;

class Student360Controller extends Controller
{
    public function __construct(private readonly Student360Service $service)
    {
    }

    public function show(Request $request, Student $student): JsonResponse
    {
        $viewer = $this->guardTeacherScope($request, $student);
        $years = min(10, max(1, (int) $request->integer('years', 3)));

        return response()->json($this->service->build($student, $viewer, $this->resolvePeriod($request), $years));
    }

    public function notifyTeachers(Request $request, Student $student): JsonResponse
    {
        $this->guardTeacherScope($request, $student);

        $validated = $request->validate([
            'subject_ids' => ['sometimes', 'array'],
            'subject_ids.*' => ['integer'],
            'include_class_teacher' => ['sometimes', 'boolean'],
            'message' => ['nullable', 'string', 'max:2000'],
        ]);

        $subjectIds = array_map('intval', $validated['subject_ids'] ?? []);
        $includeClassTeacher = (bool) ($validated['include_class_teacher'] ?? false);

        if ($subjectIds === [] && ! $includeClassTeacher) {
            return response()->json(['message' => 'Select at least one subject or the class teacher.'], 422);
        }

        /** @var User $sender */
        $sender = $request->user();
        $sent = $this->service->notifyTeachers(
            $student, $sender, $subjectIds, $includeClassTeacher,
            $validated['message'] ?? null, $this->resolvePeriod($request),
        );

        if ($sent === []) {
            return response()->json(['message' => 'No assigned teacher matched the selection.'], 422);
        }

        return response()->json(['sent' => $sent], Response::HTTP_CREATED);
    }

    public function storeTuition(Request $request, Student $student): JsonResponse
    {
        $this->guardTeacherScope($request, $student);

        $validated = $request->validate([
            'subject_name' => ['required', 'string', 'max:120'],
            'subject_id' => ['nullable', 'integer', 'exists:subjects,id'],
            'teacher_id' => ['nullable', 'integer', 'exists:teachers,id', 'required_without:tutor_name'],
            'tutor_name' => ['nullable', 'string', 'max:120', 'required_without:teacher_id'],
            'hours_per_week' => ['nullable', 'integer', 'min:1', 'max:40'],
            'started_on' => ['nullable', 'date'],
            'ended_on' => ['nullable', 'date', 'after_or_equal:started_on'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $tuition = PrivateTuition::query()->create($validated + [
            'student_id' => $student->id,
            'recorded_by' => $request->user()?->id,
        ]);

        return response()->json(['tuition' => $tuition], Response::HTTP_CREATED);
    }

    public function destroyTuition(Request $request, Student $student, PrivateTuition $tuition): JsonResponse
    {
        $this->guardTeacherScope($request, $student);
        abort_unless($tuition->student_id === $student->id, Response::HTTP_NOT_FOUND);

        $tuition->delete();

        return response()->json(['deleted' => true]);
    }

    private function guardTeacherScope(Request $request, Student $student): ?User
    {
        /** @var User|null $viewer */
        $viewer = $request->user();
        if ($viewer?->hasAnyRole('teacher') && ! TeacherScope::canAccessStudent($viewer, $student)) {
            abort(Response::HTTP_FORBIDDEN, 'You are not assigned to this student.');
        }

        return $viewer;
    }

    /** Same fallback rule as StudentPerformanceController: latest snapshot period if the requested one has none. */
    private function resolvePeriod(Request $request): string
    {
        $requested = $request->string('period')->toString() ?: now()->format('Y-m');

        if (PerformanceSnapshot::where('snapshot_period', $requested)->exists()) {
            return $requested;
        }

        return PerformanceSnapshot::max('snapshot_period') ?? $requested;
    }
}

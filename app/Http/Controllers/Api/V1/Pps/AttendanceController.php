<?php

namespace App\Http\Controllers\Api\V1\Pps;

use App\Http\Controllers\Controller;
use App\Models\Pps\AttendanceRecord;
use App\Models\Student;
use App\Models\User;
use App\Services\Pps\ScoreCalculatorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AttendanceController extends Controller
{
    public function __construct(
        private readonly ScoreCalculatorService $scoreCalculator,
    ) {
    }

    public function bulkStore(Request $request): JsonResponse
    {
        /** @var User|null $viewer */
        $viewer = $request->user();
        $data = $request->validate([
            'date' => ['nullable', 'date', 'before_or_equal:today'],
            'attendances' => ['nullable', 'array', 'min:1'],
            'attendances.*.student_id' => ['required_with:attendances', 'exists:students,id'],
            'attendances.*.status' => ['required_with:attendances', 'in:present,absent,late,excused,leave,sick_leave'],
            'attendances.*.absence_reason' => ['nullable', 'string', 'max:255'],
            'rows' => ['nullable', 'array', 'min:1'],
        ]);

        if (! empty($data['rows'])) {
            return $this->bulkStoreRows($data['rows'], $viewer);
        }

        if (empty($data['attendances']) || empty($data['date'])) {
            return response()->json([
                'message' => 'Provide either rows or the date + attendances payload.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($viewer?->hasAnyRole('teacher')) {
            collect($data['attendances'])->pluck('student_id')->unique()->each(function (int $studentId) use ($viewer): void {
                $student = Student::query()->findOrFail($studentId);

                if (! $viewer->isAssignedToClass($student->class_name, $student->section)) {
                    abort(Response::HTTP_FORBIDDEN, 'You are not assigned to one or more selected classes.');
                }
            });
        }

        $timestamp = now();
        $rows = collect($data['attendances'])
            ->map(fn (array $attendance) => [
                'student_id' => $attendance['student_id'],
                'date' => $data['date'],
                'status' => $this->normalizeAttendanceStatus($attendance['status']),
                'absence_reason' => $attendance['absence_reason'] ?? null,
                'marked_by' => $request->user()?->id,
                'period' => null,
                'created_at' => $timestamp,
            ]);

        AttendanceRecord::query()->upsert(
            $rows->toArray(),
            ['student_id', 'date', 'period'],
            ['status', 'absence_reason', 'marked_by', 'created_at']
        );

        $period = substr($data['date'], 0, 7);
        $rows->pluck('student_id')
            ->unique()
            ->each(fn (int $studentId) => $this->scoreCalculator->calculateForStudent($studentId, $period));

        return response()->json([
            'marked' => $rows->count(),
            'students_recalculated' => $rows->pluck('student_id')->unique()->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User|null $viewer */
        $viewer = $request->user();
        $data = $request->validate([
            'student_id' => ['required', 'exists:students,id'],
            'date' => ['required', 'date', 'before_or_equal:today'],
            'status' => ['required', 'in:present,absent,late,excused,leave,sick_leave'],
            'period' => ['nullable', 'integer', 'min:1', 'max:12'],
            'subject' => ['nullable', 'string', 'max:100'],
            'absence_reason' => ['nullable', 'string', 'max:255'],
        ]);

        $student = Student::query()->findOrFail($data['student_id']);

        if ($viewer?->hasAnyRole('teacher') && ! $viewer->isAssignedToClass($student->class_name, $student->section)) {
            abort(Response::HTTP_FORBIDDEN, 'You are not assigned to this class.');
        }

        $attendance = AttendanceRecord::query()->updateOrCreate(
            [
                'student_id' => $data['student_id'],
                'date' => $data['date'],
                'period' => $data['period'] ?? null,
            ],
            [
                'status' => $this->normalizeAttendanceStatus($data['status']),
                'subject' => $data['subject'] ?? null,
                'absence_reason' => $data['absence_reason'] ?? null,
                'marked_by' => $request->user()?->id,
            ]
        );

        $this->scoreCalculator->calculateForStudent($attendance->student_id, substr($attendance->date->format('Y-m-d'), 0, 7));

        return response()->json($attendance->fresh(), 201);
    }

    public function index(Request $request): JsonResponse
    {
        /** @var User|null $viewer */
        $viewer = $request->user();
        $records = AttendanceRecord::query()
            ->with('student:id,name,class_name,section,roll_number', 'markedBy:id,name')
            ->when($viewer?->hasAnyRole('teacher'), fn ($query) => $query->where('marked_by', $viewer->id))
            ->when($request->filled('student_id'), fn ($query) => $query->where('student_id', $request->integer('student_id')))
            ->when($request->filled('date'), fn ($query) => $query->whereDate('date', $request->date('date')))
            ->orderByDesc('date')
            ->limit(200)
            ->get();

        return response()->json(['data' => $records]);
    }

    private function bulkStoreRows(array $rows, ?User $viewer): JsonResponse
    {
        $errors = [];
        $upserts = collect();
        $timestamp = now();

        foreach (array_values($rows) as $index => $row) {
            $rowNum = $index + 2;
            $student = $this->resolveStudentFromRow($row);
            $status = $this->normalizeAttendanceStatus($row['status'] ?? null);
            $date = trim((string) ($row['date'] ?? ''));

            if (! $student) {
                $errors[] = ['row' => $rowNum, 'message' => 'Each row needs a valid student_id or student_code.'];
                continue;
            }

            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $errors[] = ['row' => $rowNum, 'message' => 'date must be in YYYY-MM-DD format.'];
                continue;
            }

            if (! in_array($status, ['present', 'absent', 'late', 'excused'], true)) {
                $errors[] = ['row' => $rowNum, 'message' => 'status must be present, absent, late, or excused.'];
                continue;
            }

            if ($viewer?->hasAnyRole('teacher') && ! $viewer->isAssignedToClass($student->class_name, $student->section)) {
                $errors[] = ['row' => $rowNum, 'message' => 'You are not assigned to this student or class.'];
                continue;
            }

            $upserts->push([
                'student_id' => $student->id,
                'date' => $date,
                'status' => $status,
                'absence_reason' => $row['absence_reason'] ?? null,
                'marked_by' => $viewer?->id,
                'period' => null,
                'created_at' => $timestamp,
            ]);
        }

        if ($upserts->isNotEmpty()) {
            AttendanceRecord::query()->upsert(
                $upserts->toArray(),
                ['student_id', 'date', 'period'],
                ['status', 'absence_reason', 'marked_by', 'created_at']
            );

            $upserts
                ->groupBy(fn (array $attendance) => substr($attendance['date'], 0, 7))
                ->each(function ($periodRows, $period): void {
                    collect($periodRows)->pluck('student_id')->unique()->each(
                        fn (int $studentId) => $this->scoreCalculator->calculateForStudent($studentId, $period)
                    );
                });
        }

        return response()->json([
            'marked' => $upserts->count(),
            'failed' => count($errors),
            'errors' => $errors,
            'students_recalculated' => $upserts->pluck('student_id')->unique()->values(),
        ], 201);
    }

    private function resolveStudentFromRow(array $row): ?Student
    {
        $studentId = isset($row['student_id']) && is_numeric($row['student_id']) ? (int) $row['student_id'] : null;
        $studentCode = trim((string) ($row['student_code'] ?? ''));

        return Student::query()
            ->when(
                $studentId,
                fn ($query) => $query->whereKey($studentId),
                fn ($query) => $query->where('student_code', $studentCode)
            )
            ->first();
    }

    private function normalizeAttendanceStatus(mixed $status): string
    {
        $status = strtolower(trim((string) $status));

        return match ($status) {
            'leave', 'sick_leave' => 'excused',
            default => $status,
        };
    }
}

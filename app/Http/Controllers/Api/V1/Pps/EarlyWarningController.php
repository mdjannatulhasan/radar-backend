<?php

namespace App\Http\Controllers\Api\V1\Pps;

use App\Http\Controllers\Controller;
use App\Models\Pps\EarlyWarning;
use App\Models\Pps\PpsNotificationLog;
use App\Support\StudentTaxonomyFilter;
use App\Support\TeacherScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use SmsCore\Models\User;
use Symfony\Component\HttpFoundation\Response;

class EarlyWarningController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        $warnings = EarlyWarning::query()
            ->with(array_merge(
                ['student:id,name,student_code,roll_number', 'acknowledgedBy:id,name'],
                StudentTaxonomyFilter::eagerLoadVia('student'),
            ))
            ->when($request->boolean('open', true), fn ($q) => $q->open())
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->string('category')->toString()))
            ->when($user?->hasAnyRole('teacher'), fn ($q) => $q->whereHas('student', fn ($s) => TeacherScope::applyStudentScope($s, $user)))
            ->orderByRaw("CASE category WHEN 'imminent' THEN 1 WHEN 'near' THEN 2 ELSE 3 END")
            ->orderByDesc('projected_risk')
            ->limit(300)
            ->get();

        $recipients = PpsNotificationLog::query()
            ->whereIn('student_id', $warnings->pluck('student_id'))
            ->where('type', 'like', 'early_warning_%')
            ->with('recipient:id,name')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (PpsNotificationLog $log) => $log->student_id.':'.$log->snapshot_period);

        return response()->json([
            'data' => $warnings->map(function (EarlyWarning $w) use ($recipients): array {
                $student = $w->student;
                $logs = $recipients->get($w->student_id.':'.$w->snapshot_period, collect());

                return [
                    'id' => $w->id,
                    'snapshot_period' => $w->snapshot_period,
                    'horizon_months' => $w->horizon_months,
                    'category' => $w->category,
                    'current_risk' => $w->current_risk,
                    'projected_risk' => $w->projected_risk,
                    'projected_overall' => $w->projected_overall,
                    'drivers' => $w->drivers ?? [],
                    'status' => $w->status,
                    'acknowledged_by' => $w->acknowledgedBy?->name,
                    'acknowledged_at' => $w->acknowledged_at?->toIso8601String(),
                    'acknowledgement_note' => $w->acknowledgement_note,
                    'notified_at' => $w->notified_at?->toIso8601String(),
                    'student' => $student ? array_merge(
                        ['id' => $student->id, 'name' => $student->name, 'student_code' => $student->student_code],
                        StudentTaxonomyFilter::present($student),
                    ) : null,
                    'recipients' => $logs->map(fn (PpsNotificationLog $l) => [
                        'role' => $l->recipient_role,
                        'name' => $l->recipient?->name ?? ($l->meta['teacher_name'] ?? null),
                        'subject' => $l->meta['subject'] ?? null,
                    ])->values()->all(),
                ];
            })->values(),
        ]);
    }

    public function acknowledge(Request $request, EarlyWarning $warning): JsonResponse
    {
        /** @var User|null $viewer */
        $viewer = $request->user();
        if ($viewer?->hasAnyRole('teacher') && ! TeacherScope::canAccessStudent($viewer, $warning->student_id)) {
            abort(Response::HTTP_FORBIDDEN, 'You are not assigned to this student.');
        }

        $validated = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);

        $warning->forceFill([
            'status' => 'acknowledged',
            'acknowledged_by' => $request->user()?->id,
            'acknowledged_at' => now(),
            'acknowledgement_note' => $validated['note'] ?? null,
        ])->save();

        return response()->json(['warning' => $warning->fresh()]);
    }
}

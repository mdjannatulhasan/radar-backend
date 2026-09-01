<?php

namespace App\Http\Controllers\Api\V1\Pps;

use App\Http\Controllers\Controller;
use App\Models\Pps\PerformanceSnapshot;
use App\Models\Pps\PpsAlert;
use App\Support\StudentTaxonomyFilter;
use App\Support\TeacherScope;
use SmsCore\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class AlertController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        $alerts = PpsAlert::query()
            ->with(array_merge(
                ['student:id,name,student_code,roll_number,guardian_name,guardian_phone'],
                StudentTaxonomyFilter::eagerLoadVia('student'),
            ))
            ->when(
                $request->boolean('active', true),
                fn ($query) => $query->whereNull('resolved_at')
            )
            ->when($user?->hasAnyRole('teacher'), function ($query) use ($user): void {
                // A teacher's scope is a set of sections now, not (class, section)
                // strings. applyStudentScope fails closed: a teacher with no
                // assignments matches no students.
                $query->whereHas(
                    'student',
                    fn ($studentQuery) => TeacherScope::applyStudentScope($studentQuery, $user)
                );
            })
            ->when($request->filled('alert_level'), fn ($query) => $query->where('alert_level', $request->string('alert_level')->toString()))
            ->orderByRaw("CASE alert_level WHEN 'urgent' THEN 1 WHEN 'warning' THEN 2 ELSE 3 END")
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        $snapshotHistory = $this->snapshotHistoryByStudent($alerts->pluck('student_id'));

        return response()->json([
            'data' => $alerts->map(
                fn (PpsAlert $alert) => $this->serializeAlert($alert, $snapshotHistory)
            )->values(),
        ]);
    }

    private function snapshotHistoryByStudent(Collection $studentIds): Collection
    {
        $ids = $studentIds->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return PerformanceSnapshot::query()
            ->whereIn('student_id', $ids)
            ->orderBy('student_id')
            ->orderByDesc('snapshot_period')
            ->get([
                'student_id',
                'snapshot_period',
                'overall_score',
                'risk_score',
                'academic_score',
                'attendance_score',
                'trend_direction',
            ])
            ->groupBy('student_id');
    }

    private function serializeAlert(PpsAlert $alert, Collection $snapshotHistory): array
    {
        $payload = $alert->toArray();
        $history = $snapshotHistory->get($alert->student_id, collect());
        $currentSnapshot = $history->firstWhere('snapshot_period', $alert->snapshot_period);
        $previousSnapshot = $history->first(
            fn (PerformanceSnapshot $snapshot) => $snapshot->snapshot_period < $alert->snapshot_period
        );

        $payload['snapshot'] = $currentSnapshot
            ? [
                'overall_score' => round((float) $currentSnapshot->overall_score, 1),
                'risk_score' => round((float) $currentSnapshot->risk_score, 1),
                'academic_score' => round((float) $currentSnapshot->academic_score, 1),
                'attendance_score' => round((float) $currentSnapshot->attendance_score, 1),
                'trend_direction' => $currentSnapshot->trend_direction,
                'trend_delta' => $previousSnapshot
                    ? round((float) $currentSnapshot->overall_score - (float) $previousSnapshot->overall_score, 1)
                    : null,
            ]
            : null;

        return $payload;
    }
}

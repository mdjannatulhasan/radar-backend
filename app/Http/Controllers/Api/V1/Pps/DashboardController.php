<?php

namespace App\Http\Controllers\Api\V1\Pps;

use App\Http\Controllers\Controller;
use App\Models\Pps\Assessment;
use App\Models\Pps\PerformanceSnapshot;
use App\Models\Pps\PpsAlert;
use App\Services\Pps\TrendAnalyzerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(
        private readonly TrendAnalyzerService $trendAnalyzer,
    ) {
    }

    public function summary(Request $request): JsonResponse
    {
        $period = $request->string('period')->toString() ?: now()->format('Y-m');

        $summary = PerformanceSnapshot::query()
            ->forPeriod($period)
            ->selectRaw("
                COUNT(*) as total_students,
                AVG(overall_score) as school_avg,
                SUM(CASE WHEN alert_level = 'urgent' THEN 1 ELSE 0 END) as urgent_count,
                SUM(CASE WHEN alert_level = 'warning' THEN 1 ELSE 0 END) as warning_count,
                SUM(CASE WHEN alert_level = 'watch' THEN 1 ELSE 0 END) as watch_count,
                SUM(CASE WHEN alert_level = 'none' THEN 1 ELSE 0 END) as good_count,
                SUM(CASE WHEN trend_direction = 'rapid_down' THEN 1 ELSE 0 END) as rapid_decline_count,
                AVG(CASE WHEN alert_level != 'none' THEN risk_score END) as avg_risk_score
            ")
            ->first();

        $classOverview = PerformanceSnapshot::query()
            ->forPeriod($period)
            ->join('students', 'students.id', '=', 'pps_performance_snapshots.student_id')
            ->groupBy('students.class_name', 'students.section')
            ->selectRaw("
                students.class_name,
                students.section,
                COUNT(*) as total,
                AVG(overall_score) as avg_score,
                AVG(academic_score) as avg_academic,
                AVG(attendance_score) as avg_attendance,
                SUM(CASE WHEN alert_level = 'urgent' THEN 1 ELSE 0 END) as urgent,
                SUM(CASE WHEN alert_level = 'warning' THEN 1 ELSE 0 END) as warning,
                SUM(CASE WHEN alert_level = 'watch' THEN 1 ELSE 0 END) as watch
            ")
            ->orderBy('students.class_name')
            ->orderBy('students.section')
            ->get()
            ->map(fn ($row) => [
                'class_name' => $row->class_name,
                'section' => $row->section,
                'total' => (int) $row->total,
                'avg_score' => round((float) $row->avg_score, 1),
                'avg_academic' => round((float) $row->avg_academic, 1),
                'avg_attendance' => round((float) $row->avg_attendance, 1),
                'urgent' => (int) $row->urgent,
                'warning' => (int) $row->warning,
                'watch' => (int) $row->watch,
            ])
            ->values();

        $urgentStudents = PerformanceSnapshot::query()
            ->forPeriod($period)
            ->where('alert_level', 'urgent')
            ->with('student:id,name,class_name,section,roll_number,photo_path')
            ->orderByDesc('risk_score')
            ->limit(20)
            ->get([
                'student_id',
                'overall_score',
                'risk_score',
                'academic_score',
                'attendance_score',
                'trend_direction',
            ]);

        $activeAlerts = PpsAlert::query()
            ->whereNull('resolved_at')
            ->with('student:id,name,class_name,section,roll_number')
            ->orderByRaw("CASE alert_level WHEN 'urgent' THEN 1 WHEN 'warning' THEN 2 ELSE 3 END")
            ->orderByDesc('created_at')
            ->limit(15)
            ->get();

        $schoolTrend = PerformanceSnapshot::query()
            ->whereIn('snapshot_period', $this->trendAnalyzer->getLastPeriods($period, 6))
            ->groupBy('snapshot_period')
            ->selectRaw('snapshot_period, AVG(overall_score) as avg_score')
            ->orderBy('snapshot_period')
            ->get()
            ->map(fn ($row) => [
                'snapshot_period' => $row->snapshot_period,
                'avg_score' => round((float) $row->avg_score, 1),
            ])
            ->values();

        $notableItems = $classOverview
            ->filter(fn (array $row) => $row['urgent'] > 0 || $row['warning'] >= 2)
            ->sortByDesc(fn (array $row) => ($row['urgent'] * 3) + $row['warning'])
            ->take(3)
            ->map(fn (array $row) => sprintf(
                'Class %s-%s is showing elevated risk with %d urgent and %d warning cases.',
                $row['class_name'],
                $row['section'],
                $row['urgent'],
                $row['warning']
            ))
            ->values();

        $previousPeriod = \Carbon\Carbon::createFromFormat('Y-m', $period)->subMonth()->format('Y-m');
        $previousTeacherScores = Assessment::query()
            ->whereYear('exam_date', substr($previousPeriod, 0, 4))
            ->whereMonth('exam_date', substr($previousPeriod, 5, 2))
            ->whereNotNull('teacher_id')
            ->groupBy('teacher_id', 'subject')
            ->selectRaw('teacher_id, subject, AVG(percentage) as avg_score')
            ->get()
            ->keyBy(fn ($row) => "{$row->teacher_id}_{$row->subject}");

        $teacherHighlights = Assessment::query()
            ->whereYear('exam_date', substr($period, 0, 4))
            ->whereMonth('exam_date', substr($period, 5, 2))
            ->whereNotNull('teacher_id')
            ->with('teacher:id,name')
            ->groupBy('teacher_id', 'subject')
            ->selectRaw('teacher_id, subject, AVG(percentage) as avg_score')
            ->get()
            ->map(function ($row) use ($previousTeacherScores): array {
                $key = "{$row->teacher_id}_{$row->subject}";
                $previous = $previousTeacherScores->get($key);
                $change = $previous ? round((float) $row->avg_score - (float) $previous->avg_score, 1) : 0.0;

                return [
                    'teacher_name' => $row->teacher?->name ?? 'Unknown teacher',
                    'subject' => $row->subject,
                    'avg_score' => round((float) $row->avg_score, 1),
                    'change' => $change,
                ];
            })
            ->sortByDesc(fn (array $row) => $row['change'])
            ->take(5)
            ->values();

        return response()->json([
            'period' => $period,
            'summary' => [
                'total_students' => (int) ($summary?->total_students ?? 0),
                'school_avg' => round((float) ($summary?->school_avg ?? 0), 1),
                'urgent_count' => (int) ($summary?->urgent_count ?? 0),
                'warning_count' => (int) ($summary?->warning_count ?? 0),
                'watch_count' => (int) ($summary?->watch_count ?? 0),
                'good_count' => (int) ($summary?->good_count ?? 0),
                'rapid_decline_count' => (int) ($summary?->rapid_decline_count ?? 0),
                'avg_risk_score' => round((float) ($summary?->avg_risk_score ?? 0), 1),
            ],
            'class_overview' => $classOverview,
            'urgent_students' => $urgentStudents,
            'active_alerts' => $activeAlerts,
            'school_trend' => $schoolTrend,
            'notable_items' => $notableItems,
            'teacher_highlights' => $teacherHighlights,
        ]);
    }

    public function resolve(PpsAlert $alert, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'resolution_note' => ['nullable', 'string', 'max:1000'],
            'resolution_action' => ['nullable', 'in:counseled,parent_meeting,extra_class,referred,monitored'],
        ]);

        $alert->update([
            'resolved_at' => now(),
            'resolved_by' => $request->user()?->id,
            'resolution_note' => $validated['resolution_note'] ?? null,
            'resolution_action' => $validated['resolution_action'] ?? null,
        ]);

        return response()->json([
            'message' => 'Alert resolved.',
            'alert_id' => $alert->id,
        ]);
    }
}

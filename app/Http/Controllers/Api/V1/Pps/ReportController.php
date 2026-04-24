<?php

namespace App\Http\Controllers\Api\V1\Pps;

use App\Http\Controllers\Controller;
use App\Models\Pps\PpsNotificationLog;
use App\Models\Pps\PerformanceSnapshot;
use App\Models\Student;
use App\Services\Pps\ReportCardService;
use App\Services\Pps\ReportExportService;
use App\Services\Pps\SimplePdfService;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(
        private readonly ReportExportService $reports,
        private readonly SimplePdfService $pdf,
        private readonly ReportCardService $reportCard,
    ) {
    }

    /**
     * GET /v1/pps/reports/generate/report_card?student_id=&exam_id=
     * Returns a PDF report card for one student.
     */
    public function studentReportCard(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $data = $request->validate([
            'student_id' => ['required', 'exists:students,id'],
            'exam_id'    => ['required', 'exists:pps_exam_definitions,id'],
        ]);

        $student = Student::query()->findOrFail($data['student_id']);
        $pdf = $this->reportCard->generate($data['student_id'], $data['exam_id']);

        $filename = 'report-card-' . str_replace(' ', '-', strtolower($student->name)) . '-' . $data['exam_id'] . '.pdf';

        $inline = $request->boolean('inline', false);
        $disposition = $inline ? "inline; filename=\"{$filename}\"" : "attachment; filename=\"{$filename}\"";

        return response($pdf, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => $disposition,
            'Cache-Control'       => 'private, no-store',
        ]);
    }

    /**
     * GET /v1/pps/reports/generate/tabulation?exam_id=
     * Returns a tabulation sheet PDF for all students in the exam's class+section.
     */
    public function tabulationSheet(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $data = $request->validate([
            'exam_id' => ['required', 'exists:pps_exam_definitions,id'],
        ]);

        $inline = $request->boolean('inline', false);
        $filename = "tabulation-{$data['exam_id']}.pdf";
        $disposition = $inline ? "inline; filename=\"{$filename}\"" : "attachment; filename=\"{$filename}\"";

        // Generate outside the closure so exceptions surface before streaming starts
        $pdf = $this->reportCard->generateTabulation($data['exam_id']);

        return response()->streamDownload(
            function () use ($pdf): void { echo $pdf; },
            $filename,
            [
                'Content-Type'        => 'application/pdf',
                'Content-Disposition' => $disposition,
                'Cache-Control'       => 'private, no-store',
            ],
        );
    }

    public function generate(Request $request, string $type)
    {
        $period = $request->string('period')->toString() ?: now()->format('Y-m');
        $format = $request->string('format')->toString() ?: 'json';
        $lang = $request->string('lang')->toString() ?: 'en';

        return match ($type) {
            'student_card' => $this->studentCard($request, $period, $format, $lang),
            'class_summary' => $this->classSummary($request, $period, $format),
            'at_risk_list' => $this->atRiskList($period, $format),
            'teacher_effectiveness' => $this->teacherEffectiveness($request, $period, $format),
            'board_summary' => $this->boardSummary($period, $format),
            'full_data_export' => $this->fullDataExport($period, $format),
            'notification_digest' => $this->notificationDigest($period, $format),
            default => response()->json(['message' => 'Unsupported report type.'], 422),
        };
    }

    private function studentCard(Request $request, string $period, string $format, string $lang)
    {
        $student = Student::query()->findOrFail($request->integer('student_id'));
        $snapshot = PerformanceSnapshot::query()->where('student_id', $student->id)->forPeriod($period)->firstOrFail();
        $lines = $this->reports->buildStudentCard($student, $snapshot, $lang);

        return $this->respond($format, "pps-student-{$student->id}-{$period}", $lines, ['line' => $lines]);
    }

    private function classSummary(Request $request, string $period, string $format)
    {
        $className = $request->string('class_name')->toString();
        $section = $request->string('section')->toString();
        $controller = app(StudentPerformanceController::class);
        $payload = $controller->classAnalytics(new Request(['period' => $period]), $className, $section)->getData(true);
        $lines = $this->reports->buildClassSummary($className, $section, (object) $payload['summary'], $payload['subject_performance']);

        return $this->respond($format, "pps-class-{$className}-{$section}-{$period}", $lines, $payload);
    }

    private function atRiskList(string $period, string $format)
    {
        $snapshots = PerformanceSnapshot::query()
            ->forPeriod($period)
            ->atRisk()
            ->with('student:id,name,class_name,section,roll_number')
            ->orderByDesc('risk_score')
            ->get();
        $lines = $this->reports->buildAtRiskList($snapshots);

        return $this->respond($format, "pps-at-risk-{$period}", $lines, ['data' => $snapshots]);
    }

    private function teacherEffectiveness(Request $request, string $period, string $format)
    {
        $payload = app(StudentPerformanceController::class)
            ->teacherEffectiveness(new Request(['period' => $period]))
            ->getData(true);
        $lines = $this->reports->buildTeacherEffectiveness($payload['data']);

        return $this->respond($format, "pps-teachers-{$period}", $lines, $payload);
    }

    private function boardSummary(string $period, string $format)
    {
        $dashboard = app(DashboardController::class)->summary(new Request(['period' => $period]))->getData(true);
        $teachers = app(StudentPerformanceController::class)
            ->teacherEffectiveness(new Request(['period' => $period]))
            ->getData(true);

        $teacherHighlights = collect($teachers['data'])
            ->sortByDesc(fn (array $row) => ($row['change'] ?? 0) + ($row['avg_score'] / 20))
            ->take(5)
            ->values()
            ->all();

        $lines = $this->reports->buildBoardSummary(
            $period,
            $dashboard['summary'],
            $dashboard['class_overview'],
            $teacherHighlights
        );

        return $this->respond($format, "pps-board-summary-{$period}", $lines, [
            'period' => $period,
            'summary' => $dashboard['summary'],
            'class_overview' => $dashboard['class_overview'],
            'teacher_highlights' => $teacherHighlights,
            'notable_items' => $dashboard['notable_items'],
        ]);
    }

    private function fullDataExport(string $period, string $format)
    {
        $snapshots = PerformanceSnapshot::query()
            ->forPeriod($period)
            ->with('student')
            ->orderByDesc('risk_score')
            ->get();

        $rows = $this->reports->buildFullDataExport($snapshots);

        if ($format === 'csv') {
            return response($this->reports->rowsToCsv($rows), 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => "attachment; filename=\"pps-full-data-{$period}.csv\"",
            ]);
        }

        if ($format === 'pdf') {
            $lines = collect($rows)->take(50)->map(
                fn (array $row) => "{$row['student_name']} | {$row['class_name']}-{$row['section']} | {$row['overall_score']} | {$row['alert_level']}"
            )->prepend("Full data export for {$period}")
                ->all();

            return response($this->pdf->render("PPS FULL DATA {$period}", $lines), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => "attachment; filename=\"pps-full-data-{$period}.pdf\"",
            ]);
        }

        return response()->json([
            'period' => $period,
            'total' => count($rows),
            'data' => $rows,
        ]);
    }

    private function notificationDigest(string $period, string $format)
    {
        $logs = PpsNotificationLog::query()
            ->where('snapshot_period', $period)
            ->with('student:id,name,class_name,section')
            ->orderByDesc('generated_at')
            ->get();

        $lines = $this->reports->buildNotificationDigest($logs);

        return $this->respond($format, "pps-notification-digest-{$period}", $lines, [
            'period' => $period,
            'data' => $logs,
        ]);
    }

    private function respond(string $format, string $name, array $lines, array $payload)
    {
        if ($format === 'pdf') {
            return response($this->pdf->render(str_replace('-', ' ', strtoupper($name)), $lines), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => "attachment; filename=\"{$name}.pdf\"",
            ]);
        }

        if ($format === 'csv') {
            $csv = $this->reports->toCsv(['line'], array_map(fn ($line) => [$line], $lines));

            return response($csv, 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => "attachment; filename=\"{$name}.csv\"",
            ]);
        }

        return response()->json($payload);
    }
}

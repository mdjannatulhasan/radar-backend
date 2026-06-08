<?php

namespace App\Http\Controllers\Api\V1\Pps;

use App\Http\Controllers\Controller;
use App\Models\Pps\ComputedScore;
use App\Models\Pps\Exam;
use App\Models\Pps\Extracurricular;
use App\Models\Pps\PerformanceSnapshot;
use App\Models\Student;
use App\Services\Pps\RecommendationService;
use App\Services\Pps\StudentInsightService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ParentViewController extends Controller
{
    public function __construct(
        private readonly RecommendationService $recommendations,
        private readonly StudentInsightService $insights,
    ) {
    }

    public function myChildren(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! method_exists($user, 'hasAnyRole') || ! $user->hasAnyRole('guardian')) {
            abort(Response::HTTP_FORBIDDEN, 'Guardian access is required.');
        }

        $students = Student::query()
            ->where('guardian_email', $user->email)
            ->orderBy('class_name')
            ->orderBy('section')
            ->orderBy('roll_number')
            ->get(['id', 'name', 'class_name', 'section', 'roll_number', 'photo_path']);

        return response()->json(['data' => $students]);
    }

    public function report(Request $request, Student $student): JsonResponse
    {
        $this->guardStudentAccess($request, $student);

        $period = $request->string('period')->toString() ?: now()->format('Y-m');
        $snapshot = PerformanceSnapshot::query()
            ->where('student_id', $student->id)
            ->forPeriod($period)
            ->first();

        if (! $snapshot) {
            return response()->json([
                'message' => 'The report for this period is not available yet.',
            ], Response::HTTP_NOT_FOUND);
        }

        $attendance = $snapshot->snapshot_data['attendance'] ?? ['total' => 0, 'absent' => 0];
        $present = max(0, (int) ($attendance['total'] ?? 0) - (int) ($attendance['absent'] ?? 0));

        // PPS academic results — latest by default; guardian can pass ?exam_id= to select a term
        $ppsResults = $this->buildPpsResults($student, $request->integer('exam_id') ?: null);

        return response()->json([
            'student' => $student->only(['id', 'name', 'class_name', 'section', 'photo_path']),
            'period' => $period,
            'support_status' => $this->insights->buildWellbeing($student, $request->user()),
            'context' => $this->insights->buildContext($student, $request->user()),
            'tuition_analysis' => $this->insights->buildTuitionAnalysis($student, $snapshot),
            'scores' => [
                'Academic' => $this->toStars((float) $snapshot->academic_score),
                'Attendance' => $this->toStars((float) $snapshot->attendance_score),
                'Behavior' => $this->toStars((float) $snapshot->behavior_score),
                'Participation' => $this->toStars((float) $snapshot->participation_score),
                'Extracurricular' => $this->toStars((float) $snapshot->extracurricular_score),
            ],
            'attendance_days' => [
                'present' => $present,
                'total' => (int) ($attendance['total'] ?? 0),
            ],
            'subject_notes' => $this->buildSubjectNotes($snapshot),
            'achievements' => $this->getAchievements($student->id, $period),
            'parent_advice' => $this->generateAdvice($snapshot),
            'overall_message' => $this->generateOverallMessage($snapshot),
            'report_link' => route('pps.parents.report.print', ['student' => $student->id, 'period' => $period]),
            'pps_results' => $ppsResults,
        ]);
    }

    /**
     * Build PPS academic results block for the guardian portal.
     * Returns the selected exam's result + a list of available exams for the term selector.
     */
    private function buildPpsResults(Student $student, ?int $requestedExamId): array
    {
        // Find all exams that have computed scores for this student
        $examIds = ComputedScore::query()
            ->where('student_id', $student->id)
            ->distinct()
            ->pluck('exam_id');

        if ($examIds->isEmpty()) {
            return ['available_exams' => [], 'selected' => null];
        }

        $exams = Exam::query()
            ->with('examType:id,code')
            ->whereIn('id', $examIds)
            ->orderByDesc('exam_date')
            ->get(['id', 'title', 'exam_type_id', 'exam_date']);

        $selectedExamId = $requestedExamId && $exams->contains('id', $requestedExamId)
            ? $requestedExamId
            : $exams->first()?->id;

        $selectedSummary = $selectedExamId
            ? $this->aggregateScores($student->id, $selectedExamId)
            : null;

        return [
            'available_exams' => $exams->map(fn ($e) => [
                'exam_id'         => $e->id,
                'title'           => $e->title,
                'assessment_type' => $e->examType?->code,
                'computed_at'     => $e->exam_date,
            ])->all(),
            'selected' => $selectedSummary,
        ];
    }

    private function aggregateScores(int $studentId, int $examId): array
    {
        $exam = Exam::with('examType:id,code')->find($examId);
        $scores = ComputedScore::query()
            ->where('exam_id', $examId)
            ->where('student_id', $studentId)
            ->get(['total_obtained', 'total_possible', 'percentage', 'letter_grade', 'grade_point', 'computed_at']);

        if ($scores->isEmpty()) {
            return [];
        }

        $totalObtained = $scores->sum('total_obtained');
        $totalPossible = $scores->sum('total_possible');
        $pct = $totalPossible > 0 ? round($totalObtained / $totalPossible * 100, 2) : 0;

        return [
            'exam_id'              => $examId,
            'exam_title'           => $exam?->title,
            'gpa'                  => $scores->avg('grade_point'),
            'letter_grade'         => $scores->sortBy('grade_point')->first()?->letter_grade ?? '—',
            'total_marks_obtained' => $totalObtained,
            'total_marks_full'     => $totalPossible,
            'percentage'           => $pct,
            'class_position'       => null,
            'total_students'       => null,
            'is_promoted'          => null,
            'computed_at'          => $scores->max('computed_at'),
        ];
    }

    public function printableReport(Request $request, Student $student)
    {
        $this->guardStudentAccess($request, $student);

        $period = $request->string('period')->toString() ?: now()->format('Y-m');
        $snapshot = PerformanceSnapshot::query()
            ->where('student_id', $student->id)
            ->forPeriod($period)
            ->firstOrFail();

        return response()->view('pps.reports.student_card', [
            'student' => $student,
            'snapshot' => $snapshot,
            'period' => $period,
            'subjects' => $snapshot->snapshot_data['subjects'] ?? [],
            'recommendations' => $this->recommendations->forStudent($student->id, $snapshot),
            'overallMessage' => $this->generateOverallMessage($snapshot),
            'generatedAt' => Carbon::now()->format('d M Y, h:i A'),
        ]);
    }

    private function guardStudentAccess(Request $request, Student $student): void
    {
        $this->authorize('viewParentReport', $student);
    }

    private function toStars(float $score): int
    {
        return match (true) {
            $score >= 85 => 5,
            $score >= 70 => 4,
            $score >= 55 => 3,
            $score >= 40 => 2,
            default => 1,
        };
    }

    private function buildSubjectNotes(PerformanceSnapshot $snapshot): array
    {
        return collect($snapshot->snapshot_data['subjects'] ?? [])
            ->map(function (array $data, string $subject): array {
                $average = (float) ($data['avg'] ?? 0);

                return [
                    'subject' => $subject,
                    'status' => $average >= 70 ? 'Doing well' : ($average >= 50 ? 'Needs regular practice' : 'Needs close support'),
                    'stars' => $this->toStars($average),
                ];
            })
            ->values()
            ->all();
    }

    private function generateAdvice(PerformanceSnapshot $snapshot): array
    {
        $advice = [];
        foreach ($snapshot->snapshot_data['subjects'] ?? [] as $subject => $data) {
            if ((float) ($data['avg'] ?? 0) < 50) {
                $advice[] = "Spend a little more home study time on {$subject}.";
            }
        }

        if ($snapshot->attendance_score < 80) {
            $advice[] = 'Try to improve daily attendance consistency this month.';
        }

        if ($snapshot->trend_direction === 'up') {
            $advice[] = 'The recent direction is positive. Keep encouraging the same habits.';
        }

        return $advice;
    }

    private function generateOverallMessage(PerformanceSnapshot $snapshot): string
    {
        return match (true) {
            $snapshot->overall_score >= 80 => 'Excellent progress. The student is doing very well.',
            $snapshot->overall_score >= 65 => 'Overall progress is healthy, with room to become more consistent.',
            $snapshot->overall_score >= 50 => 'Some areas need extra support. A short talk with teachers would help.',
            default => 'The student needs closer support right now. Please stay in touch with the school.',
        };
    }

    private function getAchievements(int $studentId, string $period): array
    {
        $start = Carbon::createFromFormat('Y-m', $period)->startOfMonth();
        $end = Carbon::createFromFormat('Y-m', $period)->endOfMonth();

        return Extracurricular::query()
            ->where('student_id', $studentId)
            ->whereBetween('event_date', [$start, $end])
            ->whereNotNull('achievement')
            ->orderByDesc('event_date')
            ->get()
            ->map(fn (Extracurricular $activity) => [
                'title' => $activity->activity_name,
                'achievement' => $activity->achievement,
                'date' => $activity->event_date?->format('d M'),
            ])
            ->all();
    }
}

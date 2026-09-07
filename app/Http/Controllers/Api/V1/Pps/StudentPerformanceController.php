<?php

namespace App\Http\Controllers\Api\V1\Pps;

use App\Http\Controllers\Controller;
use App\Models\Pps\ComputedScore;
use Illuminate\Support\Facades\DB;
use App\Models\Pps\Mark;
use App\Models\Pps\BehaviorCard;
use App\Models\Pps\ClassroomRating;
use App\Models\Pps\CounselingSession;
use App\Models\Pps\Extracurricular;
use App\Models\Pps\PerformanceSnapshot;
use App\Models\Pps\PpsAlert;
use SmsCore\Models\Section;
use SmsCore\Models\Student;
use SmsCore\Models\Subject;
use SmsCore\Models\Teacher;
use SmsCore\Models\User;
use App\Services\Pps\ForecastService;
use App\Services\Pps\ReportExportService;
use App\Services\Pps\RecommendationService;
use App\Services\Pps\SimplePdfService;
use App\Services\Pps\StudentInsightService;
use App\Services\Pps\TrendAnalyzerService;
use App\Services\Pps\WhatIfAnalyzerService;
use App\Support\StudentTaxonomyFilter;
use App\Support\TeacherScope;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

class StudentPerformanceController extends Controller
{
    public function __construct(
        private readonly RecommendationService $recommendations,
        private readonly TrendAnalyzerService $trendAnalyzer,
        private readonly WhatIfAnalyzerService $whatIfAnalyzer,
        private readonly ForecastService $forecastService,
        private readonly ReportExportService $reportExportService,
        private readonly SimplePdfService $pdfService,
        private readonly StudentInsightService $insights,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        /** @var User|null $viewer */
        $viewer = $request->user();
        $period = $this->resolvePeriod($request);
        $perPage = min(500, max(1, (int) $request->integer('limit', 24)));

        // level / version / group / class_level_id / section_id: the students
        // table no longer carries class_name or section to filter on.
        $filters = StudentTaxonomyFilter::validate($request);

        // The list is driven by students, not by snapshots. It used to be the
        // other way round, which silently hid every student who had not been
        // scored yet — including everyone in a newly imported class.
        $students = Student::query()->with(StudentTaxonomyFilter::eagerLoad());

        $students = StudentTaxonomyFilter::apply($students, $filters);

        if ($viewer?->hasAnyRole('teacher')) {
            TeacherScope::applyStudentScope($students, $viewer);
        }

        if ($request->filled('alert_level')) {
            $students->whereIn('id', PerformanceSnapshot::query()
                ->forPeriod($period)
                ->where('alert_level', $request->string('alert_level')->toString())
                ->select('student_id'));
        }

        if ($request->filled('search')) {
            $term = $request->string('search')->toString();
            $students->where(function (Builder $query) use ($term): void {
                $query->where('name', 'like', "%{$term}%")
                    ->orWhere('student_code', 'like', "%{$term}%")
                    ->orWhere('roll_number', 'like', "%{$term}%");
            });
        }

        // Riskiest first, as before — but a student with no snapshot for the
        // period now sorts last instead of dropping out of the list entirely.
        $riskScore = PerformanceSnapshot::query()
            ->forPeriod($period)
            ->whereColumn('student_id', 'students.id')
            ->select('risk_score')
            ->limit(1)
            ->getQuery();

        $page = $students
            ->orderByRaw('COALESCE(('.$riskScore->toSql().'), -1) DESC', $riskScore->getBindings())
            ->orderBy('students.name')
            ->paginate($perPage);

        $studentIds = $page->getCollection()->pluck('id');

        $snapshots = PerformanceSnapshot::query()
            ->forPeriod($period)
            ->whereIn('student_id', $studentIds)
            ->get()
            ->keyBy('student_id');

        $previousScores = $this->latestPreviousOverallScores($studentIds, $period);

        $page->setCollection(
            $page->getCollection()->map(function (Student $student) use ($snapshots, $previousScores, $period): array {
                $snapshot = $snapshots->get($student->id);

                $row = $snapshot === null
                    ? ['student_id' => $student->id, 'snapshot_period' => $period, 'trend_delta' => null]
                    : $this->serializeSnapshotWithTrend($snapshot, $previousScores);

                return array_merge($row, StudentTaxonomyFilter::present($student), [
                    'student_code' => $student->student_code,
                    'student' => $student,
                ]);
            })
        );

        return response()->json($page);
    }

    public function show(Request $request, Student $student): JsonResponse
    {
        /** @var User|null $viewer */
        $viewer = $request->user();
        if ($viewer?->hasAnyRole('teacher') && ! TeacherScope::canAccessStudent($viewer, $student)) {
            abort(Response::HTTP_FORBIDDEN, 'You are not assigned to this student.');
        }

        $period = $this->resolvePeriod($request);
        $snapshot = PerformanceSnapshot::query()
            ->where('student_id', $student->id)
            ->forPeriod($period)
            ->first();

        $history = PerformanceSnapshot::query()
            ->where('student_id', $student->id)
            ->orderByDesc('snapshot_period')
            ->limit(9)
            ->get([
                'snapshot_period',
                'overall_score',
                'academic_score',
                'attendance_score',
                'behavior_score',
                'participation_score',
                'alert_level',
            ])
            ->reverse()
            ->values();

        $teacherComments = ClassroomRating::query()
            ->where('student_id', $student->id)
            ->whereNotNull('free_comment')
            ->with('teacher:id,full_name')
            ->orderByDesc('rating_period')
            ->limit(5)
            ->get(['rating_period', 'subject', 'free_comment', 'behavioral_flag', 'teacher_id']);

        $activeAlerts = PpsAlert::query()
            ->where('student_id', $student->id)
            ->active()
            ->orderByDesc('created_at')
            ->get();

        $defaultWhatIf = $snapshot
            ? $this->whatIfAnalyzer->analyze($student->id, $period, $this->whatIfAnalyzer->defaultScenarios($snapshot))
            : [];

        return response()->json([
            'student' => array_merge($student->only([
                'id',
                'student_code',
                'name',
                'roll_number',
                'photo_path',
                'guardian_name',
                'guardian_phone',
                'guardian_email',
            ]), StudentTaxonomyFilter::present($student)),
            'period' => $period,
            'current_snapshot' => $snapshot
                ? $this->serializeSnapshotWithTrend(
                    $snapshot,
                    $this->latestPreviousOverallScores(collect([$student->id]), $period),
                )
                : null,
            'academic_profile' => $this->insights->buildAcademicProfile($student, $period, $snapshot),
            'assessment_breakdown' => $this->buildAssessmentBreakdown($student->id),
            'context' => $this->insights->buildContext($student, $request->user()),
            'wellbeing' => $this->insights->buildWellbeing($student, $request->user()),
            'tuition_analysis' => $this->insights->buildTuitionAnalysis($student, $snapshot),
            'history' => $history,
            'recent_events' => $this->getRecentEvents($student->id, $period),
            'teacher_comments' => $teacherComments,
            'active_alerts' => $activeAlerts,
            'recommendations' => $this->recommendations->forStudent($student->id, $snapshot),
            'advisory_brief' => $snapshot?->snapshot_data['ai_recommendation'] ?? $this->recommendations->narrativeForStudent($snapshot),
            'what_if_preview' => $defaultWhatIf,
            'forecast' => $this->forecastService->forecastForStudent($student->id, $period),
        ]);
    }

    private function buildAssessmentBreakdown(int $studentId): array
    {
        $scores = ComputedScore::query()
            ->join('pps_exams', 'pps_exams.id', '=', 'pps_computed_scores.exam_id')
            ->join('pps_exam_types', 'pps_exam_types.id', '=', 'pps_exams.exam_type_id')
            ->join('subjects', 'subjects.id', '=', 'pps_computed_scores.subject_id')
            ->where('pps_computed_scores.student_id', $studentId)
            ->orderBy('pps_exams.academic_year')
            ->orderBy('pps_exams.term')
            ->get([
                'subjects.full_name as subject',
                'pps_exam_types.code as type_code',
                'pps_exam_types.name as type_name',
                'pps_exams.id as exam_id',
                'pps_exams.title as exam_title',
                'pps_exams.exam_date',
                'pps_computed_scores.total_obtained',
                'pps_computed_scores.total_possible',
                'pps_computed_scores.percentage',
                'pps_computed_scores.letter_grade',
            ]);

        $marks = Mark::query()
            ->join('pps_exam_components', 'pps_exam_components.id', '=', 'pps_marks.component_id')
            ->join('pps_exams', 'pps_exams.id', '=', 'pps_exam_components.exam_id')
            ->join('pps_exam_types', 'pps_exam_types.id', '=', 'pps_exams.exam_type_id')
            ->join('subjects', 'subjects.id', '=', 'pps_marks.subject_id')
            ->where('pps_marks.student_id', $studentId)
            ->where('pps_exam_types.is_terminal', false)
            ->get([
                'subjects.full_name as subject',
                'pps_exam_types.code as type_code',
                'pps_exams.exam_date',
                'pps_marks.marks_obtained',
                'pps_exam_components.max_raw_marks as total',
            ]);

        $bySubject = [];
        foreach ($scores as $score) {
            $bySubject[$score->subject][$score->type_code]['records'][] = [
                'obtained'   => round($score->total_obtained, 2),
                'total'      => $score->total_possible,
                'percentage' => round($score->percentage, 1),
                'date'       => $score->exam_date,
                'exam_title' => $score->exam_title,
            ];
        }
        foreach ($marks as $mark) {
            $bySubject[$mark->subject][$mark->type_code]['individual'][] = [
                'obtained'   => round($mark->marks_obtained, 2),
                'total'      => $mark->total,
                'percentage' => round(($mark->marks_obtained / $mark->total) * 100, 1),
                'date'       => $mark->exam_date,
            ];
        }

        $result = [];
        foreach ($bySubject as $subject => $types) {
            $examTypes = [];
            $allPct    = [];
            foreach ($types as $type => $data) {
                $records = $data['records'] ?? [];
                if (empty($records)) continue;
                $pcts      = array_column($records, 'percentage');
                $obtaineds = array_column($records, 'obtained');
                $avgPct    = round(array_sum($pcts) / count($pcts), 1);
                $avgObt    = round(array_sum($obtaineds) / count($obtaineds), 1);
                $examTypes[$type] = [
                    'avg_pct'      => $avgPct,
                    'avg_obtained' => $avgObt,
                    'total_marks'  => $records[0]['total'],
                    'highest_pct'  => round(max($pcts), 1),
                    'lowest_pct'   => round(min($pcts), 1),
                    'count'        => count($records),
                    'records'      => $data['individual'] ?? $records,
                ];
                $allPct = array_merge($allPct, $pcts);
            }
            $result[] = [
                'subject'     => $subject,
                'overall_avg' => count($allPct) ? round(array_sum($allPct) / count($allPct), 1) : 0,
                'exam_types'  => $examTypes,
            ];
        }

        usort($result, fn ($a, $b) => strcmp($a['subject'], $b['subject']));
        return $result;
    }

    public function quickSearch(Request $request): JsonResponse
    {
        $term = trim($request->string('q')->toString());
        if ($term === '') {
            return response()->json(['data' => []]);
        }

        $students = Student::query()
            ->where(function (Builder $query) use ($term): void {
                $query->where('name', 'like', "%{$term}%")
                    ->orWhere('student_code', 'like', "%{$term}%")
                    ->orWhere('roll_number', 'like', "%{$term}%");
            })
            ->with(StudentTaxonomyFilter::eagerLoad())
            ->limit(10)
            ->get(['id', 'name', 'student_code', 'roll_number', 'photo_path']);

        return response()->json(['data' => $students]);
    }

    public function context(Request $request, Student $student): JsonResponse
    {
        $this->authorize('viewContext', $student);
        $viewer = $request->user();

        return response()->json([
            'student_id' => $student->id,
            'context' => $this->insights->buildContext($student, $viewer),
            'wellbeing' => $this->insights->buildWellbeing($student, $viewer),
        ]);
    }

    public function updateContext(Request $request, Student $student): JsonResponse
    {
        $viewer = $request->user();
        $this->authorize('updateContext', $student);
        $fullAccess = (bool) $viewer?->hasAnyRole(['principal', 'admin', 'counselor']);

        $validated = $request->validate([
            'admission_date' => ['nullable', 'date'],
            'current_gpa' => ['nullable', 'numeric', 'min:0', 'max:5'],
            'current_grade' => ['nullable', 'string', 'max:10'],
            'class_rank' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'private_tuition_subjects' => ['nullable', 'array'],
            'private_tuition_subjects.*.subject' => ['required_with:private_tuition_subjects', 'string', 'max:100'],
            'private_tuition_subjects.*.hours_per_week' => ['nullable', 'numeric', 'min:0', 'max:50'],
            'private_tuition_subjects.*.tutor_name' => ['nullable', 'string', 'max:120'],
            'private_tuition_notes' => ['nullable', 'string', 'max:1000'],
            'family_status' => ['nullable', 'string', 'max:120'],
            'economic_status' => ['nullable', 'string', 'max:120'],
            'scholarship_status' => ['nullable', 'string', 'max:120'],
            'health_notes' => ['nullable', 'string', 'max:1500'],
            'allergies' => ['nullable', 'string', 'max:255'],
            'medications' => ['nullable', 'string', 'max:255'],
            'residence_change_note' => ['nullable', 'string', 'max:255'],
            'special_needs' => ['nullable', 'array'],
            'confidential_context' => ['nullable', 'string', 'max:1500'],
            'guardian_profession' => ['nullable', 'string', 'max:120'],
            'guardian_profession_category' => ['nullable', 'in:business,doctor,lawyer,military,government,private_sector,education,agriculture,labor,other'],
            'guardian_time_availability' => ['nullable', 'in:high,medium,low'],
            'willingness_score' => ['nullable', 'integer', 'min:1', 'max:5'],
            'ability_score' => ['nullable', 'integer', 'min:1', 'max:5'],
            'economically_vulnerable' => ['nullable', 'boolean'],
        ]);

        if (! $fullAccess) {
            $validated = collect($validated)->only([
                'private_tuition_subjects',
                'private_tuition_notes',
                'family_status',
                'health_notes',
                'allergies',
                'medications',
                'residence_change_note',
                'willingness_score',
            ])->all();
        }

        // Auto-compute quadrant when willingness/ability scores are present
        $willingness = $validated['willingness_score'] ?? $student->willingness_score;
        $ability = $validated['ability_score'] ?? $student->ability_score;
        if ($willingness !== null && $ability !== null) {
            $validated['student_quadrant'] = match (true) {
                $willingness >= 3 && $ability >= 3 => 'willing_able',
                $willingness < 3 && $ability >= 3  => 'unwilling_able',
                $willingness >= 3 && $ability < 3  => 'willing_unable',
                default                             => 'unwilling_unable',
            };
        }

        // Auto-flag economically vulnerable when scholarship applied/approved
        $scholarshipStatus = $validated['scholarship_status'] ?? null;
        if (in_array($scholarshipStatus, ['applied', 'approved'], true)) {
            $validated['economically_vulnerable'] = true;
        }

        $student->update($validated);

        return response()->json([
            'message' => 'Student context updated.',
            'context' => $this->insights->buildContext($student->fresh(), $viewer),
        ]);
    }

    public function enrollmentHistory(Request $request, Student $student): JsonResponse
    {
        /** @var \SmsCore\Models\User|null $viewer */
        $viewer = $request->user();
        if ($viewer?->hasAnyRole('teacher') && ! TeacherScope::canAccessStudent($viewer, $student)) {
            abort(Response::HTTP_FORBIDDEN, 'You are not assigned to this student.');
        }

        $enrollments = $student->enrollments()
            ->with([
                'academicYear:id,name,start_date,end_date,is_current',
                'section.classLevel:id,name',
                'section.sectionName:id,name',
            ])
            ->orderBy('academic_year_id')
            ->get();

        $history = $enrollments->map(function ($enrollment) use ($student): array {
            $year = $enrollment->academicYear;

            $snapshots = PerformanceSnapshot::query()
                ->where('student_id', $student->id)
                ->when($year, function ($query) use ($year): void {
                    $query->where(function ($q) use ($year): void {
                        $q->whereYear('snapshot_period', $year->start_date->year)
                          ->orWhereYear('snapshot_period', $year->end_date->year);
                    });
                })
                ->orderBy('snapshot_period')
                ->get(['snapshot_period', 'overall_score', 'academic_score', 'attendance_score', 'behavior_score', 'alert_level']);

            $avgOverall = $snapshots->avg('overall_score');
            $avgAcademic = $snapshots->avg('academic_score');
            $avgAttendance = $snapshots->avg('attendance_score');

            return [
                'enrollment_id'  => $enrollment->id,
                'academic_year'  => $year ? [
                    'id'        => $year->id,
                    'year_name' => $year->name,
                    'is_active' => $year->is_current,
                ] : null,
                // An enrollment names a section, which carries the class with it.
                'class_name'     => $enrollment->section?->classLevel?->name,
                'section'        => $enrollment->section?->sectionName?->name,
                'roll_number'    => $enrollment->roll_number,
                'status'         => $enrollment->status,
                'is_current'     => (bool) $year?->is_current,
                'started_at'     => $year?->start_date?->toDateString(),
                'ended_at'       => $year?->end_date?->toDateString(),
                'snapshot_count' => $snapshots->count(),
                'avg_overall'    => $avgOverall !== null ? round($avgOverall, 1) : null,
                'avg_academic'   => $avgAcademic !== null ? round($avgAcademic, 1) : null,
                'avg_attendance' => $avgAttendance !== null ? round($avgAttendance, 1) : null,
                'snapshots'      => $snapshots->values(),
            ];
        });

        return response()->json([
            'student_id' => $student->id,
            'enrollments' => $history,
        ]);
    }

    public function whatIf(Request $request, Student $student): JsonResponse
    {
        $data = $request->validate([
            'period' => ['nullable', 'date_format:Y-m'],
            'hypotheticals' => ['required', 'array', 'min:1'],
            'hypotheticals.*.type' => ['required', 'in:academic,attendance,behavior,participation,extracurricular'],
            'hypotheticals.*.new_value' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        $period = $data['period'] ?? now()->format('Y-m');

        return response()->json([
            'period' => $period,
            'results' => $this->whatIfAnalyzer->analyze($student->id, $period, $data['hypotheticals']),
        ]);
    }

    public function classAnalytics(Request $request, string $className, string $section): JsonResponse
    {
        /** @var User|null $viewer */
        $viewer = $request->user();
        // A class name is ambiguous now — "Class 9" exists once per version —
        // so this resolves to every matching section rather than picking one.
        $sectionIds = StudentTaxonomyFilter::sectionIdsForNames($className, $section);
        $isTeacher = (bool) $viewer?->hasAnyRole('teacher');
        $teacherSectionIds = $isTeacher
            ? array_values(array_intersect($sectionIds, TeacherScope::sectionIds($viewer)))
            : $sectionIds;

        if ($isTeacher && $teacherSectionIds === []) {
            abort(Response::HTTP_FORBIDDEN, 'You are not assigned to this class.');
        }

        $visibleSubjectIds = [];
        $fullSubjectVisibility = true;

        if ($isTeacher) {
            foreach ($teacherSectionIds as $teacherSectionId) {
                $visibleSubjectIds = array_merge(
                    $visibleSubjectIds,
                    TeacherScope::assignedSubjectIdsForSection($viewer, $teacherSectionId),
                );
            }

            $visibleSubjectIds = array_values(array_unique($visibleSubjectIds));
            $fullSubjectVisibility = collect($teacherSectionIds)
                ->contains(fn (int $id): bool => TeacherScope::isClassTeacherForSection($viewer, $id));
        }

        $visibleSubjects = $visibleSubjectIds === []
            ? []
            : Subject::query()->whereIn('id', $visibleSubjectIds)->pluck('full_name')->all();

        $period = $this->resolvePeriod($request);
        $studentQuery = Student::query();
        StudentTaxonomyFilter::applySectionIds($studentQuery, $sectionIds);
        $studentIds = $studentQuery->pluck('id');

        $summary = PerformanceSnapshot::query()
            ->whereIn('student_id', $studentIds)
            ->forPeriod($period)
            ->selectRaw("
                COUNT(*) as total,
                ROUND(AVG(overall_score), 1) as avg_overall,
                ROUND(AVG(academic_score), 1) as avg_academic,
                ROUND(AVG(attendance_score), 1) as avg_attendance,
                ROUND(AVG(behavior_score), 1) as avg_behavior,
                ROUND(AVG(participation_score), 1) as avg_participation,
                SUM(CASE WHEN alert_level = 'urgent' THEN 1 ELSE 0 END) as urgent,
                SUM(CASE WHEN alert_level = 'warning' THEN 1 ELSE 0 END) as warning,
                SUM(CASE WHEN alert_level = 'watch' THEN 1 ELSE 0 END) as watch,
                SUM(CASE WHEN overall_score >= 80 THEN 1 ELSE 0 END) as good_performers,
                SUM(CASE WHEN overall_score < 60 THEN 1 ELSE 0 END) as at_risk
            ")
            ->first();

        $year = (int) substr($period, 0, 4);
        $month = (int) substr($period, 5, 2);

        $subjectPerformance = Mark::query()
            ->join('pps_exam_components', 'pps_exam_components.id', '=', 'pps_marks.component_id')
            ->join('pps_exams', 'pps_exams.id', '=', 'pps_exam_components.exam_id')
            ->join('subjects', 'subjects.id', '=', 'pps_marks.subject_id')
            ->whereIn('pps_marks.student_id', $studentIds)
            ->whereYear('pps_exams.exam_date', $year)
            ->when(
                $isTeacher && ! $fullSubjectVisibility,
                fn ($query) => $query->whereIn('subjects.id', $visibleSubjectIds ?: [0])
            )
            ->groupBy('pps_marks.subject_id', 'subjects.full_name')
            ->selectRaw("
                subjects.full_name as subject,
                ROUND(AVG((pps_marks.marks_obtained / NULLIF(pps_exam_components.max_raw_marks, 0)) * 100), 1) as class_avg,
                MIN((pps_marks.marks_obtained / NULLIF(pps_exam_components.max_raw_marks, 0)) * 100) as min_score,
                MAX((pps_marks.marks_obtained / NULLIF(pps_exam_components.max_raw_marks, 0)) * 100) as max_score,
                COUNT(*) as assessment_count
            ")
            ->orderBy('class_avg')
            ->get()
            ->map(function ($row) use ($period, $year, $month): array {
                $schoolAverage = Mark::query()
                    ->join('pps_exam_components', 'pps_exam_components.id', '=', 'pps_marks.component_id')
                    ->join('pps_exams', 'pps_exams.id', '=', 'pps_exam_components.exam_id')
                    ->join('subjects', 'subjects.id', '=', 'pps_marks.subject_id')
                    ->where('subjects.full_name', $row->subject)
                    ->whereYear('pps_exams.exam_date', $year)
                    ->selectRaw('AVG((pps_marks.marks_obtained / NULLIF(pps_exam_components.max_raw_marks, 0)) * 100) as avg_pct')
                    ->value('avg_pct') ?? 0.0;

                return [
                    'subject' => $row->subject,
                    'class_avg' => round((float) $row->class_avg, 1),
                    'min_score' => round((float) $row->min_score, 1),
                    'max_score' => round((float) $row->max_score, 1),
                    'assessment_count' => (int) $row->assessment_count,
                    'school_gap' => round((float) $row->class_avg - (float) $schoolAverage, 1),
                ];
            });

        $classTrend = PerformanceSnapshot::query()
            ->whereIn('student_id', $studentIds)
            ->whereIn('snapshot_period', $this->trendAnalyzer->getLastPeriods($period, 6))
            ->groupBy('snapshot_period')
            ->selectRaw('snapshot_period, ROUND(AVG(overall_score), 1) as avg_score')
            ->orderBy('snapshot_period')
            ->get();

        $schoolTrend = PerformanceSnapshot::query()
            ->whereIn('snapshot_period', $this->trendAnalyzer->getLastPeriods($period, 6))
            ->groupBy('snapshot_period')
            ->selectRaw('snapshot_period, ROUND(AVG(overall_score), 1) as avg_score')
            ->orderBy('snapshot_period')
            ->get()
            ->keyBy('snapshot_period');

        $studentRanking = PerformanceSnapshot::query()
            ->whereIn('student_id', $studentIds)
            ->forPeriod($period)
            ->with('student:id,name,roll_number,photo_path')
            ->orderByDesc('overall_score')
            ->get([
                'student_id',
                'overall_score',
                'academic_score',
                'attendance_score',
                'alert_level',
                'trend_direction',
            ]);

        $rankingPreviousScores = $this->latestPreviousOverallScores(
            $studentRanking->pluck('student_id'),
            $period,
        );

        return response()->json([
            'class_name' => $className,
            'section' => $section,
            'period' => $period,
            'summary' => $summary,
            'subject_performance' => $subjectPerformance,
            'recommendations' => $this->classRecommendations($subjectPerformance->all(), $summary),
            'viewer_scope' => [
                'is_class_teacher' => $isTeacher && $fullSubjectVisibility,
                'subjects' => $fullSubjectVisibility ? [] : $visibleSubjects,
            ],
            'class_trend' => $classTrend->map(fn ($point) => [
                'snapshot_period' => $point->snapshot_period,
                'class_avg' => round((float) $point->avg_score, 1),
                'school_avg' => round((float) ($schoolTrend[$point->snapshot_period]->avg_score ?? 0), 1),
            ])->values(),
            'student_ranking' => $studentRanking->map(
                fn (PerformanceSnapshot $snapshot) => $this->serializeSnapshotWithTrend($snapshot, $rankingPreviousScores)
            )->values(),
        ]);
    }

    public function teacherEffectiveness(Request $request): JsonResponse
    {
        /** @var User|null $viewer */
        $viewer = $request->user();
        $period = $this->resolvePeriod($request);

        // Find the most recent prior period that actually has exam data.
        $previousPeriodDate = DB::table('pps_exams')
            ->whereRaw("TO_CHAR(exam_date, 'YYYY-MM') < ?", [$period])
            ->orderByDesc('exam_date')
            ->value('exam_date');
        $previousPeriod = $previousPeriodDate
            ? Carbon::parse($previousPeriodDate)->format('Y-m')
            : Carbon::createFromFormat('Y-m', $period)->subMonth()->format('Y-m');

        $buildEffectivenessQuery = function (string $p) use ($viewer) {
            $year = (int) substr($p, 0, 4);
            $month = (int) substr($p, 5, 2);

            // An assignment now names a section and a subject id outright, so the
            // old string join on (class_name, section) and on subject NAME is
            // replaced by real keys through the current year's enrollments.
            return DB::table('pps_teacher_assignments as ta')
                ->join('student_enrollments as se', 'se.section_id', '=', 'ta.section_id')
                ->join('academic_years as ay', function ($join) {
                    $join->on('ay.id', '=', 'se.academic_year_id')
                         ->where('ay.is_current', '=', true);
                })
                ->join('pps_marks as m', function ($join) {
                    $join->on('m.student_id', '=', 'se.student_id')
                         ->on('m.subject_id', '=', 'ta.subject_id');
                })
                ->join('subjects as sub', 'sub.id', '=', 'ta.subject_id')
                ->join('pps_exam_components as ec', 'ec.id', '=', 'm.component_id')
                ->join('pps_exams as e', 'e.id', '=', 'ec.exam_id')
                ->whereYear('e.exam_date', $year)
                ->whereMonth('e.exam_date', $month)
                ->when(
                    $viewer?->hasAnyRole('teacher'),
                    fn ($q) => $q->where('ta.teacher_id', TeacherScope::teacherId($viewer) ?? 0)
                )
                ->groupBy('ta.teacher_id', 'sub.full_name')
                ->selectRaw("
                    ta.teacher_id,
                    sub.full_name as subject,
                    ROUND(AVG((m.marks_obtained / NULLIF(ec.max_raw_marks, 0)) * 100), 1) as avg_score,
                    COUNT(DISTINCT m.student_id) as student_count,
                    COUNT(*) as assessment_count
                ")
                ->orderByDesc('avg_score')
                ->get();
        };

        $previous = $buildEffectivenessQuery($previousPeriod)
            ->keyBy(fn ($row) => "{$row->teacher_id}_{$row->subject}");

        $current = $buildEffectivenessQuery($period);

        $allTeacherIds = $current->pluck('teacher_id')
            ->merge($previous->pluck('teacher_id'))
            ->unique();

        // teacher_id now points at teachers, not users: most staff have no login.
        $teacherNames = Teacher::query()->whereIn('id', $allTeacherIds)
            ->pluck('full_name', 'id');

        $effectiveness = $current->map(function ($row) use ($previous, $teacherNames): array {
            $key = "{$row->teacher_id}_{$row->subject}";
            $previousValue = $previous->get($key);

            return [
                'teacher_id' => $row->teacher_id,
                'teacher_name' => $teacherNames[$row->teacher_id] ?? 'Unknown teacher',
                'subject' => $row->subject,
                'avg_score' => round((float) $row->avg_score, 1),
                'previous_avg' => $previousValue ? round((float) $previousValue->avg_score, 1) : null,
                'student_count' => (int) $row->student_count,
                'assessment_count' => (int) $row->assessment_count,
                'change' => $previousValue ? round((float) $row->avg_score - (float) $previousValue->avg_score, 1) : null,
            ];
        });

        return response()->json([
            'period' => $period,
            'data' => $effectiveness,
        ]);
    }

    public function customReport(Request $request): JsonResponse
    {
        $data = $request->validate([
            'period' => ['required', 'date_format:Y-m'],
            'classes' => ['nullable', 'array'],
            'classes.*' => ['string'],
            'sections' => ['nullable', 'array'],
            'sections.*' => ['string'],
            'alert_levels' => ['nullable', 'array'],
            'alert_levels.*' => ['in:urgent,warning,watch,none'],
            'min_risk_score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'sort_by' => ['nullable', 'in:risk_score,overall_score,academic_score,attendance_score'],
            'sort_dir' => ['nullable', 'in:asc,desc'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
            'format' => ['nullable', 'in:json,csv,pdf'],
        ]);

        $query = PerformanceSnapshot::query()
            ->forPeriod($data['period'])
            ->with(array_merge(
                ['student:id,name,roll_number,guardian_phone'],
                StudentTaxonomyFilter::eagerLoadVia('student'),
            ));

        if (! empty($data['classes']) || ! empty($data['sections'])) {
            $sectionIds = Section::query()
                ->when(
                    ! empty($data['classes']),
                    fn (Builder $q) => $q->whereHas('classLevel', fn (Builder $cl) => $cl->whereIn('name', $data['classes']))
                )
                ->when(
                    ! empty($data['sections']),
                    fn (Builder $q) => $q->whereHas('sectionName', fn (Builder $sn) => $sn->whereIn('name', $data['sections']))
                )
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            $query->whereHas(
                'student',
                fn (Builder $studentQuery) => StudentTaxonomyFilter::applySectionIds($studentQuery, $sectionIds)
            );
        }

        if (! empty($data['alert_levels'])) {
            $query->whereIn('alert_level', $data['alert_levels']);
        }

        if (isset($data['min_risk_score'])) {
            $query->where('risk_score', '>=', $data['min_risk_score']);
        }

        $sortBy = $data['sort_by'] ?? 'risk_score';
        $sortDir = $data['sort_dir'] ?? 'desc';
        $limit = $data['limit'] ?? 100;
        $results = $query->orderBy($sortBy, $sortDir)->limit($limit)->get();

        if (($data['format'] ?? 'json') === 'csv') {
            $csv = $this->reportExportService->toCsv(
                ['student', 'class', 'section', 'risk_score', 'overall_score', 'alert_level'],
                $results->map(fn ($row) => [
                    $row->student?->name,
                    $row->student?->class_name,
                    $row->student?->section_name,
                    $row->risk_score,
                    $row->overall_score,
                    $row->alert_level,
                ])->all()
            );

            return response($csv, 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => "attachment; filename=\"pps-custom-{$data['period']}.csv\"",
            ]);
        }

        if (($data['format'] ?? 'json') === 'pdf') {
            $lines = $this->reportExportService->buildAtRiskList($results);

            return response($this->pdfService->render('PPS custom report', $lines), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => "attachment; filename=\"pps-custom-{$data['period']}.pdf\"",
            ]);
        }

        return response()->json([
            'period' => $data['period'],
            'total' => $results->count(),
            'data' => $results,
        ]);
    }

    private function getRecentEvents(int $studentId, string $period): array
    {
        $start = Carbon::createFromFormat('Y-m', $period)->subMonth()->startOfMonth();
        $end = Carbon::createFromFormat('Y-m', $period)->endOfMonth();
        $events = collect();

        BehaviorCard::query()
            ->where('student_id', $studentId)
            ->whereBetween('issued_at', [$start, $end])
            ->get()
            ->each(fn (BehaviorCard $card) => $events->push([
                'type' => 'behavior_card',
                'level' => $card->card_type,
                'text' => $card->reason,
                'date' => $card->issued_at?->toDateString(),
                'display_date' => $card->issued_at?->format('d M'),
            ]));

        Extracurricular::query()
            ->where('student_id', $studentId)
            ->whereBetween('event_date', [$start, $end])
            ->whereNotNull('achievement')
            ->get()
            ->each(fn (Extracurricular $activity) => $events->push([
                'type' => 'achievement',
                'level' => 'green',
                'text' => "{$activity->activity_name}: {$activity->achievement}",
                'date' => $activity->event_date?->toDateString(),
                'display_date' => $activity->event_date?->format('d M'),
            ]));

        PpsAlert::query()
            ->where('student_id', $studentId)
            ->whereBetween('created_at', [$start, $end])
            ->get()
            ->each(fn (PpsAlert $alert) => $events->push([
                'type' => 'alert',
                'level' => $alert->alert_level,
                'text' => collect($alert->trigger_reasons)->pluck('detail')->implode(', '),
                'date' => $alert->created_at?->toDateString(),
                'display_date' => $alert->created_at?->format('d M'),
            ]));

        CounselingSession::query()
            ->where('student_id', $studentId)
            ->whereBetween('session_date', [$start->toDateString(), $end->toDateString()])
            ->get()
            ->each(fn (CounselingSession $session) => $events->push([
                'type' => 'counseling',
                'level' => $session->progress_status ?? 'support',
                'text' => $session->session_type === 'psychometric'
                    ? 'Psychometric review completed.'
                    : 'Counseling follow-up recorded.',
                'date' => $session->session_date?->toDateString(),
                'display_date' => $session->session_date?->format('d M'),
            ]));

        return $events
            ->sortByDesc('date')
            ->values()
            ->take(10)
            ->toArray();
    }

    private function resolvePeriod(Request $request): string
    {
        $requested = $request->string('period')->toString() ?: now()->format('Y-m');

        $exists = PerformanceSnapshot::where('snapshot_period', $requested)->exists();
        if ($exists) {
            return $requested;
        }

        $latest = PerformanceSnapshot::max('snapshot_period');
        return $latest ?? $requested;
    }

    private function classRecommendations(array $subjects, ?object $summary): array
    {
        $recommendations = [];

        foreach ($subjects as $subject) {
            if (($subject['class_avg'] ?? 0) < 60) {
                $recommendations[] = "Most students are weak in {$subject['subject']}; review teaching approach and add remediation time.";
            }
        }

        if (($summary->urgent ?? 0) > 0 && ($summary->warning ?? 0) > 2) {
            $recommendations[] = 'The section has clustered risk and needs a teacher-principal review.';
        }

        return array_values(array_unique($recommendations));
    }

    private function latestPreviousOverallScores(Collection $studentIds, string $period): Collection
    {
        $ids = $studentIds->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return PerformanceSnapshot::query()
            ->whereIn('student_id', $ids)
            ->where('snapshot_period', '<', $period)
            ->orderBy('student_id')
            ->orderByDesc('snapshot_period')
            ->get(['student_id', 'overall_score', 'snapshot_period'])
            ->groupBy('student_id')
            ->map(fn (Collection $rows) => round((float) $rows->first()->overall_score, 1));
    }

    private function serializeSnapshotWithTrend(PerformanceSnapshot $snapshot, Collection $previousOverallScores): array
    {
        $payload = $snapshot->toArray();
        $previousOverall = $previousOverallScores->get($snapshot->student_id);

        $payload['trend_delta'] = $previousOverall === null
            ? null
            : round((float) $snapshot->overall_score - (float) $previousOverall, 1);

        return $payload;
    }
}

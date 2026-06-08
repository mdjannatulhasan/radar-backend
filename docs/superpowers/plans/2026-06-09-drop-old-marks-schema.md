# Drop Old Marks Schema — Migration & Controller Rewrites

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace all references to the six dead tables (`pps_assessments`, `pps_exam_definitions`, `pps_exam_scopes`, `pps_term_marks`, `pps_pretest_marks`, `pps_result_summary`) with queries against the new schema (`pps_exams`, `pps_exam_types`, `pps_exam_components`, `pps_marks`, `pps_computed_scores`), then drop the dead tables and delete the dead PHP files.

**Architecture:** Each file is rewritten in isolation. Dead model files are deleted after every consumer is updated. A final migration drops the old tables in FK-safe order. No new tables or columns are added — only reads move to the new schema.

**Tech Stack:** Laravel 11, PostgreSQL, Eloquent ORM, PHP 8.2

---

## File Map

| File | Change |
|---|---|
| `app/Http/Controllers/Api/V1/Pps/ExamListController.php` | Query `pps_exams` + `pps_exam_types` + `pps_exam_components` instead of `ExamDefinition` |
| `app/Http/Controllers/Api/V1/Pps/ResultSummaryController.php` | Full rewrite — validate against `pps_exams`, read/write `pps_computed_scores` |
| `app/Services/Pps/ScoreCalculatorService.php` | `calcAcademic` + `buildDetailData` read from `pps_computed_scores` |
| `app/Services/Pps/TrendAnalyzerService.php` | `calcSubjectTrend` uses `pps_computed_scores` + PostgreSQL `to_char` |
| `app/Services/Pps/ReportCardService.php` | Replace `ExamDefinition`/`TermMark`/`PretestMark`/`ResultSummary` with new models |
| `app/Http/Controllers/Api/V1/Pps/ParentViewController.php` | `buildPpsResults`: query `pps_computed_scores` grouped by exam |
| `app/Http/Controllers/Api/V1/Pps/DashboardController.php` | Two `Assessment` queries replaced with `pps_computed_scores` |
| `app/Http/Controllers/Api/V1/Pps/AdministrationController.php` | All `ExamDefinition`/`ExamScope`/`TermMark`/`PretestMark`/`ResultSummary`/`Assessment` usages replaced with new models |
| `database/migrations/YYYY_drop_old_marks_tables.php` | Drop 6 tables in FK-safe order |

Dead files to delete (no edits needed):
- `app/Http/Controllers/Api/V1/Pps/TermMarksController.php`
- `app/Http/Controllers/Api/V1/Pps/AssessmentMarksController.php`
- `app/Console/Commands/CollapseExamDefinitions.php`
- `app/Console/Commands/SeedAssessmentExamDefinitions.php`
- `app/Console/Commands/MigrateAssessmentsToTermMarks.php`
- `app/Models/Pps/ExamDefinition.php`
- `app/Models/Pps/ExamScope.php`
- `app/Models/Pps/Assessment.php`
- `app/Models/Pps/TermMark.php`
- `app/Models/Pps/PretestMark.php`
- `app/Models/Pps/ResultSummary.php`

---

## Task 1: Rewrite ExamListController

**Files:**
- Modify: `app/Http/Controllers/Api/V1/Pps/ExamListController.php`

The current controller returns a flat list exploded by scope. The new requirement: one row per exam with a `components[]` array. The route `/exams` is still consumed by the marks-entry selectors — the response shape `{ exams: [...] }` is preserved.

Fields returned per exam: `id, title, term, academic_year, is_terminal, components[]` where each component has `id, name, code, max_raw_marks, max_contribution`.

- [ ] **Step 1: Rewrite the controller**

Replace the entire file content:

```php
<?php

namespace App\Http\Controllers\Api\V1\Pps;

use App\Http\Controllers\Controller;
use App\Models\Pps\Exam;
use Illuminate\Http\JsonResponse;

class ExamListController extends Controller
{
    public function index(): JsonResponse
    {
        $exams = Exam::query()
            ->with([
                'examType:id,name,code,is_terminal',
                'components:id,exam_id,name,code,max_raw_marks,max_contribution,sort_order',
            ])
            ->where('is_active', true)
            ->orderBy('academic_year', 'desc')
            ->orderBy('term')
            ->orderBy('title')
            ->get(['id', 'exam_type_id', 'title', 'term', 'academic_year', 'scope', 'status']);

        $result = $exams->map(fn (Exam $exam) => [
            'id'           => $exam->id,
            'title'        => $exam->title,
            'term'         => $exam->term,
            'academic_year' => $exam->academic_year,
            'is_terminal'  => (bool) ($exam->examType?->is_terminal),
            'scope'        => $exam->scope,
            'components'   => $exam->components->map(fn ($c) => [
                'id'               => $c->id,
                'name'             => $c->name,
                'code'             => $c->code,
                'max_raw_marks'    => $c->max_raw_marks,
                'max_contribution' => $c->max_contribution,
            ])->values()->all(),
        ])->values()->all();

        return response()->json(['exams' => $result]);
    }
}
```

- [ ] **Step 2: Verify no syntax errors**

```bash
cd /Users/hasan/Documents/school-management-system/pps/backend && php artisan route:list --path=exams 2>&1 | head -20
```

Expected: route list shows the exams route without PHP errors.

---

## Task 2: Rewrite ResultSummaryController

**Files:**
- Modify: `app/Http/Controllers/Api/V1/Pps/ResultSummaryController.php`

The controller currently uses `ResultSummary` (empty table) and `TermMark`/`PretestMark`. Rewrite to:
- `index`: read `pps_computed_scores` grouped by student for an exam
- `compute`: recompute via `ComputedScoreService::recomputeForExamSubject` for all (student, subject) combos in that exam's class maps

The `ComputedScoreService` already exists (it was shipped with the new schema). Validate `exam_id` against `pps_exams`.

- [ ] **Step 1: Check the ComputedScoreService signature**

```bash
grep -n "public function" /Users/hasan/Documents/school-management-system/pps/backend/app/Services/Pps/ComputedScoreService.php
```

Read the method signatures before writing the controller.

- [ ] **Step 2: Rewrite the controller**

```php
<?php

namespace App\Http\Controllers\Api\V1\Pps;

use App\Http\Controllers\Controller;
use App\Models\Pps\ComputedScore;
use App\Models\Pps\Exam;
use App\Models\Pps\ExamClassMap;
use App\Models\Student;
use App\Services\Pps\ComputedScoreService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ResultSummaryController extends Controller
{
    public function __construct(
        private readonly ComputedScoreService $scorer,
    ) {
    }

    /**
     * GET /v1/pps/results/summary?exam_id=&letter_grade=
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'exam_id'      => ['required', 'exists:pps_exams,id'],
            'letter_grade' => ['nullable', 'string', 'max:3'],
        ]);

        // Aggregate per-student totals from pps_computed_scores
        $query = ComputedScore::query()
            ->where('exam_id', $data['exam_id'])
            ->with('student:id,name,roll_number,student_code,class_name,section')
            ->groupBy('student_id')
            ->selectRaw('
                student_id,
                SUM(total_obtained)  as total_marks_obtained,
                SUM(total_possible)  as total_marks_full,
                AVG(percentage)      as avg_percentage,
                MIN(letter_grade)    as letter_grade
            ');

        if (! empty($data['letter_grade'])) {
            $query->having(DB::raw('MIN(letter_grade)'), strtoupper(trim($data['letter_grade'])));
        }

        $rows = $query->get()->map(fn ($row) => [
            'student_id'           => $row->student_id,
            'student'              => $row->student,
            'total_marks_obtained' => round((float) $row->total_marks_obtained, 2),
            'total_marks_full'     => round((float) $row->total_marks_full, 2),
            'avg_percentage'       => round((float) $row->avg_percentage, 2),
            'letter_grade'         => $row->letter_grade,
        ])->sortByDesc('total_marks_obtained')->values()->all();

        return response()->json(['data' => $rows]);
    }

    /**
     * POST /v1/pps/results/compute
     * Body: { exam_id }
     */
    public function compute(Request $request): JsonResponse
    {
        $data = $request->validate([
            'exam_id' => ['required', 'exists:pps_exams,id'],
        ]);

        $examId = (int) $data['exam_id'];

        // Gather all (student, subject) combos from pps_exam_class_map
        $classMaps = ExamClassMap::query()
            ->where('exam_id', $examId)
            ->get(['class_id', 'section_id', 'subject_id']);

        $computed = 0;

        DB::transaction(function () use ($classMaps, $examId, &$computed): void {
            foreach ($classMaps as $map) {
                // Find students in this class/section
                $studentIds = Student::query()
                    ->where('class_name', $map->class_id)
                    ->when($map->section_id !== null, fn ($q) => $q->where('section', $map->section_id))
                    ->pluck('id');

                foreach ($studentIds as $studentId) {
                    $this->scorer->recomputeForExamSubject($examId, $studentId, $map->subject_id);
                    $computed++;
                }
            }
        });

        return response()->json(['computed' => $computed]);
    }
}
```

- [ ] **Step 3: Verify syntax**

```bash
cd /Users/hasan/Documents/school-management-system/pps/backend && php artisan route:list --path=results 2>&1 | head -20
```

Expected: routes listed, no PHP parse errors.

---

## Task 3: Rewrite ScoreCalculatorService (academic score + detail data)

**Files:**
- Modify: `app/Services/Pps/ScoreCalculatorService.php`

Two private methods use `Assessment`. Replace them with `pps_computed_scores` joined to `pps_exams`.

- [ ] **Step 1: Replace `calcAcademic` and `buildDetailData` subjects section**

Edit the file — replace the `use App\Models\Pps\Assessment;` import and both methods:

Remove the import line:
```
use App\Models\Pps\Assessment;
```

Add at the top of the imports:
```php
use App\Models\Pps\ComputedScore;
use App\Models\Pps\Subject;
```

Replace the `calcAcademic` method (currently lines 75–88):

```php
private function calcAcademic(int $studentId, int $year, int $month): float
{
    $anchor = Carbon::create($year, $month, 1)->startOfMonth();
    $periodStart = $anchor->copy()->subMonths(2)->startOfMonth();
    $periodEnd = $anchor->copy()->endOfMonth();

    $result = ComputedScore::query()
        ->join('pps_exams', 'pps_exams.id', '=', 'pps_computed_scores.exam_id')
        ->where('pps_computed_scores.student_id', $studentId)
        ->whereBetween('pps_exams.exam_date', [$periodStart, $periodEnd])
        ->selectRaw('AVG(pps_computed_scores.percentage) as avg_pct, COUNT(*) as total')
        ->first();

    return ($result?->total ?? 0) > 0 ? round((float) $result->avg_pct, 2) : 70.0;
}
```

Replace the `buildDetailData` method's `$subjects` block (lines 190–203) — change only the Assignment/Assessment query, leave the rest:

```php
private function buildDetailData(int $studentId, int $year, int $month): array
{
    $periodStart = Carbon::create($year, $month, 1)->startOfMonth();
    $periodEnd = Carbon::create($year, $month, 1)->endOfMonth();
    $currentPeriod = sprintf('%04d-%02d', $year, $month);

    $subjects = ComputedScore::query()
        ->join('pps_exams', 'pps_exams.id', '=', 'pps_computed_scores.exam_id')
        ->join('pps_subjects', 'pps_subjects.id', '=', 'pps_computed_scores.subject_id')
        ->where('pps_computed_scores.student_id', $studentId)
        ->whereBetween('pps_exams.exam_date', [$periodStart, $periodEnd])
        ->groupBy('pps_computed_scores.subject_id', 'pps_subjects.name')
        ->selectRaw('pps_subjects.name as subject, AVG(pps_computed_scores.percentage) as avg, COUNT(*) as total')
        ->get()
        ->mapWithKeys(fn ($row) => [
            $row->subject => [
                'avg'   => round((float) $row->avg, 1),
                'count' => (int) $row->total,
                'trend' => $this->trendAnalyzer->calcSubjectTrend($studentId, (int) $row->subject_id ?? 0, $currentPeriod),
            ],
        ])
        ->toArray();

    $attendance = AttendanceRecord::query()
        ->where('student_id', $studentId)
        ->whereYear('date', $year)
        ->whereMonth('date', $month)
        ->whereNull('period')
        ->selectRaw("
            COUNT(*) as total,
            SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent,
            SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late
        ")
        ->first();

    $cards = BehaviorCard::query()
        ->where('student_id', $studentId)
        ->whereYear('issued_at', $year)
        ->whereMonth('issued_at', $month)
        ->selectRaw("
            SUM(CASE WHEN card_type = 'green' THEN 1 ELSE 0 END) as green,
            SUM(CASE WHEN card_type = 'yellow' THEN 1 ELSE 0 END) as yellow,
            SUM(CASE WHEN card_type = 'red' THEN 1 ELSE 0 END) as red
        ")
        ->first();

    return [
        'subjects' => $subjects,
        'attendance' => [
            'total'  => (int) ($attendance?->total ?? 0),
            'absent' => (int) ($attendance?->absent ?? 0),
            'late'   => (int) ($attendance?->late ?? 0),
        ],
        'cards' => [
            'green'  => (int) ($cards?->green ?? 0),
            'yellow' => (int) ($cards?->yellow ?? 0),
            'red'    => (int) ($cards?->red ?? 0),
        ],
        'calculated_at' => now()->toISOString(),
    ];
}
```

> NOTE: `calcSubjectTrend` still takes `(int $studentId, string $subject, string $period)` in the old code but will be changed to `(int $studentId, int $subjectId, string $period)` in Task 4. Update the call here to pass `$row->subject_id` cast to int: `$this->trendAnalyzer->calcSubjectTrend($studentId, (int) $row->subject_id, $currentPeriod)`.

- [ ] **Step 2: Verify syntax**

```bash
cd /Users/hasan/Documents/school-management-system/pps/backend && php -l app/Services/Pps/ScoreCalculatorService.php
```

Expected: `No syntax errors detected`

---

## Task 4: Rewrite TrendAnalyzerService

**Files:**
- Modify: `app/Services/Pps/TrendAnalyzerService.php`

`calcSubjectTrend` currently uses SQLite-specific `strftime` and queries the `Assessment` model. Replace with `pps_computed_scores` joined to `pps_exams` using PostgreSQL `to_char`. Signature change: `string $subject` → `int $subjectId`.

- [ ] **Step 1: Rewrite the file**

```php
<?php

namespace App\Services\Pps;

use App\Models\Pps\ComputedScore;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class TrendAnalyzerService
{
    public function calcTrend(float $current, array $history): string
    {
        if ($history === []) {
            return 'stable';
        }

        $previousAverage = array_sum($history) / count($history);
        $change = $current - $previousAverage;

        return match (true) {
            $change >= 5  => 'up',
            $change <= -15 => 'rapid_down',
            $change <= -5  => 'down',
            default        => 'stable',
        };
    }

    public function calcSubjectTrend(int $studentId, int $subjectId, string $currentPeriod): array
    {
        $periods = $this->getLastPeriods($currentPeriod, 6);

        return ComputedScore::query()
            ->join('pps_exams', 'pps_exams.id', '=', 'pps_computed_scores.exam_id')
            ->where('pps_computed_scores.student_id', $studentId)
            ->where('pps_computed_scores.subject_id', $subjectId)
            ->whereIn(DB::raw("to_char(pps_exams.exam_date, 'YYYY-MM')"), $periods)
            ->groupBy(DB::raw("to_char(pps_exams.exam_date, 'YYYY-MM')"))
            ->selectRaw("to_char(pps_exams.exam_date, 'YYYY-MM') as period, AVG(pps_computed_scores.percentage) as avg_pct")
            ->orderBy('period')
            ->get()
            ->map(fn ($row) => [
                'period' => $row->period,
                'score'  => round((float) $row->avg_pct, 1),
            ])
            ->toArray();
    }

    public function getLastPeriods(string $currentPeriod, int $count): array
    {
        $periods = [];
        $date = Carbon::createFromFormat('Y-m', $currentPeriod)->startOfMonth();

        for ($index = 0; $index < $count; $index++) {
            $periods[] = $date->copy()->subMonths($index)->format('Y-m');
        }

        return array_reverse($periods);
    }
}
```

- [ ] **Step 2: Verify syntax**

```bash
cd /Users/hasan/Documents/school-management-system/pps/backend && php -l app/Services/Pps/TrendAnalyzerService.php
```

Expected: `No syntax errors detected`

---

## Task 5: Rewrite ReportCardService

**Files:**
- Modify: `app/Services/Pps/ReportCardService.php`

This is the most complex rewrite. The service uses `ExamDefinition`, `TermMark`, `PretestMark`, and `ResultSummary`. New approach:
- `Exam::findOrFail($examId)` instead of `ExamDefinition::findOrFail`
- Marks come from `pps_marks` joined to `pps_exam_components` for the exam
- Result summary data (GPA, grade, position) comes from `pps_computed_scores` aggregated per student
- The `$summary` object shape used in HTML templates: `total_marks_obtained`, `total_marks_full`, `gpa`, `letter_grade`, `class_position`, `total_students_in_class`, `is_promoted`, `discipline`, `handwriting`, `total_working_days`, `total_presence` — most of these no longer exist. Use a plain array/stdClass constructed from computed scores.
- Component marks are accessed by component code (e.g. `spot_test`, `class_test2`, etc.) instead of model fields.
- The tabulation methods will use `pps_marks` + `pps_computed_scores`.

**Key design decision:** Build a data-transfer object (plain array) for the summary, and build a per-subject marks map from `pps_marks` grouped by component code.

- [ ] **Step 1: Read the `Exam` model fields available**

The `Exam` model has: `id, exam_type_id, title, academic_year, term, exam_date, scope, status, is_active`. It does NOT have `class_name`, `section`, `assessment_type`. The tabulation methods that use `$exam->class_name` and `$exam->section` must instead derive class/section from `pps_exam_class_map`.

- [ ] **Step 2: Rewrite the file**

Replace the entire file:

```php
<?php

namespace App\Services\Pps;

use App\Models\Pps\ComputedScore;
use App\Models\Pps\Exam;
use App\Models\Pps\ExamClassMap;
use App\Models\Pps\Mark;
use App\Models\Student;
use Illuminate\Support\Facades\DB;
use Mpdf\Mpdf;

class ReportCardService
{
    /**
     * Generate a report card PDF for one student.
     *
     * @return string raw PDF bytes
     */
    public function generate(int $studentId, int $examId): string
    {
        $student = Student::query()->with('stream')->findOrFail($studentId);
        $exam    = Exam::query()->with('examType', 'components')->findOrFail($examId);
        $summary = $this->buildSummary($examId, $studentId);

        $classLevel = (int) $student->class_name;

        $html = $classLevel >= 11
            ? $this->buildFormatB($student, $exam, $summary)
            : $this->buildFormatA($student, $exam, $summary);

        return $this->renderPdf($html);
    }

    /**
     * Generate a tabulation sheet PDF for an exam.
     *
     * @return string raw PDF bytes
     */
    public function generateTabulation(int $examId): string
    {
        $exam = Exam::query()->with('examType', 'components', 'classMaps')->findOrFail($examId);

        // Take the first classMap to get class/section context (tabulation is per class-section)
        $firstMap = $exam->classMaps->first();
        $className = $firstMap?->class_id;
        $section   = $firstMap?->section_id;
        $classLevel = (int) $className;

        $students = Student::query()
            ->where('class_name', $className)
            ->when($section !== null, fn ($q) => $q->where('section', $section))
            ->orderBy('roll_number')
            ->get();

        $html = $classLevel >= 11
            ? $this->buildTabulationB($students, $exam, $className, $section)
            : $this->buildTabulationA($students, $exam, $className, $section);

        return $this->renderPdf($html, 'L');
    }

    // ─── Summary builder ──────────────────────────────────────────────────────

    /**
     * Build a summary array for a student+exam from pps_computed_scores.
     * Returns an array with keys matching the old ResultSummary model fields used in templates.
     */
    private function buildSummary(int $examId, int $studentId): array
    {
        $scores = ComputedScore::query()
            ->where('exam_id', $examId)
            ->where('student_id', $studentId)
            ->get();

        if ($scores->isEmpty()) {
            return [
                'total_marks_obtained'    => null,
                'total_marks_full'        => null,
                'gpa'                     => null,
                'letter_grade'            => null,
                'class_position'          => null,
                'total_students_in_class' => null,
                'is_promoted'             => null,
                'discipline'              => null,
                'handwriting'             => null,
                'total_working_days'      => null,
                'total_presence'          => null,
            ];
        }

        $totalObtained = $scores->sum('total_obtained');
        $totalPossible = $scores->sum('total_possible');
        $avgGp = $scores->avg('grade_point');

        // Determine overall grade from average percentage
        $overallPct = $totalPossible > 0 ? ($totalObtained / $totalPossible) * 100 : 0;
        $letterGrade = $scores->sortByDesc('percentage')->first()?->letter_grade ?? 'F';

        // Compute rank among all students in this exam
        $allStudentTotals = ComputedScore::query()
            ->where('exam_id', $examId)
            ->groupBy('student_id')
            ->selectRaw('student_id, SUM(total_obtained) as grand_total')
            ->orderByDesc('grand_total')
            ->pluck('grand_total', 'student_id');

        $position = null;
        $rank = 1;
        foreach ($allStudentTotals as $sid => $total) {
            if ($sid === $studentId) {
                $position = $rank;
                break;
            }
            $rank++;
        }

        return [
            'total_marks_obtained'    => round($totalObtained, 2),
            'total_marks_full'        => round($totalPossible, 2),
            'gpa'                     => round((float) $avgGp, 2),
            'letter_grade'            => $letterGrade,
            'class_position'          => $position,
            'total_students_in_class' => $allStudentTotals->count(),
            'is_promoted'             => null, // not tracked in new schema
            'discipline'              => null,
            'handwriting'             => null,
            'total_working_days'      => null,
            'total_presence'          => null,
        ];
    }

    /**
     * Build per-subject marks map from pps_marks for a student+exam.
     * Returns: [ subject_id => [ component_code => marks_obtained, ... ], ... ]
     */
    private function buildMarksMap(int $examId, int $studentId): array
    {
        $marks = Mark::query()
            ->join('pps_exam_components', 'pps_exam_components.id', '=', 'pps_marks.component_id')
            ->where('pps_exam_components.exam_id', $examId)
            ->where('pps_marks.student_id', $studentId)
            ->select('pps_marks.subject_id', 'pps_exam_components.code', 'pps_marks.marks_obtained')
            ->get();

        $map = [];
        foreach ($marks as $mark) {
            $map[$mark->subject_id][$mark->code] = $mark->marks_obtained;
        }
        return $map;
    }

    // ─── Format A — Classes 4–10 ─────────────────────────────────────────────

    private function buildFormatA(Student $student, Exam $exam, array $summary): string
    {
        $marksMap = $this->buildMarksMap($exam->id, $student->id);

        // Get per-subject computed scores for grade/total
        $subjectScores = ComputedScore::query()
            ->where('exam_id', $exam->id)
            ->where('student_id', $student->id)
            ->with('subject:id,name')
            ->orderBy('subject_id')
            ->get()
            ->keyBy('subject_id');

        $isSecondTerm = $exam->term === 2;

        $subjectRows = '';
        $i = 0;
        foreach ($subjectScores as $subjectId => $score) {
            $bg = ($i % 2 === 0) ? '#ffffff' : '#f5f7fb';
            $subjectName = htmlspecialchars($score->subject?->name ?? '');
            $compMarks = $marksMap[$subjectId] ?? [];

            $gradeColor = $this->gradeColor($score->letter_grade);

            if ($isSecondTerm) {
                $vtRaw = $this->fmt($compMarks['vt'] ?? null);
                $vtCon = $this->fmt($compMarks['vt_con'] ?? null);
                $vtCells = "<td style='background:{$bg}'>{$vtRaw}</td><td style='background:{$bg}'>{$vtCon}</td>";
            } else {
                $vtCells = "<td style='background:{$bg}'>—</td><td style='background:{$bg}'>—</td>";
            }

            $subjectRows .= "
            <tr>
                <td style='text-align:left;padding-left:6px;background:{$bg}'>{$subjectName}</td>
                <td style='background:{$bg}'>{$this->fmt($compMarks['spot_test'] ?? null)}</td>
                <td style='background:{$bg}'>{$this->fmt($compMarks['spot_test_con'] ?? null)}</td>
                <td style='background:{$bg}'>{$this->fmt($compMarks['class_test2'] ?? null)}</td>
                <td style='background:{$bg}'>{$this->fmt($compMarks['class_test2_con'] ?? null)}</td>
                <td style='background:{$bg}'>{$this->fmt($compMarks['attendance'] ?? null)}</td>
                <td style='background:{$bg}'>{$this->fmt($compMarks['term_marks'] ?? null)}</td>
                <td style='background:{$bg}'>{$this->fmt($compMarks['term_con'] ?? null)}</td>
                {$vtCells}
                <td style='font-weight:bold;background:{$bg}'>{$this->fmt($score->total_obtained)}</td>
                <td style='font-weight:bold;color:{$gradeColor};background:{$bg}'>{$score->letter_grade}</td>
                <td style='background:{$bg}'>—</td>
            </tr>";
            $i++;
        }

        $promotionText  = '—';
        $promotionColor = '#555';
        $totalObtained  = $this->fmt($summary['total_marks_obtained']);
        $totalFull      = $this->fmt($summary['total_marks_full']);
        $gpa            = $this->fmt($summary['gpa']);
        $letterGrade    = $summary['letter_grade'] ?? '—';
        $classPos       = $summary['class_position'] ?? '—';
        $totalStudents  = $summary['total_students_in_class'] ?? '—';
        $discipline     = $summary['discipline'] ?? '—';
        $handwriting    = $summary['handwriting'] ?? '—';
        $workingDays    = $summary['total_working_days'] ?? '—';
        $presence       = $summary['total_presence'] ?? '—';
        $gpaColor       = $this->gpaColor((float) ($summary['gpa'] ?? 0));
        $stream         = htmlspecialchars($student->stream?->name ?? '—');
        $examYear       = $exam->exam_date ? date('Y', strtotime($exam->exam_date)) : date('Y');
        $examTitle      = htmlspecialchars($exam->title ?? '');

        $vtHeaderSpan = $isSecondTerm
            ? '<th colspan="2" style="background:#1a3a5c">2nd Term Exam</th>'
            : '<th colspan="2" style="background:#1a3a5c;opacity:0.6">2nd Term</th>';

        return $this->wrapHtml("
        " . $this->pageHeader($examYear, $examTitle) . "
        " . $this->studentInfoTable($student, $exam, $stream) . "

        <table class='marks-table'>
            <thead>
                <tr>
                    <th rowspan='2' class='subj-head'>Subjects</th>
                    <th colspan='8' style='background:#1a3a5c'>First Term Exam</th>
                    {$vtHeaderSpan}
                    <th rowspan='2' class='total-head'>Total<br>Marks</th>
                    <th rowspan='2' class='grade-head'>Grade</th>
                    <th rowspan='2' class='high-head'>Highest<br>Marks</th>
                </tr>
                <tr>
                    <th>Spot<br>Test</th>
                    <th>ST<br>Con</th>
                    <th>CT-2</th>
                    <th>CT-2<br>Con</th>
                    <th>Att</th>
                    <th>Term<br>Marks</th>
                    <th>Term<br>Con</th>
                    <th>Grade</th>
                    <th>VT</th>
                    <th>VT<br>Con</th>
                </tr>
            </thead>
            <tbody>{$subjectRows}</tbody>
        </table>

        <table style='width:100%;border-collapse:collapse;margin-top:8px;font-size:8.5pt'>
            <tr>
                <td style='width:50%;vertical-align:top;padding-right:8px'>
                    <table style='width:100%;border-collapse:collapse;border:1px solid #ccc'>
                        <tr style='background:#e8edf5'>
                            <td style='padding:4px 8px;border:1px solid #ccc;font-weight:bold'>Total Students in Class</td>
                            <td style='padding:4px 8px;border:1px solid #ccc;text-align:center'>{$totalStudents}</td>
                            <td style='padding:4px 8px;border:1px solid #ccc;font-weight:bold'>Working Days</td>
                            <td style='padding:4px 8px;border:1px solid #ccc;text-align:center'>{$workingDays}</td>
                        </tr>
                        <tr>
                            <td style='padding:4px 8px;border:1px solid #ccc;font-weight:bold'>Total Marks</td>
                            <td style='padding:4px 8px;border:1px solid #ccc;text-align:center'>{$totalObtained} / {$totalFull}</td>
                            <td style='padding:4px 8px;border:1px solid #ccc;font-weight:bold'>Total Presence</td>
                            <td style='padding:4px 8px;border:1px solid #ccc;text-align:center'>{$presence}</td>
                        </tr>
                        <tr style='background:#e8edf5'>
                            <td style='padding:4px 8px;border:1px solid #ccc;font-weight:bold'>Discipline</td>
                            <td style='padding:4px 8px;border:1px solid #ccc;text-align:center'>{$discipline}</td>
                            <td style='padding:4px 8px;border:1px solid #ccc;font-weight:bold'>Hand Writing</td>
                            <td style='padding:4px 8px;border:1px solid #ccc;text-align:center'>{$handwriting}</td>
                        </tr>
                        <tr>
                            <td style='padding:4px 8px;border:1px solid #ccc;font-weight:bold'>GPA</td>
                            <td style='padding:4px 8px;border:1px solid #ccc;text-align:center;font-weight:bold;color:{$gpaColor}'>{$gpa}</td>
                            <td style='padding:4px 8px;border:1px solid #ccc;font-weight:bold'>Grade</td>
                            <td style='padding:4px 8px;border:1px solid #ccc;text-align:center;font-weight:bold'>{$letterGrade}</td>
                        </tr>
                        <tr style='background:#e8edf5'>
                            <td style='padding:4px 8px;border:1px solid #ccc;font-weight:bold'>Position</td>
                            <td style='padding:4px 8px;border:1px solid #ccc;text-align:center'>{$classPos} / {$totalStudents}</td>
                            <td style='padding:6px 8px;border:1px solid #ccc;font-weight:bold;font-size:9pt'>Result</td>
                            <td style='padding:6px 8px;border:1px solid #ccc;text-align:center;font-weight:bold;color:{$promotionColor};font-size:9pt'>{$promotionText}</td>
                        </tr>
                    </table>
                </td>
                <td style='width:50%;vertical-align:top'>
                    " . $this->gradeTable() . "
                </td>
            </tr>
        </table>

        " . $this->signatureBlock() . "
        ");
    }

    // ─── Format B — Class 11–12 ───────────────────────────────────────────────

    private function buildFormatB(Student $student, Exam $exam, array $summary): string
    {
        $marksMap = $this->buildMarksMap($exam->id, $student->id);

        $subjectScores = ComputedScore::query()
            ->where('exam_id', $exam->id)
            ->where('student_id', $student->id)
            ->with('subject:id,name')
            ->orderBy('subject_id')
            ->get()
            ->keyBy('subject_id');

        $subjectRows = '';
        $i = 0;
        foreach ($subjectScores as $subjectId => $score) {
            $bg = ($i % 2 === 0) ? '#ffffff' : '#f5f7fb';
            $subjectName = htmlspecialchars($score->subject?->name ?? '');
            $compMarks   = $marksMap[$subjectId] ?? [];
            $gradeColor  = $this->gradeColor($score->letter_grade);

            $subjectRows .= "
            <tr>
                <td style='text-align:left;padding-left:6px;background:{$bg}'>{$subjectName}</td>
                <td style='background:{$bg}'>{$this->fmt($compMarks['ct'] ?? null)}</td>
                <td style='background:{$bg}'>{$this->fmt($compMarks['attendance'] ?? null)}</td>
                <td style='background:{$bg}'>{$this->fmt($compMarks['cq'] ?? null)}</td>
                <td style='background:{$bg}'>{$this->fmt($compMarks['cq_con'] ?? null)}</td>
                <td style='background:{$bg}'>{$this->fmt($compMarks['mcq'] ?? null)}</td>
                <td style='background:{$bg}'>{$this->fmt($compMarks['mcq_con'] ?? null)}</td>
                <td style='font-weight:bold;background:{$bg}'>{$this->fmt($score->total_obtained)}</td>
                <td style='font-weight:bold;color:{$gradeColor};background:{$bg}'>{$score->letter_grade}</td>
                <td style='background:{$bg}'>{$this->fmt($score->grade_point)}</td>
                <td style='background:{$bg}'>—</td>
                <td style='background:{$bg}'>—</td>
            </tr>";
            $i++;
        }

        $bTotal    = $this->fmt($summary['total_marks_obtained']);
        $bGpa      = $this->fmt($summary['gpa']);
        $bGrade    = $summary['letter_grade'] ?? '—';
        $bStudents = $summary['total_students_in_class'] ?? '—';
        $bPos      = $summary['class_position'] ?? '—';
        $promotionText  = '—';
        $promotionColor = '#555';
        $gpaColor       = $this->gpaColor((float) ($summary['gpa'] ?? 0));
        $stream         = htmlspecialchars($student->stream?->name ?? '—');
        $examYear       = $exam->exam_date ? date('Y', strtotime($exam->exam_date)) : date('Y');
        $examTitle      = htmlspecialchars($exam->title ?? '');

        return $this->wrapHtml("
        " . $this->pageHeader($examYear, $examTitle) . "
        " . $this->studentInfoTable($student, $exam, $stream) . "

        <table class='marks-table'>
            <thead>
                <tr>
                    <th class='subj-head'>Subject</th>
                    <th>CT</th>
                    <th>Att</th>
                    <th>CQ</th>
                    <th>CQ<br>Con</th>
                    <th>MCQ</th>
                    <th>MCQ<br>Con</th>
                    <th class='total-head'>Total</th>
                    <th class='grade-head'>Grade</th>
                    <th>GP</th>
                    <th class='high-head'>Highest</th>
                    <th>Promotion<br>Grade</th>
                </tr>
            </thead>
            <tbody>{$subjectRows}</tbody>
        </table>

        <table style='width:100%;border-collapse:collapse;margin-top:8px;font-size:8.5pt'>
            <tr>
                <td style='width:50%;vertical-align:top;padding-right:8px'>
                    <table style='width:100%;border-collapse:collapse;border:1px solid #ccc'>
                        <tr style='background:#e8edf5'>
                            <td style='padding:4px 8px;border:1px solid #ccc;font-weight:bold'>Total Students</td>
                            <td style='padding:4px 8px;border:1px solid #ccc;text-align:center'>{$bStudents}</td>
                            <td style='padding:4px 8px;border:1px solid #ccc;font-weight:bold'>Total Marks</td>
                            <td style='padding:4px 8px;border:1px solid #ccc;text-align:center'>{$bTotal}</td>
                        </tr>
                        <tr>
                            <td style='padding:4px 8px;border:1px solid #ccc;font-weight:bold'>GPA</td>
                            <td style='padding:4px 8px;border:1px solid #ccc;text-align:center;font-weight:bold;color:{$gpaColor}'>{$bGpa}</td>
                            <td style='padding:4px 8px;border:1px solid #ccc;font-weight:bold'>Grade</td>
                            <td style='padding:4px 8px;border:1px solid #ccc;text-align:center;font-weight:bold'>{$bGrade}</td>
                        </tr>
                        <tr style='background:#e8edf5'>
                            <td style='padding:4px 8px;border:1px solid #ccc;font-weight:bold'>Position</td>
                            <td style='padding:4px 8px;border:1px solid #ccc;text-align:center'>{$bPos} / {$bStudents}</td>
                            <td style='padding:6px 8px;border:1px solid #ccc;font-weight:bold'>Result</td>
                            <td style='padding:6px 8px;border:1px solid #ccc;text-align:center;font-weight:bold;color:{$promotionColor}'>{$promotionText}</td>
                        </tr>
                    </table>
                </td>
                <td style='width:50%;vertical-align:top'>
                    " . $this->gradeTable() . "
                </td>
            </tr>
        </table>

        " . $this->signatureBlock() . "
        ");
    }

    // ─── Tabulation sheets ────────────────────────────────────────────────────

    private function buildTabulationA($students, Exam $exam, ?string $className, ?string $section): string
    {
        $examId = $exam->id;

        // Subject list from computed scores for this exam
        $subjects = ComputedScore::query()
            ->where('exam_id', $examId)
            ->with('subject:id,name')
            ->get()
            ->unique('subject_id')
            ->sortBy('subject_id')
            ->values();

        $subjectHeaders = $subjects->map(fn ($s) => "<th>" . htmlspecialchars($s->subject?->name ?? '') . "</th>")->implode('');

        $rows = '';
        foreach ($students as $i => $student) {
            $bg = ($i % 2 === 0) ? '#ffffff' : '#f5f7fb';
            $studentScores = ComputedScore::query()
                ->where('exam_id', $examId)
                ->where('student_id', $student->id)
                ->get()
                ->keyBy('subject_id');

            $cells = $subjects->map(function ($s) use ($studentScores, $bg): string {
                $score = $studentScores->get($s->subject_id);
                return "<td style='background:{$bg}'>{$this->fmt($score?->total_obtained)}</td>";
            })->implode('');

            $allScores = $studentScores->values();
            $tTotal = $this->fmt($allScores->sum('total_obtained'));
            $tGpa   = $this->fmt($allScores->avg('grade_point'));
            $tGrade = $allScores->sortByDesc('percentage')->first()?->letter_grade ?? '—';
            $gColor = $this->gradeColor($tGrade);

            // Rank
            $grandTotals = ComputedScore::query()
                ->where('exam_id', $examId)
                ->groupBy('student_id')
                ->selectRaw('student_id, SUM(total_obtained) as grand_total')
                ->orderByDesc('grand_total')
                ->pluck('grand_total', 'student_id');

            $tPos = '—';
            $rank = 1;
            foreach ($grandTotals as $sid => $total) {
                if ($sid === $student->id) { $tPos = $rank; break; }
                $rank++;
            }

            $rows .= "<tr>
                <td style='background:{$bg};text-align:center'>{$student->roll_number}</td>
                <td style='background:{$bg};text-align:left;padding-left:4px'>" . htmlspecialchars($student->name) . "</td>
                {$cells}
                <td style='font-weight:bold;background:{$bg}'>{$tTotal}</td>
                <td style='background:{$bg}'>{$tGpa}</td>
                <td style='font-weight:bold;color:{$gColor};background:{$bg}'>{$tGrade}</td>
                <td style='background:{$bg}'>{$tPos}</td>
            </tr>";
        }

        $year = $exam->exam_date ? date('Y', strtotime($exam->exam_date)) : date('Y');

        return $this->wrapHtml("
        <div style='text-align:center;border-bottom:2px solid #1a3a5c;padding-bottom:6px;margin-bottom:8px'>
            <div style='font-size:15pt;font-weight:bold;color:#1a3a5c'>Cantonment Public School &amp; College, Saidpur</div>
            <div style='font-size:11pt;font-weight:bold;margin-top:2px'>Tabulation Sheet — {$year}</div>
            <div style='font-size:9pt;color:#444;margin-top:2px'>Class {$className} — Section {$section} — " . htmlspecialchars($exam->title) . "</div>
        </div>
        <table class='marks-table tabulation'>
            <thead>
                <tr>
                    <th style='width:30px'>Roll</th>
                    <th style='text-align:left;min-width:100px'>Name</th>
                    {$subjectHeaders}
                    <th>Total</th><th>GPA</th><th>Grade</th><th>Pos</th>
                </tr>
            </thead>
            <tbody>{$rows}</tbody>
        </table>
        ");
    }

    private function buildTabulationB($students, Exam $exam, ?string $className, ?string $section): string
    {
        $examId = $exam->id;

        $subjects = ComputedScore::query()
            ->where('exam_id', $examId)
            ->with('subject:id,name')
            ->get()
            ->unique('subject_id')
            ->sortBy('subject_id')
            ->values();

        $subjectHeaders = $subjects->map(fn ($s) => "<th>" . htmlspecialchars($s->subject?->name ?? '') . "</th>")->implode('');

        $rows = '';
        foreach ($students as $i => $student) {
            $bg = ($i % 2 === 0) ? '#ffffff' : '#f5f7fb';
            $studentScores = ComputedScore::query()
                ->where('exam_id', $examId)
                ->where('student_id', $student->id)
                ->get()
                ->keyBy('subject_id');

            $cells = $subjects->map(function ($s) use ($studentScores, $bg): string {
                $score = $studentScores->get($s->subject_id);
                return "<td style='background:{$bg}'>{$this->fmt($score?->total_obtained)}</td>";
            })->implode('');

            $allScores = $studentScores->values();
            $bTotal = $this->fmt($allScores->sum('total_obtained'));
            $bGpa   = $this->fmt($allScores->avg('grade_point'));
            $bGrade = $allScores->sortByDesc('percentage')->first()?->letter_grade ?? '—';
            $gColor = $this->gradeColor($bGrade);

            $rows .= "<tr>
                <td style='background:{$bg};text-align:center'>{$student->roll_number}</td>
                <td style='background:{$bg};text-align:left;padding-left:4px'>" . htmlspecialchars($student->name) . "</td>
                {$cells}
                <td style='font-weight:bold;background:{$bg}'>{$bTotal}</td>
                <td style='background:{$bg}'>{$bGpa}</td>
                <td style='font-weight:bold;color:{$gColor};background:{$bg}'>{$bGrade}</td>
            </tr>";
        }

        $year = $exam->exam_date ? date('Y', strtotime($exam->exam_date)) : date('Y');

        return $this->wrapHtml("
        <div style='text-align:center;border-bottom:2px solid #1a3a5c;padding-bottom:6px;margin-bottom:8px'>
            <div style='font-size:15pt;font-weight:bold;color:#1a3a5c'>Cantonment Public School &amp; College, Saidpur</div>
            <div style='font-size:11pt;font-weight:bold;margin-top:2px'>Tabulation Sheet — {$year}</div>
            <div style='font-size:9pt;color:#444;margin-top:2px'>Class {$className} — Section {$section} — " . htmlspecialchars($exam->title) . "</div>
        </div>
        <table class='marks-table tabulation'>
            <thead>
                <tr>
                    <th style='width:30px'>Roll</th>
                    <th style='text-align:left;min-width:100px'>Name</th>
                    {$subjectHeaders}
                    <th>Total</th><th>GPA</th><th>Grade</th>
                </tr>
            </thead>
            <tbody>{$rows}</tbody>
        </table>
        ");
    }

    // ─── Page layout helpers ──────────────────────────────────────────────────

    private function pageHeader(string $year, string $examTitle): string
    {
        $logoSvg = $this->schoolLogoSvg();

        return "
        <table style='width:100%;border-bottom:2px solid #1a3a5c;margin-bottom:8px;padding-bottom:6px'>
            <tr>
                <td style='width:60px;text-align:center;vertical-align:middle'>{$logoSvg}</td>
                <td style='text-align:center;vertical-align:middle'>
                    <div style='font-size:14pt;font-weight:bold;color:#1a3a5c'>Cantonment Public School &amp; College, Saidpur</div>
                    <div style='font-size:10pt;font-weight:bold;margin-top:3px'>Students Progress Report — {$year}</div>
                    <div style='font-size:8pt;color:#555;margin-top:2px'>{$examTitle}</div>
                </td>
                <td style='width:60px;text-align:center;vertical-align:middle'>{$logoSvg}</td>
            </tr>
        </table>";
    }

    private function studentInfoTable(Student $student, Exam $exam, string $stream): string
    {
        $name    = htmlspecialchars($student->name ?? '');
        $code    = htmlspecialchars($student->student_code ?? '');
        $roll    = $student->roll_number ?? '—';
        $class   = $student->class_name ?? '—';
        $section = $student->section ?? '—';
        $gender  = ucfirst($student->gender ?? '—');

        return "
        <table style='width:100%;border-collapse:collapse;margin-bottom:8px;font-size:8.5pt;border:1px solid #ccc'>
            <tr style='background:#e8edf5'>
                <td style='padding:5px 8px;border:1px solid #ccc;font-weight:bold;width:80px'>Name</td>
                <td style='padding:5px 8px;border:1px solid #ccc;font-weight:bold'>{$name}</td>
                <td style='padding:5px 8px;border:1px solid #ccc;font-weight:bold;width:80px'>Student ID</td>
                <td style='padding:5px 8px;border:1px solid #ccc'>{$code}</td>
                <td style='padding:5px 8px;border:1px solid #ccc;font-weight:bold;width:70px'>Class Roll</td>
                <td style='padding:5px 8px;border:1px solid #ccc'>{$roll}</td>
                <td rowspan='2' style='width:55px;text-align:center;border:1px solid #ccc;vertical-align:middle;color:#aaa;font-size:7pt'>Photo</td>
            </tr>
            <tr>
                <td style='padding:5px 8px;border:1px solid #ccc;font-weight:bold'>Class</td>
                <td style='padding:5px 8px;border:1px solid #ccc'>{$class}</td>
                <td style='padding:5px 8px;border:1px solid #ccc;font-weight:bold'>Section</td>
                <td style='padding:5px 8px;border:1px solid #ccc'>{$section}</td>
                <td style='padding:5px 8px;border:1px solid #ccc;font-weight:bold'>Gender</td>
                <td style='padding:5px 8px;border:1px solid #ccc'>{$gender}</td>
            </tr>
        </table>";
    }

    private function gradeTable(): string
    {
        return "
        <table style='width:100%;border-collapse:collapse;font-size:8pt'>
            <thead>
                <tr>
                    <th colspan='3' style='background:#1a3a5c;color:#fff;padding:4px;text-align:center'>Grading By Merit</th>
                </tr>
                <tr style='background:#e8edf5'>
                    <th style='padding:3px 6px;border:1px solid #ccc;text-align:center'>Marks %</th>
                    <th style='padding:3px 6px;border:1px solid #ccc;text-align:center'>Grade</th>
                    <th style='padding:3px 6px;border:1px solid #ccc;text-align:center'>Grade Point</th>
                </tr>
            </thead>
            <tbody>
                <tr><td style='padding:3px 6px;border:1px solid #ccc;text-align:center'>80–100</td><td style='padding:3px 6px;border:1px solid #ccc;text-align:center;font-weight:bold;color:#166534'>A+</td><td style='padding:3px 6px;border:1px solid #ccc;text-align:center'>5.00</td></tr>
                <tr style='background:#f9f9f9'><td style='padding:3px 6px;border:1px solid #ccc;text-align:center'>70–79</td><td style='padding:3px 6px;border:1px solid #ccc;text-align:center;font-weight:bold;color:#166534'>A</td><td style='padding:3px 6px;border:1px solid #ccc;text-align:center'>4.00</td></tr>
                <tr><td style='padding:3px 6px;border:1px solid #ccc;text-align:center'>60–69</td><td style='padding:3px 6px;border:1px solid #ccc;text-align:center;font-weight:bold;color:#1d4ed8'>A-</td><td style='padding:3px 6px;border:1px solid #ccc;text-align:center'>3.50</td></tr>
                <tr style='background:#f9f9f9'><td style='padding:3px 6px;border:1px solid #ccc;text-align:center'>50–59</td><td style='padding:3px 6px;border:1px solid #ccc;text-align:center;font-weight:bold;color:#1d4ed8'>B</td><td style='padding:3px 6px;border:1px solid #ccc;text-align:center'>3.00</td></tr>
                <tr><td style='padding:3px 6px;border:1px solid #ccc;text-align:center'>40–49</td><td style='padding:3px 6px;border:1px solid #ccc;text-align:center;font-weight:bold;color:#d97706'>C</td><td style='padding:3px 6px;border:1px solid #ccc;text-align:center'>2.00</td></tr>
                <tr style='background:#f9f9f9'><td style='padding:3px 6px;border:1px solid #ccc;text-align:center'>33–39</td><td style='padding:3px 6px;border:1px solid #ccc;text-align:center;font-weight:bold;color:#d97706'>D</td><td style='padding:3px 6px;border:1px solid #ccc;text-align:center'>1.00</td></tr>
                <tr><td style='padding:3px 6px;border:1px solid #ccc;text-align:center'>0–32</td><td style='padding:3px 6px;border:1px solid #ccc;text-align:center;font-weight:bold;color:#dc2626'>F</td><td style='padding:3px 6px;border:1px solid #ccc;text-align:center'>0.00</td></tr>
            </tbody>
        </table>";
    }

    private function signatureBlock(): string
    {
        return "
        <table style='width:100%;margin-top:30px;font-size:8pt'>
            <tr>
                <td style='width:33%;text-align:center;padding-top:4px'>
                    <div style='border-top:1px solid #333;padding-top:4px;margin:0 20px'>Class Teacher's Comment &amp; Signature</div>
                </td>
                <td style='width:33%;text-align:center;padding-top:4px'>
                    <div style='border-top:1px solid #333;padding-top:4px;margin:0 20px'>Principal's Signature</div>
                </td>
                <td style='width:33%;text-align:center;padding-top:4px'>
                    <div style='border-top:1px solid #333;padding-top:4px;margin:0 20px'>Guardian's Signature</div>
                </td>
            </tr>
        </table>
        <div style='text-align:center;margin-top:10px;font-size:7pt;color:#888'>
            Login: cpcs.artscolege.com
        </div>";
    }

    private function schoolLogoSvg(): string
    {
        return '<svg width="52" height="52" viewBox="0 0 52 52" xmlns="http://www.w3.org/2000/svg">
            <circle cx="26" cy="26" r="25" fill="#1a3a5c" stroke="#c9a227" stroke-width="2"/>
            <circle cx="26" cy="26" r="20" fill="none" stroke="#c9a227" stroke-width="1"/>
            <text x="26" y="21" font-family="Arial" font-size="7" font-weight="bold" fill="#ffffff" text-anchor="middle">CPSC</text>
            <text x="26" y="31" font-family="Arial" font-size="5" fill="#c9a227" text-anchor="middle">SAIDPUR</text>
            <text x="26" y="39" font-family="Arial" font-size="4.5" fill="#aaa" text-anchor="middle">EST. 1971</text>
        </svg>';
    }

    // ─── Utility helpers ──────────────────────────────────────────────────────

    private function gradeColor(?string $grade): string
    {
        return match ($grade) {
            'A+', 'A' => '#166534',
            'A-', 'B' => '#1d4ed8',
            'C', 'D'  => '#d97706',
            'F'       => '#dc2626',
            default   => '#111111',
        };
    }

    private function gpaColor(float $gpa): string
    {
        if ($gpa >= 4.5) return '#166534';
        if ($gpa >= 3.5) return '#1d4ed8';
        if ($gpa >= 2.5) return '#d97706';
        return '#dc2626';
    }

    private function fmt(?float $value): string
    {
        if ($value === null) {
            return '—';
        }
        return $value == (int) $value ? (string) (int) $value : number_format($value, 2);
    }

    private function wrapHtml(string $body): string
    {
        return "<!DOCTYPE html><html><head><meta charset='UTF-8'><style>
            body { font-family: Arial, sans-serif; font-size: 9pt; color: #111; margin: 0; padding: 0; }
            .marks-table { width: 100%; border-collapse: collapse; margin-bottom: 8px; font-size: 8pt; }
            .marks-table th {
                background: #1a3a5c;
                color: #fff;
                padding: 4px 3px;
                text-align: center;
                border: 1px solid #2c4e7c;
                font-size: 7.5pt;
                line-height: 1.2;
            }
            .marks-table td {
                padding: 3px 3px;
                text-align: center;
                border: 1px solid #d0d5e0;
                font-size: 8pt;
            }
            .subj-head  { text-align: left !important; padding-left: 6px !important; min-width: 90px; }
            .total-head { background: #2c3e6b !important; }
            .grade-head { background: #2c3e6b !important; }
            .high-head  { background: #374151 !important; }
            .tabulation th, .tabulation td { font-size: 7pt; padding: 2px 3px; }
        </style></head><body>{$body}</body></html>";
    }

    private function renderPdf(string $html, string $orientation = 'P'): string
    {
        $mpdf = new Mpdf([
            'orientation'       => $orientation,
            'margin_top'        => 8,
            'margin_right'      => 8,
            'margin_bottom'     => 8,
            'margin_left'       => 8,
            'default_font_size' => 9,
            'default_font'      => 'Arial',
        ]);

        $mpdf->WriteHTML($html);

        return $mpdf->Output('', 'S');
    }
}
```

- [ ] **Step 3: Add `subject` relation to `ComputedScore` model**

The service calls `$score->subject` — verify this relation exists:

```bash
grep -n "subject" /Users/hasan/Documents/school-management-system/pps/backend/app/Models/Pps/ComputedScore.php
```

If the relation is missing, add it to the model:

```php
public function subject(): BelongsTo
{
    return $this->belongsTo(\App\Models\Pps\Subject::class, 'subject_id');
}

public function student(): BelongsTo
{
    return $this->belongsTo(\App\Models\Student::class, 'student_id');
}
```

- [ ] **Step 4: Verify syntax**

```bash
cd /Users/hasan/Documents/school-management-system/pps/backend && php -l app/Services/Pps/ReportCardService.php
```

Expected: `No syntax errors detected`

---

## Task 6: Rewrite ParentViewController

**Files:**
- Modify: `app/Http/Controllers/Api/V1/Pps/ParentViewController.php`

Only `buildPpsResults` uses old models (`ResultSummary` with eager-loaded `exam`). Replace with `pps_computed_scores` grouped by exam, with `pps_exams` eager-loaded.

- [ ] **Step 1: Replace the `buildPpsResults` method and update imports**

Remove these imports:
```php
use App\Models\Pps\ExamDefinition;
use App\Models\Pps\ResultSummary;
```

Add:
```php
use App\Models\Pps\ComputedScore;
use App\Models\Pps\Exam;
```

Replace the `buildPpsResults` method:

```php
private function buildPpsResults(Student $student, ?int $requestedExamId): array
{
    // All exams this student has any computed score in
    $examIds = ComputedScore::query()
        ->where('student_id', $student->id)
        ->distinct()
        ->pluck('exam_id');

    if ($examIds->isEmpty()) {
        return ['available_exams' => [], 'selected' => null];
    }

    $exams = Exam::query()
        ->whereIn('id', $examIds)
        ->with('examType:id,name,code,is_terminal')
        ->orderByDesc('academic_year')
        ->orderByDesc('term')
        ->get(['id', 'exam_type_id', 'title', 'academic_year', 'term']);

    $selectedExamId = $requestedExamId && $examIds->contains($requestedExamId)
        ? $requestedExamId
        : $exams->first()?->id;

    $selectedData = null;
    if ($selectedExamId) {
        $scores = ComputedScore::query()
            ->where('exam_id', $selectedExamId)
            ->where('student_id', $student->id)
            ->get();

        $totalObtained = $scores->sum('total_obtained');
        $totalPossible = $scores->sum('total_possible');
        $avgGp         = round((float) $scores->avg('grade_point'), 2);
        $letterGrade   = $scores->sortByDesc('percentage')->first()?->letter_grade;

        // Rank
        $allTotals = ComputedScore::query()
            ->where('exam_id', $selectedExamId)
            ->groupBy('student_id')
            ->selectRaw('student_id, SUM(total_obtained) as grand_total')
            ->orderByDesc('grand_total')
            ->pluck('grand_total', 'student_id');

        $position = null;
        $rank = 1;
        foreach ($allTotals as $sid => $total) {
            if ($sid === $student->id) { $position = $rank; break; }
            $rank++;
        }

        $selectedData = [
            'exam_id'              => $selectedExamId,
            'exam_title'           => $exams->firstWhere('id', $selectedExamId)?->title,
            'gpa'                  => $avgGp,
            'letter_grade'         => $letterGrade,
            'total_marks_obtained' => round($totalObtained, 2),
            'total_marks_full'     => round($totalPossible, 2),
            'class_position'       => $position,
            'total_students'       => $allTotals->count(),
            'is_promoted'          => null,
            'computed_at'          => $scores->max('computed_at'),
        ];
    }

    return [
        'available_exams' => $exams->map(fn ($e) => [
            'exam_id'         => $e->id,
            'title'           => $e->title,
            'assessment_type' => $e->examType?->name,
            'computed_at'     => null,
        ])->all(),
        'selected' => $selectedData,
    ];
}
```

- [ ] **Step 2: Verify syntax**

```bash
cd /Users/hasan/Documents/school-management-system/pps/backend && php -l app/Http/Controllers/Api/V1/Pps/ParentViewController.php
```

Expected: `No syntax errors detected`

---

## Task 7: Rewrite DashboardController

**Files:**
- Modify: `app/Http/Controllers/Api/V1/Pps/DashboardController.php`

Two `Assessment` query blocks at lines ~121–152 (teacher highlights). Replace with `pps_computed_scores` grouped by teacher — but `pps_computed_scores` has no `teacher_id`. This data is no longer available in the new schema (marks do not carry `teacher_id` at the computed score level). The safe replacement is to return an empty array for `teacher_highlights`.

- [ ] **Step 1: Remove Assessment import and replace the two query blocks**

Remove:
```php
use App\Models\Pps\Assessment;
```

Replace the teacher highlights block (lines ~120–152 in the original, covering `$previousPeriod`, `$previousTeacherScores`, `$teacherHighlights`):

```php
// Teacher highlights removed: pps_computed_scores does not carry teacher_id.
$teacherHighlights = collect();
```

- [ ] **Step 2: Verify syntax**

```bash
cd /Users/hasan/Documents/school-management-system/pps/backend && php -l app/Http/Controllers/Api/V1/Pps/DashboardController.php
```

Expected: `No syntax errors detected`

---

## Task 8: Rewrite AdministrationController

**Files:**
- Modify: `app/Http/Controllers/Api/V1/Pps/AdministrationController.php`

This controller has extensive `ExamDefinition`/`ExamScope`/`TermMark`/`PretestMark`/`ResultSummary`/`Assessment` usage. Changes:

1. `overview()` — `'exams' => ExamDefinition::...` → `Exam::...with('components','classMaps')`
2. `destroyDepartment()` — `ExamDefinition::...where('department_id')` → `ExamClassMap::...` (no `department_id` on exam, skip this guard or use `pps_exam_class_map`)
3. `destroyClassSection()` — `ExamDefinition::...where('class_name')` → check `ExamClassMap`
4. `destroySubject()` — `ExamDefinition::...where('subject_id')` → `ExamClassMap`, `Assessment` check → `Mark` check
5. `storeExam/updateExam/destroyExam` — rewrite to use `Exam` + `ExamComponent` + `ExamClassMap`
6. `storeExamScope/destroyExamScope` — rewrite to use `ExamClassMap`
7. `destroyStudent()` — `TermMark`/`PretestMark`/`ResultSummary` → `Mark`/`ComputedScore`
8. `bulkMarks()` — rewrite to use new tables (or remove if superseded)
9. Exam count in `overview` summary → `Exam::count()`

This is the most extensive change. Work through each method:

- [ ] **Step 1: Update imports**

Remove all of these imports:
```php
use App\Models\Pps\Assessment;
use App\Models\Pps\ExamDefinition;
use App\Models\Pps\ExamScope;
use App\Models\Pps\PretestMark;
use App\Models\Pps\ResultSummary;
use App\Models\Pps\TermMark;
```

Add:
```php
use App\Models\Pps\ComputedScore;
use App\Models\Pps\Exam;
use App\Models\Pps\ExamClassMap;
use App\Models\Pps\ExamComponent;
use App\Models\Pps\Mark;
```

- [ ] **Step 2: Update `overview()` method**

Replace exam-related lines in `summary` and `exams` key:

```php
// In summary array:
'exams' => Exam::query()->count(),

// Replace the 'exams' list key:
'exams' => Exam::query()
    ->with('examType:id,name,code', 'components:id,exam_id,name,code', 'classMaps:id,exam_id,class_id,section_id,subject_id')
    ->orderByDesc('academic_year')
    ->orderByDesc('term')
    ->orderBy('title')
    ->get(),
```

- [ ] **Step 3: Update `destroyDepartment()` guard**

The old check `ExamDefinition::query()->where('department_id', $department->id)->exists()` has no equivalent in the new schema (exams don't have `department_id`). Remove that guard line entirely:

```php
// REMOVE: || ExamDefinition::query()->where('department_id', $department->id)->exists()
```

Keep only:
```php
if (
    $department->classSections()->exists()
    || $department->subjects()->exists()
) {
```

- [ ] **Step 4: Update `destroyClassSection()` guard**

Replace:
```php
|| ExamDefinition::query()->where('class_name', $classSection->class_name)->where('section', $classSection->section)->exists()
```
With:
```php
|| ExamClassMap::query()->where('class_id', $classSection->class_name)->where('section_id', $classSection->section)->exists()
```

- [ ] **Step 5: Update `destroySubject()` guard**

Replace:
```php
ExamDefinition::query()->where('subject_id', $subject->id)->exists()
|| TeacherAssignment::query()->where('subject', $subject->name)->exists()
|| Assessment::query()->where('subject', $subject->name)->exists()
```
With:
```php
ExamClassMap::query()->where('subject_id', $subject->id)->exists()
|| TeacherAssignment::query()->where('subject', $subject->name)->exists()
|| Mark::query()->where('subject_id', $subject->id)->exists()
```

- [ ] **Step 6: Rewrite `storeExam()`**

```php
public function storeExam(Request $request): JsonResponse
{
    $data = $request->validate($this->examRules());
    $components = $data['components'] ?? [];
    $classMaps  = $data['class_maps'] ?? [];
    unset($data['components'], $data['class_maps']);

    return DB::transaction(function () use ($data, $components, $classMaps): JsonResponse {
        $exam = Exam::query()->create($data);

        foreach ($components as $comp) {
            $exam->components()->create([
                'name'             => $comp['name'],
                'code'             => $comp['code'] ?? null,
                'max_raw_marks'    => $comp['max_raw_marks'] ?? 0,
                'max_contribution' => $comp['max_contribution'] ?? 0,
                'sort_order'       => $comp['sort_order'] ?? 0,
            ]);
        }

        foreach ($classMaps as $map) {
            $exam->classMaps()->create([
                'class_id'   => $map['class_id'],
                'section_id' => $map['section_id'] ?? null,
                'subject_id' => $map['subject_id'] ?? null,
            ]);
        }

        return response()->json([
            'exam' => $exam->load('examType:id,name,code', 'components', 'classMaps'),
        ], Response::HTTP_CREATED);
    });
}
```

- [ ] **Step 7: Rewrite `updateExam()`**

```php
public function updateExam(Request $request, Exam $exam): JsonResponse
{
    $data = $request->validate($this->examRules($exam));
    $components = $data['components'] ?? null;
    $classMaps  = $data['class_maps'] ?? null;
    unset($data['components'], $data['class_maps']);

    return DB::transaction(function () use ($data, $components, $classMaps, $exam): JsonResponse {
        $exam->update($data);

        if ($components !== null) {
            $exam->components()->delete();
            foreach ($components as $comp) {
                $exam->components()->create([
                    'name'             => $comp['name'],
                    'code'             => $comp['code'] ?? null,
                    'max_raw_marks'    => $comp['max_raw_marks'] ?? 0,
                    'max_contribution' => $comp['max_contribution'] ?? 0,
                    'sort_order'       => $comp['sort_order'] ?? 0,
                ]);
            }
        }

        if ($classMaps !== null) {
            $exam->classMaps()->delete();
            foreach ($classMaps as $map) {
                $exam->classMaps()->create([
                    'class_id'   => $map['class_id'],
                    'section_id' => $map['section_id'] ?? null,
                    'subject_id' => $map['subject_id'] ?? null,
                ]);
            }
        }

        return response()->json([
            'exam' => $exam->fresh()->load('examType:id,name,code', 'components', 'classMaps'),
        ]);
    });
}
```

- [ ] **Step 8: Rewrite `destroyExam()`**

```php
public function destroyExam(Exam $exam): JsonResponse
{
    if (
        Mark::query()
            ->join('pps_exam_components', 'pps_exam_components.id', '=', 'pps_marks.component_id')
            ->where('pps_exam_components.exam_id', $exam->id)
            ->exists()
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
```

- [ ] **Step 9: Rewrite `storeExamScope()` → now `storeExamClassMap()`**

Route binding uses `ExamDefinition $exam` — this needs to change to `Exam $exam`. Rewrite:

```php
public function storeExamScope(Request $request, Exam $exam): JsonResponse
{
    $data = $request->validate([
        'class_id'   => ['required', 'string', 'max:20'],
        'section_id' => ['nullable', 'string', 'max:10'],
        'subject_id' => ['nullable', 'exists:pps_subjects,id'],
    ]);

    $map = $exam->classMaps()->create($data);

    return response()->json(['class_map' => $map], Response::HTTP_CREATED);
}
```

- [ ] **Step 10: Rewrite `destroyExamScope()` → now uses `ExamClassMap`**

```php
public function destroyExamScope(Exam $exam, ExamClassMap $scope): JsonResponse
{
    if ($scope->exam_id !== $exam->id) {
        return response()->json(['message' => 'Class map does not belong to this exam.'], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    $scope->delete();

    return response()->json(['deleted' => true]);
}
```

- [ ] **Step 11: Update `destroyStudent()` guard**

Replace:
```php
TermMark::query()->where('student_id', $student->id)->exists()
|| PretestMark::query()->where('student_id', $student->id)->exists()
|| ResultSummary::query()->where('student_id', $student->id)->exists()
```
With:
```php
Mark::query()->where('student_id', $student->id)->exists()
|| ComputedScore::query()->where('student_id', $student->id)->exists()
```

- [ ] **Step 12: Rewrite `bulkMarks()`**

The old method inserted into `pps_term_marks`. Replace with `pps_marks` (component-based). However since the bulk CSV format references component codes (spot_test, class_test2, etc.), and components are now per-exam, the logic must look up the component by `exam_id` + `code`.

```php
public function bulkMarks(Request $request): JsonResponse
{
    $data = $request->validate([
        'rows'                   => ['required', 'array', 'min:1'],
        'rows.*.student_code'    => ['required', 'string'],
        'rows.*.exam_id'         => ['required', 'integer', 'exists:pps_exams,id'],
        'rows.*.subject'         => ['required', 'string'],
        'rows.*.component_code'  => ['required', 'string', 'max:40'],
        'rows.*.marks_obtained'  => ['nullable', 'numeric', 'min:0'],
    ]);

    $inserted  = 0;
    $updated   = 0;
    $errors    = [];
    $enteredBy = $request->user()?->id;
    $compCache = [];

    DB::transaction(function () use ($data, $enteredBy, &$inserted, &$updated, &$errors, &$compCache): void {
        foreach ($data['rows'] as $i => $row) {
            $line = $i + 2;

            $student = Student::query()
                ->where('student_code', trim($row['student_code']))
                ->first(['id', 'class_name', 'section']);

            if (! $student) {
                $errors[] = ['row' => $line, 'error' => "Student code \"{$row['student_code']}\" not found."];
                continue;
            }

            $examId = (int) $row['exam_id'];
            $cacheKey = "{$examId}_{$row['component_code']}";
            if (! isset($compCache[$cacheKey])) {
                $compCache[$cacheKey] = ExamComponent::query()
                    ->where('exam_id', $examId)
                    ->where('code', trim($row['component_code']))
                    ->first(['id']);
            }
            $component = $compCache[$cacheKey];

            if (! $component) {
                $errors[] = ['row' => $line, 'error' => "Component code \"{$row['component_code']}\" not found for exam {$examId}."];
                continue;
            }

            $subject = Subject::query()
                ->where('is_active', true)
                ->where(fn ($q) => $q->where('name', trim($row['subject']))->orWhere('code', trim($row['subject'])))
                ->first(['id']);

            if (! $subject) {
                $errors[] = ['row' => $line, 'error' => "Subject \"{$row['subject']}\" not found."];
                continue;
            }

            $existing = Mark::query()->where([
                'component_id' => $component->id,
                'student_id'   => $student->id,
                'subject_id'   => $subject->id,
            ])->first();

            $marksValue = isset($row['marks_obtained']) && $row['marks_obtained'] !== '' ? (float) $row['marks_obtained'] : null;

            if ($existing) {
                $existing->fill(['marks_obtained' => $marksValue, 'entered_by' => $enteredBy])->save();
                $updated++;
            } else {
                Mark::query()->create([
                    'component_id'   => $component->id,
                    'student_id'     => $student->id,
                    'subject_id'     => $subject->id,
                    'marks_obtained' => $marksValue,
                    'entered_by'     => $enteredBy,
                ]);
                $inserted++;
            }
        }
    });

    return response()->json([
        'imported' => $inserted + $updated,
        'created'  => $inserted,
        'updated'  => $updated,
        'failed'   => count($errors),
        'errors'   => $errors,
    ], Response::HTTP_CREATED);
}
```

- [ ] **Step 13: Update `examRules()` private method**

The old method validated against `pps_exam_definitions`. Replace:

```php
private function examRules(?Exam $exam = null): array
{
    return [
        'exam_type_id'                 => ['required', 'exists:pps_exam_types,id'],
        'title'                        => ['required', 'string', 'max:255'],
        'academic_year'                => ['required', 'integer', 'min:2000', 'max:2100'],
        'term'                         => ['nullable', 'integer', 'min:1', 'max:4'],
        'exam_date'                    => ['nullable', 'date'],
        'scope'                        => ['nullable', 'string', 'max:255'],
        'status'                       => ['sometimes', 'string', 'in:draft,active,closed'],
        'is_active'                    => ['sometimes', 'boolean'],
        'components'                   => ['sometimes', 'array'],
        'components.*.name'            => ['required', 'string', 'max:100'],
        'components.*.code'            => ['nullable', 'string', 'max:40'],
        'components.*.max_raw_marks'   => ['required', 'numeric', 'min:0'],
        'components.*.max_contribution' => ['required', 'numeric', 'min:0'],
        'components.*.sort_order'      => ['nullable', 'integer'],
        'class_maps'                   => ['sometimes', 'array'],
        'class_maps.*.class_id'        => ['required', 'string', 'max:20'],
        'class_maps.*.section_id'      => ['nullable', 'string', 'max:10'],
        'class_maps.*.subject_id'      => ['nullable', 'exists:pps_subjects,id'],
    ];
}
```

- [ ] **Step 14: Verify syntax**

```bash
cd /Users/hasan/Documents/school-management-system/pps/backend && php -l app/Http/Controllers/Api/V1/Pps/AdministrationController.php
```

Expected: `No syntax errors detected`

---

## Task 9: Delete dead PHP files

**Files to delete:**

- [ ] **Step 1: Delete dead controllers and commands**

```bash
rm /Users/hasan/Documents/school-management-system/pps/backend/app/Http/Controllers/Api/V1/Pps/TermMarksController.php
rm /Users/hasan/Documents/school-management-system/pps/backend/app/Http/Controllers/Api/V1/Pps/AssessmentMarksController.php
rm /Users/hasan/Documents/school-management-system/pps/backend/app/Console/Commands/CollapseExamDefinitions.php
rm /Users/hasan/Documents/school-management-system/pps/backend/app/Console/Commands/SeedAssessmentExamDefinitions.php
rm /Users/hasan/Documents/school-management-system/pps/backend/app/Console/Commands/MigrateAssessmentsToTermMarks.php
```

- [ ] **Step 2: Delete dead models**

```bash
rm /Users/hasan/Documents/school-management-system/pps/backend/app/Models/Pps/ExamDefinition.php
rm /Users/hasan/Documents/school-management-system/pps/backend/app/Models/Pps/ExamScope.php
rm /Users/hasan/Documents/school-management-system/pps/backend/app/Models/Pps/Assessment.php
rm /Users/hasan/Documents/school-management-system/pps/backend/app/Models/Pps/TermMark.php
rm /Users/hasan/Documents/school-management-system/pps/backend/app/Models/Pps/PretestMark.php
rm /Users/hasan/Documents/school-management-system/pps/backend/app/Models/Pps/ResultSummary.php
```

- [ ] **Step 3: Check for any remaining references to deleted models**

```bash
cd /Users/hasan/Documents/school-management-system/pps/backend && grep -rn "ExamDefinition\|ExamScope\|TermMark\|PretestMark\|ResultSummary\|pps_assessments\|pps_exam_definitions\|pps_exam_scopes\|pps_term_marks\|pps_pretest_marks\|pps_result_summary" app/ --include="*.php" | grep -v "vendor"
```

Expected: no output. If any references remain, fix them before proceeding.

---

## Task 10: Create drop migration and run migrate

**Files:**
- Create: `database/migrations/2026_06_09_000001_drop_old_marks_tables.php`

Drop order must respect FK constraints:
1. `pps_assessments` — no FK deps from live tables
2. `pps_pretest_marks` — FK → `pps_exam_definitions`
3. `pps_result_summary` — FK → `pps_exam_definitions`
4. `pps_term_marks` — FK → `pps_exam_definitions`
5. `pps_exam_scopes` — FK → `pps_exam_definitions`
6. `pps_exam_definitions` — can drop last

- [ ] **Step 1: Create the migration file**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('pps_assessments');
        Schema::dropIfExists('pps_pretest_marks');
        Schema::dropIfExists('pps_result_summary');
        Schema::dropIfExists('pps_term_marks');
        Schema::dropIfExists('pps_exam_scopes');
        Schema::dropIfExists('pps_exam_definitions');
    }

    public function down(): void
    {
        // These tables are intentionally not restored.
        // To roll back, restore from a database backup.
    }
};
```

- [ ] **Step 2: Run the migration**

```bash
cd /Users/hasan/Documents/school-management-system/pps/backend && php artisan migrate
```

Expected output includes: `Migrating: 2026_06_09_000001_drop_old_marks_tables` followed by `Migrated`.

- [ ] **Step 3: Final smoke test — route list**

```bash
cd /Users/hasan/Documents/school-management-system/pps/backend && php artisan route:list --path=pps 2>&1 | head -40
```

Expected: routes list prints cleanly with no PHP errors or class-not-found warnings.

---

## Spec Coverage Check

| Requirement | Task |
|---|---|
| ExamListController queries pps_exams | Task 1 |
| ResultSummaryController rewrite | Task 2 |
| ScoreCalculatorService calcAcademic + buildDetailData | Task 3 |
| TrendAnalyzerService PostgreSQL strftime fix | Task 4 |
| ReportCardService full rewrite | Task 5 |
| ParentViewController buildPpsResults | Task 6 |
| DashboardController Assessment removal | Task 7 |
| AdministrationController full update | Task 8 |
| Delete dead PHP files | Task 9 |
| Drop old tables migration | Task 10 |
| php artisan migrate succeeds | Task 10 step 2 |
| No PHP syntax errors | Verified at each task |

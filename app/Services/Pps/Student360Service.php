<?php

namespace App\Services\Pps;

use App\Models\Pps\BehaviorCard;
use App\Models\Pps\ClassroomRating;
use App\Models\Pps\CounselingSession;
use App\Models\Pps\Extracurricular;
use App\Models\Pps\PerformanceSnapshot;
use App\Models\Pps\PpsAlert;
use App\Models\Pps\PpsNotificationLog;
use App\Models\Pps\PrivateTuition;
use App\Models\Pps\SchoolPpsConfig;
use App\Models\Pps\TeacherAssignment;
use App\Models\Pps\WelfareIntervention;
use App\Support\StudentTaxonomyFilter;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use SmsCore\Models\Student;
use SmsCore\Models\User;

/**
 * Everything the "Student 360" page shows, in one payload.
 *
 * Deliberately separate from StudentPerformanceController::show so the
 * existing profile page keeps its contract while the principal compares the
 * two views.
 */
class Student360Service
{
    public const NOTIFICATION_TYPE = 'student_360_teacher_alert';

    public function __construct(
        private readonly StudentInsightService $insights,
        private readonly ForecastService $forecast,
    ) {
    }

    // ── Marks grid ─────────────────────────────────────────────────────────

    /**
     * Rows = subjects, columns = exams (oldest left), limited to the last
     * $years distinct academic years the student has marks in.
     *
     * @return array{available_years: int[], columns: array<int, array>, rows: array<int, array>}
     */
    public function buildMarksGrid(Student $student, int $years): array
    {
        $marks = DB::table('pps_marks as m')
            ->join('pps_exam_components as c', 'c.id', '=', 'm.component_id')
            ->join('pps_exams as e', 'e.id', '=', 'c.exam_id')
            ->join('pps_exam_types as t', 't.id', '=', 'e.exam_type_id')
            ->join('subjects as s', 's.id', '=', 'm.subject_id')
            ->where('m.student_id', $student->id)
            ->groupBy('e.id', 'e.title', 'e.academic_year', 'e.term', 'e.exam_date', 't.name', 't.code', 's.id', 's.full_name')
            ->selectRaw('e.id as exam_id, e.title, e.academic_year, e.term, e.exam_date, t.name as type_name, t.code as type_code, s.id as subject_id, s.full_name as subject, SUM(m.marks_obtained) as obtained, SUM(c.max_raw_marks) as total')
            ->get();

        $availableYears = $marks->pluck('academic_year')->map(fn ($y) => (int) $y)->unique()->sortDesc()->values();
        $selectedYears = $availableYears->take(max(1, $years))->all();
        $marks = $marks->filter(fn ($row) => in_array((int) $row->academic_year, $selectedYears, true));

        $columns = $marks
            ->unique('exam_id')
            ->sortBy(fn ($row) => sprintf('%04d-%02d-%s', $row->academic_year, $row->term, $row->exam_date))
            ->values()
            ->map(fn ($row) => [
                'exam_id' => (int) $row->exam_id,
                'title' => $row->title,
                'academic_year' => (int) $row->academic_year,
                'term' => (int) $row->term,
                'exam_date' => $row->exam_date,
                'type_name' => $row->type_name,
                'type_code' => $row->type_code,
            ])
            ->all();

        $examIds = array_column($columns, 'exam_id');
        $subjectIds = $marks->pluck('subject_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        $peerStats = $this->peerStats($examIds, $subjectIds);

        $rows = [];
        foreach ($marks->groupBy('subject_id') as $subjectId => $subjectMarks) {
            $cells = [];
            foreach ($columns as $column) {
                $mark = $subjectMarks->firstWhere('exam_id', $column['exam_id']);
                if ($mark === null || (float) $mark->total <= 0) {
                    continue;
                }
                $pct = round((float) $mark->obtained / (float) $mark->total * 100, 1);
                $peers = $peerStats[$column['exam_id'].':'.$subjectId] ?? [];
                $cells[(string) $column['exam_id']] = [
                    'pct' => $pct,
                    'obtained' => round((float) $mark->obtained, 1),
                    'total' => round((float) $mark->total, 1),
                    'grade' => $this->grade($pct),
                    'class_avg' => $peers === [] ? null : round(array_sum($peers) / count($peers), 1),
                    'rank' => $peers === [] ? null : 1 + count(array_filter($peers, fn (float $p) => $p > $pct)),
                    'cohort' => count($peers),
                ];
            }

            $ordered = array_values($cells);
            $latest = $ordered === [] ? null : end($ordered);
            $previous = count($ordered) >= 2 ? $ordered[count($ordered) - 2] : null;
            $delta = ($latest && $previous) ? round($latest['pct'] - $previous['pct'], 1) : null;

            $rows[] = [
                'subject_id' => (int) $subjectId,
                'subject' => $subjectMarks->first()->subject,
                'cells' => $cells,
                'latest_pct' => $latest['pct'] ?? null,
                'latest_class_avg' => $latest['class_avg'] ?? null,
                'gap' => ($latest && $latest['class_avg'] !== null) ? round($latest['pct'] - $latest['class_avg'], 1) : null,
                'delta' => $delta,
                'trend' => $delta === null ? 'flat' : ($delta <= -3 ? 'down' : ($delta >= 3 ? 'up' : 'flat')),
                'tuition' => null,
                'tuition_flag' => false,
            ];
        }

        // Weakest first: biggest negative gap to class average on top.
        usort($rows, fn (array $a, array $b) => ($a['gap'] ?? 0) <=> ($b['gap'] ?? 0));

        return [
            'available_years' => $availableYears->all(),
            'columns' => $columns,
            'rows' => $rows,
        ];
    }

    /**
     * Per (exam, subject): every student's percentage, for class average and rank.
     *
     * @return array<string, float[]>
     */
    private function peerStats(array $examIds, array $subjectIds): array
    {
        if ($examIds === [] || $subjectIds === []) {
            return [];
        }

        $rows = DB::table('pps_marks as m')
            ->join('pps_exam_components as c', 'c.id', '=', 'm.component_id')
            ->whereIn('c.exam_id', $examIds)
            ->whereIn('m.subject_id', $subjectIds)
            ->groupBy('c.exam_id', 'm.subject_id', 'm.student_id')
            ->selectRaw('c.exam_id, m.subject_id, SUM(m.marks_obtained) / NULLIF(SUM(c.max_raw_marks), 0) * 100 as pct')
            ->get();

        $stats = [];
        foreach ($rows as $row) {
            if ($row->pct === null) {
                continue;
            }
            $stats[$row->exam_id.':'.$row->subject_id][] = round((float) $row->pct, 1);
        }

        return $stats;
    }

    private function grade(float $pct): string
    {
        return match (true) {
            $pct >= 80 => 'A+',
            $pct >= 70 => 'A',
            $pct >= 60 => 'A-',
            $pct >= 50 => 'B',
            $pct >= 40 => 'C',
            $pct >= 33 => 'D',
            default => 'F',
        };
    }
}

<?php

namespace App\Console\Commands;

use App\Services\Pps\ConMarksService;
use App\Services\Pps\GradeCalculatorService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MigrateAssessmentsToTermMarks extends Command
{
    protected $signature   = 'pps:migrate-assessments {--dry-run : Preview without writing}';
    protected $description = 'Migrate pps_assessments data into pps_term_marks';

    public function __construct(
        private readonly ConMarksService $con,
        private readonly GradeCalculatorService $grader,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        // Build subject name → id map
        $subjectMap = DB::table('pps_subjects')->pluck('id', 'name');

        // Each exam scope = one exam+class+subject combination
        $scopes = DB::table('pps_exam_scopes as es')
            ->join('pps_exam_definitions as ed', 'ed.id', '=', 'es.exam_id')
            ->select('es.exam_id', 'es.class_name', 'es.section', 'es.subject_id', 'ed.term')
            ->get();

        $inserted = 0;
        $skipped  = 0;

        foreach ($scopes as $scope) {
            $subjectName = $subjectMap->flip()[$scope->subject_id] ?? null;
            if (!$subjectName) {
                continue;
            }

            // Students in this class+section
            $studentQuery = DB::table('students')->where('class_name', $scope->class_name);
            if ($scope->section !== null) {
                $studentQuery->where('section', $scope->section);
            }
            $students = $studentQuery->pluck('id');

            foreach ($students as $studentId) {
                // Already migrated?
                $exists = DB::table('pps_term_marks')
                    ->where('exam_id', $scope->exam_id)
                    ->where('student_id', $studentId)
                    ->where('subject_id', $scope->subject_id)
                    ->exists();

                if ($exists) {
                    $skipped++;
                    continue;
                }

                // Pull assessments for this student+subject+term
                $assessments = DB::table('pps_assessments')
                    ->where('student_id', $studentId)
                    ->where('subject', $subjectName)
                    ->where('term', $scope->term)
                    ->get()
                    ->groupBy('assessment_type');

                // term_marks: midterm → direct (max 100)
                $termMarks = $this->avgObtained($assessments->get('midterm'));

                // spot_test: avg raw marks, scale from /30 to /10
                $spotRaw  = $this->avgObtained($assessments->get('spot_test'));
                $spotTest = $spotRaw !== null ? round($spotRaw / 30 * 10, 2) : null;

                // class_test2: scale from /50 to /20
                $ctRaw      = $this->avgObtained($assessments->get('class_test'));
                $classTest2 = $ctRaw !== null ? round($ctRaw / 50 * 20, 2) : null;

                // vt: assessment_test scale from /75 to /25
                $atRaw = $this->avgObtained($assessments->get('assessment_test'));
                $vt    = $atRaw !== null ? round($atRaw / 75 * 25, 2) : null;

                // Skip entirely if no data at all
                if ($termMarks === null && $spotTest === null && $classTest2 === null && $vt === null) {
                    $skipped++;
                    continue;
                }

                $isSecondTerm = str_contains((string) $scope->term, 'T2');

                $raw = [
                    'spot_test'   => $spotTest,
                    'class_test2' => $classTest2,
                    'attendance'  => null,
                    'term_marks'  => $termMarks,
                    'vt'          => $isSecondTerm ? $vt : null,
                ];

                $computed = $this->con->computeTermCon($raw, $isSecondTerm);
                $grade    = ['letter_grade' => null, 'grade_point' => null];
                if ($computed['total_obtained'] !== null) {
                    $grade = $this->grader->resolve($computed['total_obtained']);
                }

                if (!$dryRun) {
                    DB::table('pps_term_marks')->insert(array_merge($raw, $computed, $grade, [
                        'exam_id'    => $scope->exam_id,
                        'student_id' => $studentId,
                        'subject_id' => $scope->subject_id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]));
                }

                $inserted++;
            }
        }

        // Update highest_marks per exam+subject after all inserts
        if (!$dryRun && $inserted > 0) {
            $pairs = DB::table('pps_term_marks')
                ->select('exam_id', 'subject_id')
                ->distinct()
                ->get();

            foreach ($pairs as $pair) {
                $max = DB::table('pps_term_marks')
                    ->where('exam_id', $pair->exam_id)
                    ->where('subject_id', $pair->subject_id)
                    ->max('total_obtained');

                if ($max !== null) {
                    DB::table('pps_term_marks')
                        ->where('exam_id', $pair->exam_id)
                        ->where('subject_id', $pair->subject_id)
                        ->update(['highest_marks' => $max]);
                }
            }
        }

        $this->info(($dryRun ? '[DRY RUN] ' : '') . "Inserted: {$inserted}, Skipped: {$skipped}");

        return self::SUCCESS;
    }

    private function avgObtained($rows): ?float
    {
        if (!$rows || $rows->isEmpty()) {
            return null;
        }

        return round($rows->avg('marks_obtained'), 2);
    }
}

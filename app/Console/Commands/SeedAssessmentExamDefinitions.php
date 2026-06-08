<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SeedAssessmentExamDefinitions extends Command
{
    protected $signature   = 'pps:seed-assessment-exams {--dry-run}';
    protected $description = 'Create exam_definitions + scopes for all assessment types found in pps_assessments';

    // Maps assessment_type → human title prefix + total_marks
    private const TYPE_META = [
        'quiz'            => ['label' => 'Quiz',            'total' => 20],
        'spot_test'       => ['label' => 'Spot Test',       'total' => 30],
        'class_test'      => ['label' => 'Class Test',      'total' => 50],
        'assessment_test' => ['label' => 'Assessment Test', 'total' => 75],
        'midterm'         => ['label' => 'Mid Term',        'total' => 100],
        'final'           => ['label' => 'Final Exam',      'total' => 100],
    ];

    private const TERM_LABEL = [
        '2026-T1' => '1st Term',
        '2026-T2' => '2nd Term',
    ];

    public function handle(): int
    {
        $dry = $this->option('dry-run');

        // Distinct type+term combos from pps_assessments
        $combos = DB::table('pps_assessments')
            ->select('assessment_type', 'term')
            ->distinct()
            ->get();

        // What already exists — normalize mid_term↔midterm
        $existing = DB::table('pps_exam_definitions')
            ->get(['id', 'assessment_type', 'term'])
            ->flatMap(fn ($r) => [
                $r->assessment_type . ':' . $r->term,
                str_replace('mid_term', 'midterm', $r->assessment_type) . ':' . $r->term,
                str_replace('midterm', 'mid_term', $r->assessment_type) . ':' . $r->term,
            ])
            ->toArray();

        // Subject name → id map
        $subjects = DB::table('pps_subjects')
            ->where('is_active', true)
            ->pluck('id', 'name')
            ->toArray();

        // Distinct classes
        $classes = DB::table('students')
            ->select('class_name')
            ->distinct()
            ->pluck('class_name')
            ->filter()
            ->toArray();

        $created = 0;

        foreach ($combos as $combo) {
            $key = $combo->assessment_type . ':' . $combo->term;

            if (in_array($key, $existing, true)) {
                $this->line("  SKIP (exists): {$key}");
                continue;
            }

            $meta       = self::TYPE_META[$combo->assessment_type] ?? ['label' => ucfirst($combo->assessment_type), 'total' => 100];
            $termLabel  = self::TERM_LABEL[$combo->term] ?? $combo->term;
            $title      = "{$termLabel} {$meta['label']} 2026";
            $totalMarks = $meta['total'];

            $this->info("  CREATE: \"{$title}\" ({$combo->assessment_type}, {$combo->term})");

            $created++;
            if ($dry) continue;

            $examId = DB::table('pps_exam_definitions')->insertGetId([
                'title'           => $title,
                'assessment_type' => $combo->assessment_type,
                'term'            => $combo->term,
                'total_marks'     => $totalMarks,
                'is_active'       => true,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);

            // Create 25 scopes (5 classes × 5 subjects)
            $scopeRows = [];
            foreach ($classes as $className) {
                foreach ($subjects as $subjectName => $subjectId) {
                    $scopeRows[] = [
                        'exam_id'    => $examId,
                        'class_name' => $className,
                        'section'    => null,
                        'subject_id' => $subjectId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }
            DB::table('pps_exam_scopes')->insert($scopeRows);
            $this->line("    → id={$examId}, " . count($scopeRows) . " scopes");
            $created++;
        }

        $this->info($dry ? "Dry run. Would create: {$created}" : "Done. Created: {$created} exam definitions.");
        return 0;
    }
}

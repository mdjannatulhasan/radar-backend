<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CollapseExamDefinitions extends Command
{
    protected $signature   = 'pps:collapse-exam-definitions {--dry-run : Preview changes without writing}';
    protected $description = 'Collapse 25 per-subject exam definitions into 1 reusable exam definition';

    public function handle(): int
    {
        $dry = $this->option('dry-run');

        $existing = DB::table('pps_exam_definitions')->count();
        $this->info("Current exam_definitions: {$existing}");

        if ($existing === 1) {
            $this->info('Already collapsed. Nothing to do.');
            return 0;
        }

        $first = DB::table('pps_exam_definitions')->orderBy('id')->first();
        $newTitle          = '1st Term Mid Term 2026';
        $newAssessmentType = $first->assessment_type ?? 'mid_term';
        $newTerm           = $first->term ?? '2026-T1';

        $this->info("Will create: \"{$newTitle}\" ({$newAssessmentType}, {$newTerm})");

        $oldIds = DB::table('pps_exam_definitions')->pluck('id')->toArray();
        $scopeCount = DB::table('pps_exam_scopes')->whereIn('exam_id', $oldIds)->count();
        $marksCount = DB::table('pps_term_marks')->whereIn('exam_id', $oldIds)->count();

        $this->info("Scopes to rekey: {$scopeCount}");
        $this->info("Term marks to rekey: {$marksCount}");

        if ($dry) {
            $this->warn('Dry run — no changes written.');
            return 0;
        }

        DB::transaction(function () use ($oldIds, $newTitle, $newAssessmentType, $newTerm) {
            $newId = DB::table('pps_exam_definitions')->insertGetId([
                'title'           => $newTitle,
                'assessment_type' => $newAssessmentType,
                'term'            => $newTerm,
                'is_active'       => true,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);

            $this->info("Inserted new exam_definition id={$newId}");

            DB::table('pps_exam_scopes')
                ->whereIn('exam_id', $oldIds)
                ->update(['exam_id' => $newId, 'updated_at' => now()]);

            DB::table('pps_term_marks')
                ->whereIn('exam_id', $oldIds)
                ->update(['exam_id' => $newId, 'updated_at' => now()]);

            DB::table('pps_exam_definitions')
                ->whereIn('id', $oldIds)
                ->delete();

            $this->info("Deleted {count} old definitions. Done.");
        });

        $remaining = DB::table('pps_exam_definitions')->count();
        $this->info("exam_definitions remaining: {$remaining}");

        return 0;
    }
}

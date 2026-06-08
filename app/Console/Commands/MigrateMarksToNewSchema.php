<?php
namespace App\Console\Commands;

use App\Models\Pps\Exam;
use App\Models\Pps\ExamComponent;
use App\Models\Pps\ExamType;
use App\Models\Pps\Mark;
use App\Services\Pps\ComputedScoreService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MigrateMarksToNewSchema extends Command
{
    protected $signature   = 'pps:migrate-marks-schema';
    protected $description = 'Seed exam types and migrate pps_assessments + pps_term_marks into new schema';

    public function __construct(private readonly ComputedScoreService $scorer) {
        parent::__construct();
    }

    public function handle(): int
    {
        DB::transaction(function () {
            $this->seedExamTypes();
            $this->migrateAssessments();
            $this->migrateTermMarks();
        });

        $this->info('Computing scores...');
        $this->computeAllScores();

        $this->info('Done.');
        return 0;
    }

    private function seedExamTypes(): void
    {
        $types = [
            ['name' => 'Quiz',            'code' => 'quiz',            'is_terminal' => false, 'is_system' => true],
            ['name' => 'Spot Test',       'code' => 'spot_test',       'is_terminal' => false, 'is_system' => true],
            ['name' => 'Class Test',      'code' => 'class_test',      'is_terminal' => false, 'is_system' => true],
            ['name' => 'Assessment Test', 'code' => 'assessment_test', 'is_terminal' => false, 'is_system' => true],
            ['name' => 'Mid-Term',        'code' => 'mid_term',        'is_terminal' => true,  'is_system' => true],
            ['name' => 'Final',           'code' => 'final',           'is_terminal' => true,  'is_system' => true],
        ];

        foreach ($types as $t) {
            ExamType::firstOrCreate(['code' => $t['code']], array_merge($t, ['is_active' => true]));
        }
        $this->info('Exam types seeded.');
    }

    private function migrateAssessments(): void
    {
        $typeMap = ExamType::pluck('id', 'code');

        $typeCodeMap = [
            'quiz'            => 'quiz',
            'spot_test'       => 'spot_test',
            'class_test'      => 'class_test',
            'assessment_test' => 'assessment_test',
            'final'           => 'final',
        ];

        $rows = DB::table('pps_assessments')
            ->whereIn('assessment_type', array_keys($typeCodeMap))
            ->get();

        $examCache = [];

        foreach ($rows as $row) {
            $typeCode = $typeCodeMap[$row->assessment_type] ?? null;
            if (!$typeCode) continue;

            $examKey = $typeCode . '|' . $row->term . '|' . ($row->exam_date ?? '');

            if (!isset($examCache[$examKey])) {
                [$year, $termNum] = $this->parseTerm($row->term);
                $exam = Exam::firstOrCreate(
                    ['title' => $this->examTitle($typeCode, $termNum, $year)],
                    [
                        'exam_type_id'  => $typeMap[$typeCode],
                        'academic_year' => $year,
                        'term'          => $termNum,
                        'exam_date'     => $row->exam_date,
                        'scope'         => 'global',
                        'status'        => 'closed',
                    ]
                );

                $component = ExamComponent::firstOrCreate(
                    ['exam_id' => $exam->id, 'code' => 'main_paper'],
                    [
                        'name'             => 'Main Paper',
                        'max_raw_marks'    => $row->total_marks,
                        'max_contribution' => $row->total_marks,
                        'sort_order'       => 1,
                    ]
                );

                $examCache[$examKey] = ['exam' => $exam, 'component' => $component];
            }

            ['exam' => $exam, 'component' => $component] = $examCache[$examKey];

            $subjectId = DB::table('pps_subjects')->where('name', $row->subject)->value('id');
            if (!$subjectId) continue;

            Mark::updateOrCreate(
                ['component_id' => $component->id, 'student_id' => $row->student_id, 'subject_id' => $subjectId],
                ['marks_obtained' => $row->marks_obtained, 'entered_by' => $row->teacher_id, 'recorded_at' => $row->created_at]
            );
        }

        $this->info('pps_assessments migrated.');
    }

    private function migrateTermMarks(): void
    {
        $typeMap = ExamType::pluck('id', 'code');

        $midTermExam = Exam::firstOrCreate(
            ['title' => '1st Term Mid-Term 2026'],
            [
                'exam_type_id'  => $typeMap['mid_term'],
                'academic_year' => 2026,
                'term'          => 1,
                'scope'         => 'global',
                'status'        => 'closed',
            ]
        );

        $componentDefs = [
            ['code' => 'spot_test',  'name' => 'Spot Test',  'max_raw' => 10,  'max_con' => 5,  'order' => 1],
            ['code' => 'class_test', 'name' => 'Class Test', 'max_raw' => 20,  'max_con' => 5,  'order' => 2],
            ['code' => 'attendance', 'name' => 'Attendance',  'max_raw' => 5,   'max_con' => 5,  'order' => 3],
            ['code' => 'main_paper', 'name' => 'Term Paper',  'max_raw' => 100, 'max_con' => 85, 'order' => 4],
        ];

        $components = [];
        foreach ($componentDefs as $def) {
            $components[$def['code']] = ExamComponent::firstOrCreate(
                ['exam_id' => $midTermExam->id, 'code' => $def['code']],
                ['name' => $def['name'], 'max_raw_marks' => $def['max_raw'], 'max_contribution' => $def['max_con'], 'sort_order' => $def['order']]
            );
        }

        $rows = DB::table('pps_term_marks')
            ->where('exam_id', 26)
            ->get();

        foreach ($rows as $row) {
            $map = [
                'spot_test'  => $row->spot_test,
                'class_test' => $row->class_test2,
                'attendance' => $row->attendance,
                'main_paper' => $row->term_marks,
            ];

            foreach ($map as $code => $value) {
                if ($value === null) continue;

                Mark::updateOrCreate(
                    ['component_id' => $components[$code]->id, 'student_id' => $row->student_id, 'subject_id' => $row->subject_id],
                    ['marks_obtained' => $value, 'entered_by' => $row->entered_by, 'recorded_at' => $row->created_at]
                );
            }
        }

        $this->info('pps_term_marks migrated.');
    }

    private function computeAllScores(): void
    {
        $combos = DB::table('pps_marks')
            ->join('pps_exam_components', 'pps_exam_components.id', '=', 'pps_marks.component_id')
            ->select('pps_exam_components.exam_id', 'pps_marks.student_id', 'pps_marks.subject_id')
            ->distinct()
            ->get();

        $bar = $this->output->createProgressBar($combos->count());
        foreach ($combos as $combo) {
            $this->scorer->recompute($combo->exam_id, $combo->student_id, $combo->subject_id);
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();
    }

    private function parseTerm(string $term): array
    {
        preg_match('/(\d{4})-T(\d)/', $term, $m);
        return [(int) ($m[1] ?? 2026), (int) ($m[2] ?? 1)];
    }

    private function examTitle(string $code, int $term, int $year): string
    {
        $ordinal = $term === 1 ? '1st' : '2nd';
        $names = [
            'quiz'            => 'Quiz',
            'spot_test'       => 'Spot Test',
            'class_test'      => 'Class Test',
            'assessment_test' => 'Assessment Test',
            'final'           => 'Final Exam',
        ];
        return "{$ordinal} Term {$names[$code]} {$year}";
    }
}

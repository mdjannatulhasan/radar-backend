<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Pps\PerformanceSnapshot;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use SmsCore\Models\ClassLevel;
use SmsCore\Models\Section;

/**
 * DEMO ONLY. Two prior academic years of terminal exam marks so the
 * Student 360 multi-year grid has something to show. A student in Class 12
 * gets Class 11 marks for last year and Class 10 marks for the year before.
 */
class PpsHistoryDemoSeeder extends Seeder
{
    private const YEARS_BACK = 2;

    public function run(): void
    {
        $currentYear = (int) (is_numeric(PpsAdministrationSeeder::academicYear()->name)
            ? PpsAdministrationSeeder::academicYear()->name
            : now()->year);
        $superadmin = PpsAdministrationSeeder::demoUser('superadmin', 'RADAR Super Admin');
        $firstTerm = PpsAdministrationSeeder::examType('first_term', '1st Term', true);
        $secondTerm = PpsAdministrationSeeder::examType('second_term', '2nd Term', true);

        $latestPeriod = PerformanceSnapshot::max('snapshot_period');
        $falling = $latestPeriod === null ? [] : PerformanceSnapshot::query()
            ->forPeriod($latestPeriod)
            ->whereIn('alert_level', ['urgent', 'warning'])
            ->pluck('student_id')
            ->flip()
            ->all();

        $inserted = 0;

        foreach (PpsAdministrationSeeder::demoSections() as $section) {
            /** @var Section $section */
            $classLevel = $section->classLevel;
            if ($classLevel === null) {
                continue;
            }

            $studentIds = DB::table('student_enrollments as se')
                ->join('academic_years as ay', 'ay.id', '=', 'se.academic_year_id')
                ->where('ay.is_current', true)
                ->where('se.section_id', $section->id)
                ->pluck('se.student_id')
                ->all();
            if ($studentIds === []) {
                continue;
            }

            for ($k = 1; $k <= self::YEARS_BACK; $k++) {
                // Not filtered by level_id: Class 11/12 sit in the College level
                // while Class 10 sits in School, and a Class 12 student's year
                // before last was Class 10. Version + numeric_order (+ group) is
                // enough to identify the class a student came from.
                $priorLevel = ClassLevel::query()
                    ->where('version_id', $classLevel->version_id)
                    ->where('numeric_order', $classLevel->numeric_order - $k)
                    ->when($classLevel->group, fn ($q, $g) => $q->where(fn ($qq) => $qq->where('group', $g)->orWhereNull('group')))
                    ->orderByRaw('CASE WHEN "group" IS NULL THEN 1 ELSE 0 END')
                    ->first();
                if ($priorLevel === null) {
                    continue;
                }

                $subjects = PpsAdministrationSeeder::examinableSubjects($priorLevel);
                if ($subjects->isEmpty()) {
                    continue;
                }

                $year = $currentYear - $k;
                $label = PpsAdministrationSeeder::classLabel($priorLevel);
                $exams = [
                    PpsAdministrationSeeder::examFor($firstTerm, $priorLevel, "{$label} — 1st Term {$year}", 1, "{$year}-06-15", $subjects, $superadmin->id, $year),
                    PpsAdministrationSeeder::examFor($secondTerm, $priorLevel, "{$label} — 2nd Term {$year}", 2, "{$year}-11-20", $subjects, $superadmin->id, $year),
                ];

                foreach ($exams as $termIndex => $exam) {
                    $components = DB::table('pps_exam_components')->where('exam_id', $exam->id)->get(['id', 'max_raw_marks']);
                    $existing = DB::table('pps_marks')
                        ->whereIn('component_id', $components->pluck('id'))
                        ->whereIn('student_id', $studentIds)
                        ->select('component_id', 'student_id', 'subject_id')
                        ->get()
                        ->map(fn ($r) => "{$r->component_id}:{$r->student_id}:{$r->subject_id}")
                        ->flip()
                        ->all();

                    $rows = [];
                    foreach ($studentIds as $studentId) {
                        // Falling students were better in earlier years; others hover around 60-75.
                        $base = isset($falling[$studentId]) ? 72 + $k * 6 - $termIndex * 3 : 66 - $termIndex * 1;
                        foreach ($subjects as $subject) {
                            $noise = (($studentId * 7 + $subject->id * 13 + $year) % 21) - 10;
                            $pct = max(25, min(98, $base + $noise));
                            foreach ($components as $component) {
                                $key = "{$component->id}:{$studentId}:{$subject->id}";
                                if (isset($existing[$key])) {
                                    continue;
                                }
                                $rows[] = [
                                    'component_id' => $component->id,
                                    'student_id' => $studentId,
                                    'subject_id' => $subject->id,
                                    'marks_obtained' => round($pct / 100 * (float) $component->max_raw_marks, 2),
                                    'entered_by' => $superadmin->id,
                                    'recorded_at' => $exam->exam_date,
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ];
                            }
                        }
                    }
                    foreach (array_chunk($rows, 1000) as $chunk) {
                        DB::table('pps_marks')->insert($chunk);
                        $inserted += count($chunk);
                    }
                }
            }
        }

        $this->command?->info("PpsHistoryDemoSeeder: inserted {$inserted} historical marks.");
    }
}

<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use SmsCore\Models\ClassLevel;
use SmsCore\Models\Section;

/**
 * Terminal exams for every class the demo cohort occupies.
 *
 * Was: a fixed catalogue of exams for hardcoded classes 4-10 plus a class 12
 * pre-test, over an invented subject list ("BAN", "ENG", "PHY"…). Both the
 * classes and the subjects are real now — Cantonment Public School & College
 * runs 32 class_levels across two levels and two versions, with its own 137
 * subjects — so this seeder reads the structure and builds exams to fit it
 * rather than asserting one.
 *
 * Each exam is broken into three weighted components (Written 70 / MCQ 20 /
 * Continuous Assessment 10) instead of a single 100-mark total, which is what
 * gives the marks grid more than one column, and there are two terms per class
 * so results and performance snapshots have a previous value to trend against.
 */
class PpsExamSeeder extends Seeder
{
    public function run(): void
    {
        $year = (int) (is_numeric(PpsAdministrationSeeder::academicYear()->name)
            ? PpsAdministrationSeeder::academicYear()->name
            : now()->year);

        $superadmin = PpsAdministrationSeeder::demoUser('superadmin', 'RADAR Super Admin');

        $firstTerm = PpsAdministrationSeeder::examType('first_term', '1st Term', true);
        $secondTerm = PpsAdministrationSeeder::examType('second_term', '2nd Term', true);

        $classLevels = PpsAdministrationSeeder::demoSections()
            ->map(fn (Section $s) => $s->classLevel)
            ->filter()
            ->unique('id');

        $built = 0;

        foreach ($classLevels as $classLevel) {
            /** @var ClassLevel $classLevel */
            $subjects = PpsAdministrationSeeder::examinableSubjects($classLevel);

            if ($subjects->isEmpty()) {
                $this->command?->warn(
                    "No examinable subjects for {$classLevel->name}; skipping its exams."
                );

                continue;
            }

            $label = PpsAdministrationSeeder::classLabel($classLevel);

            PpsAdministrationSeeder::examFor(
                $firstTerm,
                $classLevel,
                "{$label} — 1st Term {$year}",
                1,
                "{$year}-06-15",
                $subjects,
                $superadmin->id,
                $year,
            );

            PpsAdministrationSeeder::examFor(
                $secondTerm,
                $classLevel,
                "{$label} — 2nd Term {$year}",
                2,
                "{$year}-11-20",
                $subjects,
                $superadmin->id,
                $year,
            );

            $built += 2;
        }

        $this->command?->info("Seeded {$built} exams across {$classLevels->count()} classes.");
    }
}

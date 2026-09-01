<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Term and pre-test exams.
 *
 * Was: one pps_exam_definitions row per (class, subject, term), carrying
 * class_name / section / department_id / subject_id on the exam row itself.
 * That table is gone. The same data is now one pps_exams row per (class, term)
 * with a pps_exam_class_map row per subject — 78 class x subject scopes, as
 * before, expressed as 15 exams instead of 78.
 *
 * Streams (pps_streams) are gone too: class 12's Science stream is
 * class_levels.group on the class itself, set by PpsAdministrationSeeder.
 */
class PpsExamSeeder extends Seeder
{
    /** Format A: the core catalogue, classes 4–10. */
    private const FORMAT_A_CLASSES = ['4', '5', '6', '7', '8', '9', '10'];

    /**
     * Format B: HSC subjects, class 12. The old rows carried a department_id
     * (Science / Humanities / General); that column no longer exists —
     * the stream now lives on class_levels.group.
     *
     * @var array<int, array{short:string, full:string}>
     */
    private const FORMAT_B_SUBJECTS = [
        ['short' => 'BAN-HSC', 'full' => 'Bangla (HSC)'],
        ['short' => 'ENG-HSC', 'full' => 'English (HSC)'],
        ['short' => 'PHY', 'full' => 'Physics'],
        ['short' => 'CHM', 'full' => 'Chemistry'],
        ['short' => 'BIO', 'full' => 'Biology'],
        ['short' => 'HMT', 'full' => 'Higher Mathematics'],
        ['short' => 'HIS', 'full' => 'History'],
        ['short' => 'GEO', 'full' => 'Geography'],
    ];

    public function run(): void
    {
        $year = now()->year;

        // Format A subjects — School level. Idempotent with PpsAdministrationSeeder.
        foreach (PpsAdministrationSeeder::CORE_SUBJECTS as $subject) {
            PpsAdministrationSeeder::subject(
                $subject['short'],
                $subject['full'],
                PpsAdministrationSeeder::LEVEL_SCHOOL,
            );
        }

        $formatA = array_column(PpsAdministrationSeeder::CORE_SUBJECTS, 'short');

        $firstTerm = PpsAdministrationSeeder::examType('first_term', '1st Term', true);
        $secondTerm = PpsAdministrationSeeder::examType('second_term', '2nd Term', true);
        $pretest = PpsAdministrationSeeder::examType('pretest', 'Pre-Test', false);

        // 1st Term — Format A, classes 4–10
        foreach (self::FORMAT_A_CLASSES as $className) {
            PpsAdministrationSeeder::examForClass(
                $firstTerm,
                $className,
                "Class {$className} — 1st Term {$year}",
                1,
                "{$year}-06-15",
                $formatA,
            );
        }

        // 2nd Term — Format A, classes 4–10
        foreach (self::FORMAT_A_CLASSES as $className) {
            PpsAdministrationSeeder::examForClass(
                $secondTerm,
                $className,
                "Class {$className} — 2nd Term {$year}",
                2,
                "{$year}-11-20",
                $formatA,
            );
        }

        // Format B subjects — College level.
        foreach (self::FORMAT_B_SUBJECTS as $subject) {
            PpsAdministrationSeeder::subject(
                $subject['short'],
                $subject['full'],
                PpsAdministrationSeeder::LEVEL_COLLEGE,
            );
        }

        // Class 12 sections. The old seed made 12A and 12B in the Science
        // stream; the stream is now class_levels.group on class 12 itself.
        foreach (PpsAdministrationSeeder::SECTION_NAMES as $sectionName) {
            PpsAdministrationSeeder::section('12', $sectionName);
        }

        // Class 12 pre-test, all eight Format B subjects.
        PpsAdministrationSeeder::examForClass(
            $pretest,
            '12',
            "Class 12 Pre-Test {$year}",
            1,
            "{$year}-04-30",
            array_column(self::FORMAT_B_SUBJECTS, 'short'),
        );
    }
}

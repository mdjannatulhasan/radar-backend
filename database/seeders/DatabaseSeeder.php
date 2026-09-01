<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * Order matters now: PpsDemoSeeder writes pps_marks rows, and a mark is
     * keyed by (exam component, student, subject) — so every exam it can score
     * against must already exist. PpsExamSeeder therefore runs first; it (and
     * PpsAdministrationSeeder, which it leans on) builds the school, taxonomy,
     * subjects and exams that the demo cohort is then hung off.
     */
    public function run(): void
    {
        $this->call(PpsGradeConfigSeeder::class);
        $this->call(PpsExamSeeder::class);
        $this->call(PpsDemoSeeder::class);
    }
}

<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PpsGradeConfigSeeder extends Seeder
{
    public function run(): void
    {
        // Bangladesh standard 5-point grading scale — school_id NULL = system default
        $grades = [
            ['min_pct' => 80.00, 'max_pct' => 100.00, 'letter_grade' => 'A+', 'grade_point' => 5.00, 'sort_order' => 1],
            ['min_pct' => 70.00, 'max_pct' => 79.99,  'letter_grade' => 'A',  'grade_point' => 4.00, 'sort_order' => 2],
            ['min_pct' => 60.00, 'max_pct' => 69.99,  'letter_grade' => 'A-', 'grade_point' => 3.50, 'sort_order' => 3],
            ['min_pct' => 50.00, 'max_pct' => 59.99,  'letter_grade' => 'B',  'grade_point' => 3.00, 'sort_order' => 4],
            ['min_pct' => 40.00, 'max_pct' => 49.99,  'letter_grade' => 'C',  'grade_point' => 2.00, 'sort_order' => 5],
            ['min_pct' => 33.00, 'max_pct' => 39.99,  'letter_grade' => 'D',  'grade_point' => 1.00, 'sort_order' => 6],
            ['min_pct' =>  0.00, 'max_pct' => 32.99,  'letter_grade' => 'F',  'grade_point' => 0.00, 'sort_order' => 7],
        ];

        foreach ($grades as $grade) {
            DB::table('pps_grade_config')->updateOrInsert(
                ['school_id' => null, 'letter_grade' => $grade['letter_grade']],
                array_merge($grade, ['school_id' => null, 'created_at' => now(), 'updated_at' => now()])
            );
        }

        // Seed default streams
        $streams = [
            ['name' => 'Science',    'code' => 'SCI'],
            ['name' => 'Humanities', 'code' => 'HUM'],
            ['name' => 'BST',        'code' => 'BST'],
        ];

        foreach ($streams as $stream) {
            DB::table('pps_streams')->updateOrInsert(
                ['code' => $stream['code']],
                array_merge($stream, ['is_active' => true, 'created_at' => now(), 'updated_at' => now()])
            );
        }
    }
}

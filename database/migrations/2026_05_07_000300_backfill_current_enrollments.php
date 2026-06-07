<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Create current academic year
        DB::table('academic_years')->insert([
            'year_name'  => '2025-2026',
            'start_date' => '2026-01-01',
            'end_date'   => '2026-12-31',
            'is_active'  => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $yearId = DB::table('academic_years')->where('year_name', '2025-2026')->value('id');

        // Backfill one enrollment per student from their current class/section
        DB::table('students')
            ->select(['id', 'class_name', 'section', 'roll_number', 'stream_id'])
            ->orderBy('id')
            ->chunk(500, function ($students) use ($yearId): void {
                $rows = [];
                foreach ($students as $student) {
                    $rows[] = [
                        'student_id'       => $student->id,
                        'academic_year_id' => $yearId,
                        'class_name'       => $student->class_name,
                        'section'          => $student->section,
                        'roll_number'      => $student->roll_number,
                        'stream_id'        => $student->stream_id,
                        'started_at'       => '2026-01-01',
                        'ended_at'         => null,
                        'status'           => 'active',
                        'is_current'       => true,
                        'created_at'       => now(),
                        'updated_at'       => now(),
                    ];
                }
                DB::table('student_enrollments')->insertOrIgnore($rows);
            });
    }

    public function down(): void
    {
        DB::table('student_enrollments')->delete();
        DB::table('academic_years')->where('year_name', '2025-2026')->delete();
    }
};

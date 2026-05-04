<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Migrate existing class_name/section/subject_id/department_id into exam_scopes
        $exams = DB::table('pps_exam_definitions')
            ->whereNotNull('class_name')
            ->orWhereNotNull('subject_id')
            ->get(['id', 'class_name', 'section', 'subject_id', 'department_id']);

        foreach ($exams as $exam) {
            DB::table('pps_exam_scopes')->insertOrIgnore([
                'exam_id'       => $exam->id,
                'class_name'    => $exam->class_name,
                'section'       => $exam->section,
                'subject_id'    => $exam->subject_id,
                'department_id' => $exam->department_id,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        }

        // Drop the now-denormalized columns from exam_definitions
        Schema::table('pps_exam_definitions', function (Blueprint $table): void {
            $table->dropForeign(['subject_id']);
            $table->dropForeign(['department_id']);
            $table->dropColumn(['class_name', 'section', 'subject_id', 'department_id']);
        });
    }

    public function down(): void
    {
        Schema::table('pps_exam_definitions', function (Blueprint $table): void {
            $table->string('class_name', 20)->nullable();
            $table->string('section', 10)->nullable();
            $table->foreignId('department_id')->nullable()->constrained('pps_departments')->nullOnDelete();
            $table->foreignId('subject_id')->nullable()->constrained('pps_subjects')->nullOnDelete();
        });
    }
};

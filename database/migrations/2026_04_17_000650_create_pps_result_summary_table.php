<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pps_result_summary', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('exam_id')->constrained('pps_exam_definitions')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();

            // Aggregated result
            $table->decimal('total_marks_obtained', 8, 2)->nullable();
            $table->decimal('total_marks_full', 8, 2)->nullable();
            $table->decimal('gpa', 4, 2)->nullable();
            $table->string('letter_grade', 5)->nullable();

            // Format A only — nullable for Format B
            $table->string('discipline', 30)->nullable();    // Excellent, Good, etc.
            $table->string('handwriting', 30)->nullable();
            $table->boolean('is_promoted')->nullable();

            // Attendance summary
            $table->unsignedSmallInteger('total_presence')->nullable();
            $table->unsignedSmallInteger('total_working_days')->nullable();

            // Class rank
            $table->unsignedSmallInteger('class_position')->nullable();
            $table->unsignedSmallInteger('total_students_in_class')->nullable();

            $table->timestamp('computed_at')->nullable();
            $table->foreignId('computed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['exam_id', 'student_id']);
            $table->index(['exam_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pps_result_summary');
    }
};

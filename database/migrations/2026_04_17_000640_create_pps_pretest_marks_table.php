<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pps_pretest_marks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('exam_id')->constrained('pps_exam_definitions')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained('pps_subjects')->cascadeOnDelete();

            // Format B components (Class 12 Pre-Test)
            $table->decimal('ct', 5, 2)->nullable();         // Class Test, max varies
            $table->decimal('attendance', 4, 2)->nullable(); // max 5
            $table->decimal('cq', 6, 2)->nullable();         // Creative Questions raw
            $table->decimal('cq_con', 6, 2)->nullable();     // auto: ×0.75
            $table->decimal('mcq', 5, 2)->nullable();        // Multiple Choice raw
            $table->decimal('mcq_con', 5, 2)->nullable();    // auto: formula TBD (stored when known)

            // Computed
            $table->decimal('total_obtained', 6, 2)->nullable();
            $table->decimal('highest_marks', 6, 2)->nullable();
            $table->string('letter_grade', 5)->nullable();
            $table->decimal('grade_point', 4, 2)->nullable();
            $table->string('promotion_grade', 5)->nullable();

            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['exam_id', 'student_id', 'subject_id']);
            $table->index(['exam_id', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pps_pretest_marks');
    }
};

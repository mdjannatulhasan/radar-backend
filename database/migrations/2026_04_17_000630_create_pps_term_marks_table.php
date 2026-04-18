<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pps_term_marks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('exam_id')->constrained('pps_exam_definitions')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained('pps_subjects')->cascadeOnDelete();

            // Continuous assessment components
            $table->decimal('spot_test', 5, 2)->nullable();       // max 10
            $table->decimal('spot_test_con', 5, 2)->nullable();   // auto: ×0.50, max 5
            $table->decimal('class_test2', 5, 2)->nullable();     // max 20
            $table->decimal('class_test2_con', 5, 2)->nullable(); // auto: ×0.25, max 5
            $table->decimal('attendance', 4, 2)->nullable();      // max 5 (direct)

            // Term exam
            $table->decimal('term_marks', 6, 2)->nullable();      // raw exam marks
            $table->decimal('term_con', 6, 2)->nullable();        // auto: ×0.85 (T1) or ×0.80 (T2)

            // 2nd Term only — nullable for 1st Term
            $table->decimal('vt', 5, 2)->nullable();              // Verbal Test, max 25
            $table->decimal('vt_con', 5, 2)->nullable();          // auto: ×0.20, max 5

            // Computed
            $table->decimal('total_obtained', 6, 2)->nullable();
            $table->decimal('highest_marks', 6, 2)->nullable();   // class highest for this subject
            $table->string('letter_grade', 5)->nullable();
            $table->decimal('grade_point', 4, 2)->nullable();

            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['exam_id', 'student_id', 'subject_id']);
            $table->index(['exam_id', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pps_term_marks');
    }
};

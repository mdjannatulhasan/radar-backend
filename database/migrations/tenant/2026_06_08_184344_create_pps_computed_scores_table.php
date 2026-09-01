<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('pps_computed_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained('pps_exams')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained('pps_subjects')->cascadeOnDelete();
            $table->decimal('total_obtained', 8, 2);
            $table->decimal('total_possible', 8, 2);
            $table->decimal('percentage', 5, 2);
            $table->string('letter_grade', 5)->nullable();
            $table->decimal('grade_point', 4, 2)->nullable();
            $table->timestamp('computed_at')->useCurrent();
            $table->timestamps();

            $table->unique(['exam_id', 'student_id', 'subject_id'], 'pcs_unique');
            $table->index(['student_id', 'subject_id']);
            $table->index(['exam_id', 'subject_id']);
        });
    }
    public function down(): void { Schema::dropIfExists('pps_computed_scores'); }
};

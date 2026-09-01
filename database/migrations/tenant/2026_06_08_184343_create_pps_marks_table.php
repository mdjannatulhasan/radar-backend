<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('pps_marks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('component_id')->constrained('pps_exam_components')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained('subjects')->cascadeOnDelete();
            $table->decimal('marks_obtained', 8, 2);
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recorded_at')->useCurrent();
            $table->timestamps();

            $table->unique(['component_id', 'student_id', 'subject_id'], 'pps_marks_unique');
            $table->index(['student_id', 'subject_id']);
            $table->index(['component_id']);
        });
    }
    public function down(): void { Schema::dropIfExists('pps_marks'); }
};

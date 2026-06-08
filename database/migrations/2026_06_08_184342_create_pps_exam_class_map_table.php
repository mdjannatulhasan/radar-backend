<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('pps_exam_class_map', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained('pps_exams')->cascadeOnDelete();
            $table->foreignId('class_id')->nullable()->constrained('pps_classes')->cascadeOnDelete();
            $table->foreignId('section_id')->nullable()->constrained('pps_sections')->cascadeOnDelete();
            $table->foreignId('subject_id')->nullable()->constrained('pps_subjects')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['exam_id', 'class_id', 'section_id', 'subject_id'], 'ecm_unique');
            $table->index(['exam_id']);
            $table->index(['class_id', 'section_id']);
        });
    }
    public function down(): void { Schema::dropIfExists('pps_exam_class_map'); }
};

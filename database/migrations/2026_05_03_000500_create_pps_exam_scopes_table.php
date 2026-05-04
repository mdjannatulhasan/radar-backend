<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pps_exam_scopes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('exam_id')->constrained('pps_exam_definitions')->cascadeOnDelete();
            $table->string('class_name', 20)->nullable();
            $table->string('section', 10)->nullable();
            $table->foreignId('subject_id')->nullable()->constrained('pps_subjects')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('pps_departments')->nullOnDelete();
            $table->timestamps();

            $table->unique(['exam_id', 'class_name', 'section', 'subject_id'], 'pps_exam_scopes_unique');
            $table->index(['class_name', 'section']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pps_exam_scopes');
    }
};

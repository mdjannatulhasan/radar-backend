<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pps_exam_definitions', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('code', 40)->nullable()->unique();
            $table->string('assessment_type', 50);
            $table->string('term', 30)->nullable();
            $table->decimal('total_marks', 8, 2)->default(100);
            $table->date('exam_date')->nullable();
            $table->string('class_name', 20)->nullable();
            $table->string('section', 10)->nullable();
            $table->foreignId('department_id')->nullable()->constrained('pps_departments')->nullOnDelete();
            $table->foreignId('subject_id')->nullable()->constrained('pps_subjects')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pps_exam_definitions');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pps_assessments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('teacher_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subject', 100);
            $table->string('assessment_type', 50);
            $table->string('term', 20)->nullable();
            $table->decimal('marks_obtained', 6, 2);
            $table->decimal('total_marks', 6, 2);
            $table->decimal('percentage', 5, 2);
            $table->date('exam_date')->nullable();
            $table->text('remarks')->nullable();
            $table->boolean('is_verified')->default(false);
            $table->timestamps();

            $table->index(['student_id', 'subject', 'term']);
            $table->index(['student_id', 'exam_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pps_assessments');
    }
};


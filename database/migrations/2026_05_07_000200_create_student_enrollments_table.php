<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_enrollments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained('academic_years')->cascadeOnDelete();
            $table->string('class_name', 20);
            $table->string('section', 10);
            $table->unsignedSmallInteger('roll_number')->nullable();
            $table->foreignId('stream_id')->nullable()->constrained('pps_streams')->nullOnDelete();
            $table->date('started_at')->nullable();
            $table->date('ended_at')->nullable();
            $table->enum('status', ['active', 'promoted', 'transferred', 'withdrawn', 'graduated'])->default('active');
            $table->boolean('is_current')->default(false);
            $table->timestamps();

            $table->index(['student_id', 'is_current']);
            $table->index(['student_id', 'academic_year_id']);
            $table->unique(['student_id', 'academic_year_id', 'class_name', 'section'], 'student_enrollment_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_enrollments');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Private tuition a student takes outside school hours.
 *
 * `teacher_id` is set when the tutor is one of this school's own teachers —
 * the case the principal wants surfaced (a student tutored by the school's
 * Maths teacher and still falling in Maths). `tutor_name` covers outside
 * tutors. `subject_name` is denormalised because the legacy
 * `students.private_tuition_subjects` JSON only ever held names.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pps_private_tuitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('subject_id')->nullable()->constrained('subjects')->nullOnDelete();
            $table->string('subject_name', 120);
            $table->foreignId('teacher_id')->nullable()->constrained('teachers')->nullOnDelete();
            $table->string('tutor_name', 120)->nullable();
            $table->unsignedSmallInteger('hours_per_week')->nullable();
            $table->date('started_on')->nullable();
            $table->date('ended_on')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['student_id', 'ended_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pps_private_tuitions');
    }
};

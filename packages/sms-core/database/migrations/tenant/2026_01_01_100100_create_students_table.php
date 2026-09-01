<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Students move into sms-core: a school management system's core record, not
 * a RADAR-specific one. Autoroutine will need them for attendance and
 * section rosters.
 *
 * Dropped vs RADAR's old table:
 *   stream_id            -> class_levels.group, reached via the enrollment
 *   class_name, section  -> denormalised strings; use student_enrollments
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('student_code');
            $table->string('name');
            $table->smallInteger('roll_number')->nullable();
            $table->string('photo_path')->nullable();
            $table->date('admission_date')->nullable();

            // Guardian 1
            $table->string('guardian_name')->nullable();
            $table->string('guardian_phone')->nullable();
            $table->string('guardian_email')->nullable();
            $table->string('guardian_relation', 40)->nullable();
            $table->string('guardian_profession')->nullable();
            $table->string('guardian_profession_category', 60)->nullable();
            $table->string('guardian_time_availability', 20)->nullable();

            // Guardian 2
            $table->string('second_guardian_name')->nullable();
            $table->string('second_guardian_phone')->nullable();
            $table->string('second_guardian_email')->nullable();
            $table->string('second_guardian_relation', 40)->nullable();
            $table->string('second_guardian_profession')->nullable();
            $table->string('second_guardian_profession_category', 60)->nullable();
            $table->string('second_guardian_time_availability', 20)->nullable();

            // Cached performance rollups
            $table->decimal('current_gpa', 4, 2)->nullable();
            $table->string('current_grade', 10)->nullable();
            $table->smallInteger('class_rank')->nullable();

            // Context / welfare
            $table->json('private_tuition_subjects')->nullable();
            $table->text('private_tuition_notes')->nullable();
            $table->string('family_status', 120)->nullable();
            $table->string('economic_status', 120)->nullable();
            $table->string('scholarship_status', 120)->nullable();
            $table->boolean('economically_vulnerable')->default(false);
            $table->text('health_notes')->nullable();
            $table->string('allergies')->nullable();
            $table->string('medications')->nullable();
            $table->string('residence_change_note')->nullable();
            $table->json('special_needs')->nullable();
            $table->text('confidential_context')->nullable();

            // Evaluation quadrant
            $table->smallInteger('willingness_score')->nullable();
            $table->smallInteger('ability_score')->nullable();
            $table->string('student_quadrant', 30)->nullable();

            $table->timestamps();

            $table->unique(['school_id', 'student_code']);
            $table->index(['school_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};

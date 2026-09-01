<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a student sat in a given year. This is the ONLY place a student's
 * class and section are recorded — the old denormalised students.class_name /
 * students.section strings are gone, because they could not survive promotion
 * and made year-over-year trend queries lie.
 *
 * section_id resolves the full chain: section -> class_level -> level, version,
 * group. That is how RADAR gains the level/version dimension it never had.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_enrollments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained('academic_years')->restrictOnDelete();
            $table->foreignId('section_id')->constrained('sections')->restrictOnDelete();
            $table->smallInteger('roll_number')->nullable();
            $table->string('status', 20)->default('active')
                ->comment('active | promoted | transferred | left');
            $table->timestamps();

            $table->unique(['student_id', 'academic_year_id']);
            $table->index(['school_id', 'section_id']);
            $table->index(['academic_year_id', 'section_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_enrollments');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who teaches what, where.
 *
 * Was: teacher_id -> users, plus free-text class_name and section strings.
 * That could not express version (EV Class 9 vs BV Class 9) and could not
 * represent a teacher with no login — which is most of the 159 real staff.
 *
 * Now: teacher_id -> teachers, section_id -> sections, subject_id -> subjects.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pps_teacher_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('teacher_id')->constrained('teachers')->cascadeOnDelete();
            $table->foreignId('section_id')->constrained('sections')->cascadeOnDelete();
            $table->foreignId('subject_id')->nullable()->constrained('subjects')->nullOnDelete();
            $table->boolean('is_class_teacher')->default(false);
            $table->timestamps();

            $table->unique(['teacher_id', 'section_id', 'subject_id'], 'pps_teacher_assignments_unique');
            $table->index(['school_id', 'section_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pps_teacher_assignments');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which classes, sections and subjects an exam covers.
 *
 * class_id -> class_level_id: the target is now class_levels, so an exam scope
 * distinguishes "Class 9 English version" from "Class 9 Bangla version".
 * The old pps_classes table could not.
 *
 * A NULL class_level_id means "all classes"; NULL section_id means "all
 * sections of that class"; NULL subject_id means "all subjects".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pps_exam_class_map', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('exam_id')->constrained('pps_exams')->cascadeOnDelete();
            $table->foreignId('class_level_id')->nullable()->constrained('class_levels')->cascadeOnDelete();
            $table->foreignId('section_id')->nullable()->constrained('sections')->cascadeOnDelete();
            $table->foreignId('subject_id')->nullable()->constrained('subjects')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['exam_id', 'class_level_id', 'section_id', 'subject_id'], 'ecm_unique');
            $table->index(['class_level_id', 'section_id']);
            $table->index('exam_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pps_exam_class_map');
    }
};

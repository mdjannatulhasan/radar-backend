<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * section_names is the canonical vocabulary — CPSCS uses both letters (A–E)
 * and flower names (Aster, Daffodil, Dahlia, Dolon, Mohua, Orchid, Shapla).
 * Without it, "Shapla" gets typo'd into "Shopla" across 98 rows and reporting
 * silently splits into two buckets.
 *
 * sections is the concrete instance: class_level + section_name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('section_names', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('name', 30);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['school_id', 'name']);
        });

        Schema::create('sections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_level_id')->constrained('class_levels')->cascadeOnDelete();
            $table->foreignId('section_name_id')->constrained('section_names')->restrictOnDelete();
            $table->unsignedBigInteger('class_teacher_id')->nullable();
            $table->unsignedSmallInteger('capacity')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['class_level_id', 'section_name_id']);
            $table->index(['school_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sections');
        Schema::dropIfExists('section_names');
    }
};

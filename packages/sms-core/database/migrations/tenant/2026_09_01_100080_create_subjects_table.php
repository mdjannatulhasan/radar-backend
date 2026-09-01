<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Subjects are scoped by level and version — "Bangla 1st Paper" in the Bangla
 * version of the school level is a different subject row from its English
 * version counterpart, and they carry different quotas and marks schemes.
 *
 * RADAR's pps_subjects.department_id is dropped; department was a duplicate of
 * stream, and both are now class_levels.group.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subjects', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('level_id')->nullable()->constrained('levels')->restrictOnDelete();
            $table->foreignId('version_id')->nullable()->constrained('versions')->restrictOnDelete();
            $table->string('full_name');
            $table->string('short_name', 20);
            $table->decimal('default_periods', 4, 2)->default(1);
            $table->boolean('is_optional')->default(false);
            $table->boolean('counts_as_class')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['school_id', 'level_id', 'version_id', 'short_name'], 'subjects_identity_unique');
            $table->index(['school_id', 'is_active']);
        });

        // Which subjects a given class_level actually teaches, and how often.
        Schema::create('subject_quotas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained('subjects')->cascadeOnDelete();
            $table->foreignId('class_level_id')->constrained('class_levels')->cascadeOnDelete();
            $table->decimal('periods_per_week', 4, 1)->default(0);
            $table->timestamps();

            $table->unique(['subject_id', 'class_level_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subject_quotas');
        Schema::dropIfExists('subjects');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A class as actually taught: "Class 9" in the English version of the school
 * level is a different class_level from "Class 9" in the Bangla version.
 * RADAR previously could not express that distinction at all.
 *
 * `group` replaces BOTH of RADAR's dead tables — pps_streams
 * (Science/Humanities/BST) and pps_departments (Science/Humanities/General),
 * which were duplicates of each other. It only applies at College level, where
 * Class 11/12 split into groups.
 *
 * The period columns are otoroutine's routine-scheduling fields. RADAR ignores
 * them; they are here so Autoroutine can migrate onto this table unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_levels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('level_id')->constrained('levels')->restrictOnDelete();
            $table->foreignId('version_id')->constrained('versions')->restrictOnDelete();
            $table->string('name');
            $table->string('group', 30)->nullable()
                ->comment('science | humanities | business_studies — College level only');
            $table->unsignedSmallInteger('numeric_order')->nullable()
                ->comment('Sortable rank: Nursery=0, KG=1, Class 1=2 … Class 12=13');
            $table->boolean('is_active')->default(true);

            // Autoroutine scheduling fields — unused by RADAR.
            $table->unsignedInteger('max_periods_per_day')->default(6);
            $table->unsignedInteger('start_period')->default(1);
            $table->unsignedInteger('end_period')->default(7);
            $table->unsignedInteger('tiffin_period')->nullable();
            $table->json('day_periods')->nullable();

            $table->timestamps();

            $table->unique(['school_id', 'level_id', 'version_id', 'name'], 'class_levels_identity_unique');
            $table->index(['school_id', 'numeric_order']);
        });

        \DB::statement("
            ALTER TABLE class_levels
            ADD CONSTRAINT class_levels_group_check
            CHECK (\"group\" IS NULL OR \"group\" IN ('science','humanities','business_studies'))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('class_levels');
    }
};

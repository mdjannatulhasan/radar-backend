<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two orthogonal axes of a Bangladeshi school-and-college:
 *
 *   level   — School (Nursery–Class 10) vs College (Class 11–12)
 *   version — Bangla medium vs English version
 *
 * otoroutine additionally had a `tracks` table holding the four combinations
 * (BV school, BV college, EV school, EV college). It is not carried over: a
 * track is exactly level × version, and class_levels holds both FKs, so the
 * table was pure redundancy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('levels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['school_id', 'name']);
        });

        Schema::create('versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['school_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('versions');
        Schema::dropIfExists('levels');
    }
};

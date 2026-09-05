<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which version/level pairs a teacher belongs to (otoroutine `teacher_scopes`).
 * For leadership staff this is their responsibility scope: a Vice Principal
 * scoped to English/College is notified about English-version college
 * students only. A teacher with no rows is unscoped (whole school).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_scopes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('teacher_id')->constrained('teachers')->cascadeOnDelete();
            $table->foreignId('version_id')->constrained('versions')->cascadeOnDelete();
            $table->foreignId('level_id')->constrained('levels')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['teacher_id', 'version_id', 'level_id'], 'teacher_scopes_unique');
            $table->index(['school_id', 'version_id', 'level_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_scopes');
    }
};

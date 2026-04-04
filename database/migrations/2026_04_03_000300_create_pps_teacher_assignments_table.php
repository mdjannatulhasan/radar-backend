<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pps_teacher_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('teacher_id')->constrained('users')->cascadeOnDelete();
            $table->string('class_name', 20);
            $table->string('section', 10);
            $table->string('subject', 100)->nullable();
            $table->boolean('is_class_teacher')->default(false);
            $table->timestamps();

            $table->unique(['teacher_id', 'class_name', 'section', 'subject'], 'pps_teacher_assignments_unique');
            $table->index(['class_name', 'section']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pps_teacher_assignments');
    }
};

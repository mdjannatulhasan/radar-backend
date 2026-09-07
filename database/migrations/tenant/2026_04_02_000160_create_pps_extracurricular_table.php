<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pps_extracurricular', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->string('activity_name');
            $table->string('category', 50)->nullable();
            $table->string('role')->nullable();
            $table->string('achievement')->nullable();
            $table->unsignedTinyInteger('achievement_level')->default(0);
            $table->date('event_date');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['student_id', 'event_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pps_extracurricular');
    }
};


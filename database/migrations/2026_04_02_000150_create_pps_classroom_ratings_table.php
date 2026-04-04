<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pps_classroom_ratings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('teacher_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subject', 100)->nullable();
            $table->date('rating_period');
            $table->string('period_type', 20)->default('weekly');
            $table->unsignedTinyInteger('participation')->nullable();
            $table->unsignedTinyInteger('attentiveness')->nullable();
            $table->unsignedTinyInteger('group_work')->nullable();
            $table->unsignedTinyInteger('creativity')->nullable();
            $table->string('behavioral_flag')->nullable();
            $table->text('free_comment')->nullable();
            $table->timestamps();

            $table->index(['student_id', 'rating_period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pps_classroom_ratings');
    }
};


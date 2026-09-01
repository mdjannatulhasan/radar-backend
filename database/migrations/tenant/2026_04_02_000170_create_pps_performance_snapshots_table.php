<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pps_performance_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->string('snapshot_period', 7);
            $table->decimal('academic_score', 5, 2);
            $table->decimal('attendance_score', 5, 2);
            $table->decimal('behavior_score', 5, 2);
            $table->decimal('participation_score', 5, 2);
            $table->decimal('extracurricular_score', 5, 2);
            $table->decimal('overall_score', 5, 2);
            $table->decimal('risk_score', 5, 2)->default(0);
            $table->string('alert_level', 20)->default('none');
            $table->string('trend_direction', 20)->default('stable');
            $table->json('snapshot_data')->nullable();
            $table->timestamp('calculated_at')->nullable();
            $table->timestamps();

            $table->unique(['student_id', 'snapshot_period']);
            $table->index(['snapshot_period', 'alert_level']);
            $table->index(['snapshot_period', 'risk_score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pps_performance_snapshots');
    }
};


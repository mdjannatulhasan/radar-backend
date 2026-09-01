<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pps_alerts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->string('snapshot_period', 7);
            $table->string('alert_level', 20);
            $table->json('trigger_reasons');
            $table->json('notified_to')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('resolution_action')->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['alert_level', 'resolved_at']);
            $table->index(['student_id', 'snapshot_period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pps_alerts');
    }
};


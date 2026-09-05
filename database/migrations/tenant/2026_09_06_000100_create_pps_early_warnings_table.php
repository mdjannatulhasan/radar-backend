<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A predicted fall: the earliest horizon (1, 3 or 6 months) at which the
 * student's projected risk crosses the early-warning threshold. One open row
 * per student; re-generated each period, cleared when the trend recovers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pps_early_warnings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->string('snapshot_period', 7);
            $table->unsignedTinyInteger('horizon_months');
            $table->string('category', 20); // imminent | near | emerging
            $table->decimal('current_risk', 5, 2);
            $table->decimal('projected_risk', 5, 2);
            $table->decimal('projected_overall', 5, 2)->nullable();
            $table->json('drivers')->nullable();
            $table->string('status', 20)->default('open'); // open | acknowledged | cleared
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->text('acknowledgement_note')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->unique(['student_id', 'snapshot_period']);
            $table->index(['status', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pps_early_warnings');
    }
};

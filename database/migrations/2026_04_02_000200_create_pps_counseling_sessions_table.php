<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pps_counseling_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('counselor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('referred_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('alert_id')->nullable()->constrained('pps_alerts')->nullOnDelete();
            $table->date('session_date');
            $table->string('session_type', 30)->default('initial');
            $table->text('session_notes')->nullable();
            $table->text('action_plan')->nullable();
            $table->date('next_session_date')->nullable();
            $table->string('progress_status', 30)->nullable();
            $table->timestamps();

            $table->index(['student_id', 'session_date']);
            $table->index('counselor_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pps_counseling_sessions');
    }
};

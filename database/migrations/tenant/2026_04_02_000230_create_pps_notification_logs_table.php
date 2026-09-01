<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pps_notification_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 80);
            $table->string('channel', 30)->default('database');
            $table->string('recipient_role', 40);
            $table->foreignId('recipient_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete();
            $table->string('snapshot_period', 7)->nullable();
            $table->string('status', 30)->default('generated');
            $table->string('subject', 180);
            $table->text('body');
            $table->json('meta')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'snapshot_period']);
            $table->index(['recipient_role', 'recipient_user_id']);
            $table->index(['student_id', 'snapshot_period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pps_notification_logs');
    }
};

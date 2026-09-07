<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pps_attendance', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('date');
            $table->string('status', 20);
            $table->unsignedTinyInteger('period')->nullable();
            $table->string('subject', 100)->nullable();
            $table->string('absence_reason')->nullable();
            $table->boolean('parent_notified')->default(false);
            $table->timestamps();

            $table->unique(['student_id', 'date', 'period'], 'pps_attendance_unique_daily');
            $table->index(['student_id', 'date']);
            $table->index(['date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pps_attendance');
    }
};


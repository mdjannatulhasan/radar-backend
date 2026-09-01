<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pps_behavior_cards', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('card_type', 20);
            $table->text('reason');
            $table->text('notes')->nullable();
            $table->boolean('is_integrity_violation')->default(false);
            $table->timestamp('issued_at')->useCurrent();
            $table->timestamps();

            $table->index(['student_id', 'issued_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pps_behavior_cards');
    }
};


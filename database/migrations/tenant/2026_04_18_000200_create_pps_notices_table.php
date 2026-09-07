<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pps_notices', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 255);
            $table->text('body');
            $table->foreignId('posted_by')->constrained('users')->restrictOnDelete();

            // JSON array of role slugs: ['public'], ['teachers','counselors'], etc.
            // Special values: 'public' (all users), 'all' (everyone), 'students' (guardians)
            // Role slugs: 'teachers', 'counselors', 'welfare_officers', 'guardians', 'staff'
            $table->json('audience');

            // For notices targeting a specific student's guardian
            $table->foreignId('target_student_id')
                ->nullable()
                ->constrained('students')
                ->nullOnDelete();

            // For notices targeting a specific user
            $table->foreignId('target_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->boolean('is_expiry_enabled')->default(false);
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_pinned')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pps_notices');
    }
};

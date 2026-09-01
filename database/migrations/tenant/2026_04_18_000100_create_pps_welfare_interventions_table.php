<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pps_welfare_interventions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('officer_id')->constrained('users')->restrictOnDelete();
            $table->string('intervention_type', 40);
            // Allowed: scholarship_review, economic_assessment, family_visit,
            //          counseling_referral, financial_aid, other
            $table->text('notes')->nullable();
            $table->string('scholarship_status_set', 40)->nullable();
            $table->string('economic_status_set', 40)->nullable();
            $table->boolean('economically_vulnerable_set')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pps_welfare_interventions');
    }
};

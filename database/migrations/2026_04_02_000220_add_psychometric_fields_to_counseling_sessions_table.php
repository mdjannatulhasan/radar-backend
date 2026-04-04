<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pps_counseling_sessions', function (Blueprint $table): void {
            $table->json('psychometric_scores')->nullable()->after('progress_status');
            $table->json('special_needs_profile')->nullable()->after('psychometric_scores');
            $table->string('assessment_tool', 120)->nullable()->after('special_needs_profile');
        });
    }

    public function down(): void
    {
        Schema::table('pps_counseling_sessions', function (Blueprint $table): void {
            $table->dropColumn([
                'psychometric_scores',
                'special_needs_profile',
                'assessment_tool',
            ]);
        });
    }
};

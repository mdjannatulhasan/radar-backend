<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('pps_school_configs', function (Blueprint $table): void {
            $table->decimal('heatmap_score_critical', 5, 2)->default(70)->after('notify_guardian_email_on_urgent');
            $table->decimal('heatmap_score_attention', 5, 2)->default(82)->after('heatmap_score_critical');
        });
    }

    public function down(): void
    {
        Schema::table('pps_school_configs', function (Blueprint $table): void {
            $table->dropColumn(['heatmap_score_critical', 'heatmap_score_attention']);
        });
    }
};

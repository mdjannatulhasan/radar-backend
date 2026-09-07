<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pps_school_configs', function (Blueprint $table): void {
            $table->decimal('early_warning_risk_threshold', 5, 2)->default(40)->after('heatmap_score_attention');
            $table->unsignedSmallInteger('early_warning_min_history')->default(3)->after('early_warning_risk_threshold');
        });
    }

    public function down(): void
    {
        Schema::table('pps_school_configs', function (Blueprint $table): void {
            $table->dropColumn(['early_warning_risk_threshold', 'early_warning_min_history']);
        });
    }
};

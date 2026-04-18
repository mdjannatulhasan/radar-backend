<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table): void {
            $table->foreignId('stream_id')
                ->nullable()
                ->after('section')
                ->constrained('pps_streams')
                ->nullOnDelete();
        });

        Schema::table('pps_class_sections', function (Blueprint $table): void {
            $table->foreignId('stream_id')
                ->nullable()
                ->after('section')
                ->constrained('pps_streams')
                ->nullOnDelete();
            // class_level lets us distinguish Format A (≤10) from Format B (11–12)
            $table->unsignedTinyInteger('class_level')->nullable()->after('class_name');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table): void {
            $table->dropForeign(['stream_id']);
            $table->dropColumn('stream_id');
        });

        Schema::table('pps_class_sections', function (Blueprint $table): void {
            $table->dropForeign(['stream_id']);
            $table->dropColumn(['stream_id', 'class_level']);
        });
    }
};

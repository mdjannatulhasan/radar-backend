<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table): void {
            $table->string('guardian_profession')->nullable()->after('guardian_email');
            $table->string('guardian_profession_category', 60)->nullable()->after('guardian_profession');
            // business|doctor|lawyer|military|government|private_sector|education|agriculture|labor|other
            $table->string('guardian_time_availability', 20)->nullable()->after('guardian_profession_category');
            // high|medium|low
            $table->unsignedTinyInteger('willingness_score')->nullable()->after('guardian_time_availability');
            // 1-5, teacher-assessed
            $table->unsignedTinyInteger('ability_score')->nullable()->after('willingness_score');
            // 1-5, derived from academic performance
            $table->string('student_quadrant', 30)->nullable()->after('ability_score');
            // willing_able|unwilling_able|willing_unable|unwilling_unable
            $table->boolean('economically_vulnerable')->default(false)->after('student_quadrant');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table): void {
            $table->dropColumn([
                'guardian_profession',
                'guardian_profession_category',
                'guardian_time_availability',
                'willingness_score',
                'ability_score',
                'student_quadrant',
                'economically_vulnerable',
            ]);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table): void {
            $table->string('guardian_relation', 40)->nullable()->after('guardian_email');
            // Second guardian (typically the other parent)
            $table->string('second_guardian_name')->nullable()->after('guardian_time_availability');
            $table->string('second_guardian_phone')->nullable()->after('second_guardian_name');
            $table->string('second_guardian_email')->nullable()->after('second_guardian_phone');
            $table->string('second_guardian_relation', 40)->nullable()->after('second_guardian_email');
            $table->string('second_guardian_profession')->nullable()->after('second_guardian_relation');
            $table->string('second_guardian_profession_category', 60)->nullable()->after('second_guardian_profession');
            $table->string('second_guardian_time_availability', 20)->nullable()->after('second_guardian_profession_category');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table): void {
            $table->dropColumn([
                'guardian_relation',
                'second_guardian_name',
                'second_guardian_phone',
                'second_guardian_email',
                'second_guardian_relation',
                'second_guardian_profession',
                'second_guardian_profession_category',
                'second_guardian_time_availability',
            ]);
        });
    }
};

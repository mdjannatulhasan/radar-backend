<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table): void {
            $table->date('admission_date')->nullable()->after('roll_number');
            $table->decimal('current_gpa', 4, 2)->nullable()->after('admission_date');
            $table->string('current_grade', 10)->nullable()->after('current_gpa');
            $table->unsignedSmallInteger('class_rank')->nullable()->after('current_grade');
            $table->json('private_tuition_subjects')->nullable()->after('guardian_email');
            $table->text('private_tuition_notes')->nullable()->after('private_tuition_subjects');
            $table->string('family_status', 120)->nullable()->after('private_tuition_notes');
            $table->string('economic_status', 120)->nullable()->after('family_status');
            $table->string('scholarship_status', 120)->nullable()->after('economic_status');
            $table->text('health_notes')->nullable()->after('scholarship_status');
            $table->string('allergies')->nullable()->after('health_notes');
            $table->string('medications')->nullable()->after('allergies');
            $table->string('residence_change_note', 255)->nullable()->after('medications');
            $table->json('special_needs')->nullable()->after('residence_change_note');
            $table->text('confidential_context')->nullable()->after('special_needs');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table): void {
            $table->dropColumn([
                'admission_date',
                'current_gpa',
                'current_grade',
                'class_rank',
                'private_tuition_subjects',
                'private_tuition_notes',
                'family_status',
                'economic_status',
                'scholarship_status',
                'health_notes',
                'allergies',
                'medications',
                'residence_change_note',
                'special_needs',
                'confidential_context',
            ]);
        });
    }
};

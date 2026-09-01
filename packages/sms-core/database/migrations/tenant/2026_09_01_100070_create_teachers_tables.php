<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RADAR had no teacher entity at all — only a `role` string on users and a
 * pps_teacher_assignments table pointing at user ids. That cannot express a
 * teacher who has not been given a login, which is most of the 159 real CPSCS
 * staff.
 *
 * A teacher is a person on staff. A user is a login. They are 1:0..1: user_id
 * is nullable, so a teacher can exist with no account and gain one later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('designations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->unsignedSmallInteger('rank');
            $table->timestamps();

            $table->unique(['school_id', 'title']);
            $table->unique(['school_id', 'rank']);
        });

        Schema::create('teachers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('full_name');
            $table->string('short_code', 10)->nullable();
            $table->foreignId('designation_id')->nullable()->constrained('designations')->nullOnDelete();
            $table->string('contact_phone')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('education')->nullable();
            $table->unsignedInteger('max_weekly_periods')->default(26)
                ->comment('Autoroutine scheduling cap — unused by RADAR');
            $table->boolean('is_active')->default(true);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['school_id', 'short_code']);
            $table->unique('user_id');
            $table->index(['school_id', 'is_active']);
        });

        // sections.class_teacher_id could not be constrained before teachers existed.
        Schema::table('sections', function (Blueprint $table): void {
            $table->foreign('class_teacher_id')->references('id')->on('teachers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sections', function (Blueprint $table): void {
            $table->dropForeign(['class_teacher_id']);
        });

        Schema::dropIfExists('teachers');
        Schema::dropIfExists('designations');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table): void {
            $table->id();
            $table->string('student_code')->unique();
            $table->string('name');
            $table->string('class_name', 20);
            $table->string('section', 10);
            $table->unsignedSmallInteger('roll_number')->nullable();
            $table->string('photo_path')->nullable();
            $table->string('guardian_name')->nullable();
            $table->string('guardian_phone')->nullable();
            $table->string('guardian_email')->nullable();
            $table->timestamps();

            $table->index(['class_name', 'section']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};


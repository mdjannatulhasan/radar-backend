<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pps_class_sections', function (Blueprint $table): void {
            $table->id();
            $table->string('class_name', 20);
            $table->string('section', 10);
            $table->foreignId('department_id')->nullable()->constrained('pps_departments')->nullOnDelete();
            $table->unsignedSmallInteger('capacity')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['class_name', 'section'], 'pps_class_sections_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pps_class_sections');
    }
};

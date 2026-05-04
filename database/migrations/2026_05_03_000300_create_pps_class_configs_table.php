<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pps_class_configs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('class_id')->constrained('pps_classes')->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('pps_departments')->nullOnDelete();
            $table->foreignId('section_id')->nullable()->constrained('pps_sections')->nullOnDelete();
            $table->unsignedSmallInteger('capacity')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['class_id', 'department_id', 'section_id'], 'pps_class_configs_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pps_class_configs');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pps_subjects', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code', 30)->nullable()->unique();
            $table->foreignId('department_id')->nullable()->constrained('pps_departments')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pps_subjects');
    }
};

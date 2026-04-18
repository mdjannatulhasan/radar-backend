<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pps_streams', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 60)->unique(); // Science, Humanities, BST
            $table->string('code', 20)->unique()->nullable(); // SCI, HUM, BST
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pps_streams');
    }
};

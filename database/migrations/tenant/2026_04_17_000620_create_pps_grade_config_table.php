<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pps_grade_config', function (Blueprint $table): void {
            $table->id();
            // NULL school_id = system default used by all schools
            $table->unsignedBigInteger('school_id')->nullable()->index();
            $table->decimal('min_pct', 5, 2);  // e.g. 80.00
            $table->decimal('max_pct', 5, 2);  // e.g. 100.00
            $table->string('letter_grade', 5);  // A+, A, A-, B, C, D, F
            $table->decimal('grade_point', 4, 2); // 5.00, 4.00, 3.50 …
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['school_id', 'letter_grade']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pps_grade_config');
    }
};

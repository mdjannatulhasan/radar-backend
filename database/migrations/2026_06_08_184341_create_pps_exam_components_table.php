<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('pps_exam_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained('pps_exams')->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('code', 40);
            $table->decimal('max_raw_marks', 8, 2);
            $table->decimal('max_contribution', 8, 2);
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['exam_id', 'code']);
            $table->index('exam_id');
        });
    }
    public function down(): void { Schema::dropIfExists('pps_exam_components'); }
};

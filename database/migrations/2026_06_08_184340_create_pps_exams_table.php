<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('pps_exams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_type_id')->constrained('pps_exam_types')->restrictOnDelete();
            $table->string('title');
            $table->smallInteger('academic_year');
            $table->unsignedTinyInteger('term');
            $table->date('exam_date')->nullable();
            $table->string('scope', 20)->default('class');
            $table->string('status', 20)->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['academic_year', 'term']);
            $table->index(['exam_type_id', 'academic_year', 'term']);
        });
    }
    public function down(): void { Schema::dropIfExists('pps_exams'); }
};

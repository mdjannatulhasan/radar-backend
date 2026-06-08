<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        // Drop in FK order
        Schema::dropIfExists('pps_assessments');
        Schema::dropIfExists('pps_pretest_marks');
        Schema::dropIfExists('pps_result_summary');
        Schema::dropIfExists('pps_term_marks');
        Schema::dropIfExists('pps_exam_scopes');
        Schema::dropIfExists('pps_exam_definitions');
    }
    public function down(): void {}
};

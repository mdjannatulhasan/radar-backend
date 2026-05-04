<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('pps_class_sections')->get();

        foreach ($rows as $row) {
            // Upsert class
            $classId = DB::table('pps_classes')
                ->insertOrIgnore([
                    'name' => $row->class_name,
                    'numeric_order' => is_numeric($row->class_name) ? (int) $row->class_name : null,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

            $classId = DB::table('pps_classes')->where('name', $row->class_name)->value('id');

            // Upsert section
            DB::table('pps_sections')->insertOrIgnore([
                'name' => $row->section,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $sectionId = DB::table('pps_sections')->where('name', $row->section)->value('id');

            // Insert class config
            DB::table('pps_class_configs')->insertOrIgnore([
                'class_id' => $classId,
                'department_id' => $row->department_id,
                'section_id' => $sectionId,
                'capacity' => $row->capacity,
                'is_active' => $row->is_active,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('pps_class_configs')->truncate();
        DB::table('pps_sections')->truncate();
        DB::table('pps_classes')->truncate();
    }
};

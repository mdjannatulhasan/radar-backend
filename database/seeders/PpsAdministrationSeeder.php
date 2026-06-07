<?php

namespace Database\Seeders;

use App\Models\Pps\ClassConfig;
use App\Models\Pps\ClassSection;
use App\Models\Pps\Department;
use App\Models\Pps\ExamDefinition;
use App\Models\Pps\ExamScope;
use App\Models\Pps\SchoolClass;
use App\Models\Pps\Section;
use App\Models\Pps\Subject;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class PpsAdministrationSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->firstOrCreate(['email' => 'superadmin@pps.local'], [
            'name' => 'RADAR Super Admin',
            'role' => 'superadmin',
            'password' => Hash::make(PpsDemoSeeder::DEMO_PASSWORD),
        ]);

        $science = Department::query()->firstOrCreate(
            ['code' => 'SCI'],
            ['name' => 'Science', 'description' => 'Science stream and lab-linked classes.']
        );
        $humanities = Department::query()->firstOrCreate(
            ['code' => 'HUM'],
            ['name' => 'Humanities', 'description' => 'Humanities and social studies stream.']
        );
        $general = Department::query()->firstOrCreate(
            ['code' => 'GEN'],
            ['name' => 'General', 'description' => 'Common academic subjects across classes.']
        );

        foreach (['6', '7', '8', '9', '10'] as $className) {
            foreach (['A', 'B'] as $sectionName) {
                $deptId = in_array($className, ['9', '10'], true) ? $science->id : $general->id;

                ClassSection::query()->updateOrCreate(
                    ['class_name' => $className, 'section' => $sectionName],
                    ['department_id' => $deptId, 'capacity' => 45, 'is_active' => true],
                );

                // Populate pps_classes, pps_sections, and pps_class_configs so the
                // /classes/structure endpoint returns real data (not empty array).
                $schoolClass = SchoolClass::query()->updateOrCreate(
                    ['name' => $className],
                    ['numeric_order' => (int) $className, 'is_active' => true],
                );

                $section = Section::query()->updateOrCreate(
                    ['name' => $sectionName],
                    ['is_active' => true],
                );

                ClassConfig::query()->updateOrCreate(
                    ['class_id' => $schoolClass->id, 'section_id' => $section->id, 'department_id' => null],
                    ['capacity' => 45, 'is_active' => true],
                );
            }
        }

        $subjects = [
            ['name' => 'Bangla', 'code' => 'BAN', 'department_id' => $general->id],
            ['name' => 'English', 'code' => 'ENG', 'department_id' => $general->id],
            ['name' => 'Mathematics', 'code' => 'MTH', 'department_id' => $general->id],
            ['name' => 'Science', 'code' => 'SCIENCE', 'department_id' => $science->id],
            ['name' => 'Social Studies', 'code' => 'SOC', 'department_id' => $humanities->id],
        ];

        foreach ($subjects as $subject) {
            Subject::query()->updateOrCreate(
                ['code' => $subject['code']],
                [
                    'name' => $subject['name'],
                    'department_id' => $subject['department_id'],
                    'is_active' => true,
                ],
            );
        }

        $subjectMap = Subject::query()->get()->keyBy('name');
        $term = now()->format('Y').'-T1';
        $examDate = now()->startOfMonth()->addDays(18)->toDateString();

        foreach (['6', '7', '8', '9', '10'] as $className) {
            foreach (['Bangla', 'English', 'Mathematics', 'Science', 'Social Studies'] as $subjectName) {
                $subject = $subjectMap->get($subjectName);

                $exam = ExamDefinition::query()->updateOrCreate(
                    ['code' => "{$className}-{$subject?->code}-MT-{$term}"],
                    [
                        'title' => "{$subjectName} Mid Term — Class {$className}",
                        'assessment_type' => 'mid_term',
                        'term' => $term,
                        'total_marks' => 100,
                        'exam_date' => $examDate,
                        'is_active' => true,
                    ],
                );

                ExamScope::query()->updateOrCreate(
                    ['exam_id' => $exam->id, 'class_name' => $className, 'section' => null, 'subject_id' => $subject?->id],
                    ['department_id' => $subject?->department_id],
                );
            }
        }
    }
}

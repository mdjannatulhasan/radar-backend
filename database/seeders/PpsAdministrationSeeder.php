<?php

namespace Database\Seeders;

use App\Models\Pps\ClassSection;
use App\Models\Pps\Department;
use App\Models\Pps\ExamDefinition;
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
            foreach (['A', 'B'] as $section) {
                ClassSection::query()->updateOrCreate(
                    ['class_name' => $className, 'section' => $section],
                    [
                        'department_id' => in_array($className, ['9', '10'], true) ? $science->id : $general->id,
                        'capacity' => 45,
                        'is_active' => true,
                    ],
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

                ExamDefinition::query()->updateOrCreate(
                    ['code' => "{$className}-{$subject?->code}-MT-{$term}"],
                    [
                        'title' => "{$subjectName} Mid Term",
                        'assessment_type' => 'mid_term',
                        'term' => $term,
                        'total_marks' => 100,
                        'exam_date' => $examDate,
                        'class_name' => $className,
                        'section' => null,
                        'department_id' => $subject?->department_id,
                        'subject_id' => $subject?->id,
                        'is_active' => true,
                    ],
                );
            }
        }
    }
}

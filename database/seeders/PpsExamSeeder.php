<?php

namespace Database\Seeders;

use App\Models\Pps\ClassSection;
use App\Models\Pps\Department;
use App\Models\Pps\ExamDefinition;
use App\Models\Pps\Stream;
use App\Models\Pps\Subject;
use Illuminate\Database\Seeder;

class PpsExamSeeder extends Seeder
{
    public function run(): void
    {
        $year = now()->year;

        $general = Department::query()->firstOrCreate(
            ['code' => 'GEN'],
            ['name' => 'General', 'description' => 'Common academic subjects across classes.']
        );
        $science = Department::query()->firstOrCreate(
            ['code' => 'SCI'],
            ['name' => 'Science', 'description' => 'Science stream.']
        );
        $humanities = Department::query()->firstOrCreate(
            ['code' => 'HUM'],
            ['name' => 'Humanities', 'description' => 'Humanities stream.']
        );

        // --- Format A subjects (classes 4–10) ---
        $formatASubjects = [
            ['name' => 'Bangla', 'code' => 'BAN', 'department_id' => $general->id],
            ['name' => 'English', 'code' => 'ENG', 'department_id' => $general->id],
            ['name' => 'Mathematics', 'code' => 'MTH', 'department_id' => $general->id],
            ['name' => 'Science', 'code' => 'SCIENCE', 'department_id' => $science->id],
            ['name' => 'Social Studies', 'code' => 'SOC', 'department_id' => $humanities->id],
        ];

        foreach ($formatASubjects as $s) {
            Subject::query()->updateOrCreate(['code' => $s['code']], [
                'name' => $s['name'],
                'department_id' => $s['department_id'],
                'is_active' => true,
            ]);
        }

        $subjectMap = Subject::query()->get()->keyBy('name');

        // 1st Term — Format A, classes 4–10
        foreach (['4', '5', '6', '7', '8', '9', '10'] as $className) {
            $term = "{$year}-T1";
            foreach (['Bangla', 'English', 'Mathematics', 'Science', 'Social Studies'] as $subjectName) {
                $subject = $subjectMap->get($subjectName);
                ExamDefinition::query()->updateOrCreate(
                    ['code' => "{$className}-{$subject?->code}-1ST-{$year}"],
                    [
                        'title' => "Class {$className} — 1st Term {$year}",
                        'assessment_type' => 'first_term',
                        'term' => $term,
                        'total_marks' => 100,
                        'exam_date' => "{$year}-06-15",
                        'class_name' => $className,
                        'section' => null,
                        'department_id' => $subject?->department_id,
                        'subject_id' => $subject?->id,
                        'is_active' => true,
                    ]
                );
            }
        }

        // 2nd Term — Format A, classes 4–10
        foreach (['4', '5', '6', '7', '8', '9', '10'] as $className) {
            $term = "{$year}-T2";
            foreach (['Bangla', 'English', 'Mathematics', 'Science', 'Social Studies'] as $subjectName) {
                $subject = $subjectMap->get($subjectName);
                ExamDefinition::query()->updateOrCreate(
                    ['code' => "{$className}-{$subject?->code}-2ND-{$year}"],
                    [
                        'title' => "Class {$className} — 2nd Term {$year}",
                        'assessment_type' => 'second_term',
                        'term' => $term,
                        'total_marks' => 100,
                        'exam_date' => "{$year}-11-20",
                        'class_name' => $className,
                        'section' => null,
                        'department_id' => $subject?->department_id,
                        'subject_id' => $subject?->id,
                        'is_active' => true,
                    ]
                );
            }
        }

        // --- Format B subjects (class 12) ---
        $formatBSubjects = [
            ['name' => 'Bangla (HSC)', 'code' => 'BAN-HSC', 'department_id' => $general->id],
            ['name' => 'English (HSC)', 'code' => 'ENG-HSC', 'department_id' => $general->id],
            ['name' => 'Physics', 'code' => 'PHY', 'department_id' => $science->id],
            ['name' => 'Chemistry', 'code' => 'CHM', 'department_id' => $science->id],
            ['name' => 'Biology', 'code' => 'BIO', 'department_id' => $science->id],
            ['name' => 'Higher Mathematics', 'code' => 'HMT', 'department_id' => $science->id],
            ['name' => 'History', 'code' => 'HIS', 'department_id' => $humanities->id],
            ['name' => 'Geography', 'code' => 'GEO', 'department_id' => $humanities->id],
        ];

        foreach ($formatBSubjects as $s) {
            Subject::query()->updateOrCreate(['code' => $s['code']], [
                'name' => $s['name'],
                'department_id' => $s['department_id'],
                'is_active' => true,
            ]);
        }

        $subjectMap = Subject::query()->get()->keyBy('code');

        // Class 12 sections for each stream
        $streams = Stream::query()->get()->keyBy('code');
        $sciStream = $streams->get('SCI');
        $humStream = $streams->get('HUM');
        $bstStream = $streams->get('BST');

        foreach (['12'] as $className) {
            foreach ([
                ['code' => 'BAN-HSC', 'stream_id' => null],
                ['code' => 'ENG-HSC', 'stream_id' => null],
                ['code' => 'PHY', 'stream_id' => $sciStream?->id],
                ['code' => 'CHM', 'stream_id' => $sciStream?->id],
                ['code' => 'BIO', 'stream_id' => $sciStream?->id],
                ['code' => 'HMT', 'stream_id' => $sciStream?->id],
                ['code' => 'HIS', 'stream_id' => $humStream?->id],
                ['code' => 'GEO', 'stream_id' => $humStream?->id],
            ] as $entry) {
                $subject = $subjectMap->get($entry['code']);
                ExamDefinition::query()->updateOrCreate(
                    ['code' => "{$className}-{$entry['code']}-PRETEST-{$year}"],
                    [
                        'title' => "Class {$className} Pre-Test {$year} — {$subject?->name}",
                        'assessment_type' => 'pretest',
                        'term' => "{$year}-T1",
                        'total_marks' => 100,
                        'exam_date' => "{$year}-04-30",
                        'class_name' => $className,
                        'section' => null,
                        'department_id' => $subject?->department_id,
                        'subject_id' => $subject?->id,
                        'is_active' => true,
                    ]
                );
            }
        }

        // Class 12 sections
        foreach (['A', 'B'] as $section) {
            ClassSection::query()->updateOrCreate(
                ['class_name' => '12', 'section' => $section],
                [
                    'department_id' => $science->id,
                    'capacity' => 40,
                    'class_level' => 12,
                    'stream_id' => $sciStream?->id,
                    'is_active' => true,
                ]
            );
        }

        // Ensure class_level is set on existing sections
        ClassSection::query()->whereIn('class_name', ['4', '5', '6', '7', '8', '9', '10'])
            ->get()
            ->each(function (ClassSection $cs) {
                $cs->update(['class_level' => (int) $cs->class_name]);
            });
    }
}

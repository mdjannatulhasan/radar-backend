<?php

namespace Database\Seeders;

use App\Models\Pps\TeacherAssignment;
use App\Models\User;
use Illuminate\Database\Seeder;

class PpsTeacherAssignmentSeeder extends Seeder
{
    public function run(): void
    {
        $matrix = [
            'teacher.math@pps.local' => [
                ['class_name' => '8', 'section' => 'A', 'subject' => 'Mathematics', 'is_class_teacher' => true],
                ['class_name' => '9', 'section' => 'A', 'subject' => 'Mathematics', 'is_class_teacher' => false],
                ['class_name' => '10', 'section' => 'A', 'subject' => 'Mathematics', 'is_class_teacher' => false],
            ],
            'teacher.english@pps.local' => [
                ['class_name' => '7', 'section' => 'B', 'subject' => 'English', 'is_class_teacher' => true],
                ['class_name' => '8', 'section' => 'A', 'subject' => 'English', 'is_class_teacher' => false],
                ['class_name' => '10', 'section' => 'A', 'subject' => 'English', 'is_class_teacher' => false],
            ],
            'teacher.science@pps.local' => [
                ['class_name' => '6', 'section' => 'A', 'subject' => 'Science', 'is_class_teacher' => true],
                ['class_name' => '8', 'section' => 'A', 'subject' => 'Science', 'is_class_teacher' => false],
                ['class_name' => '10', 'section' => 'A', 'subject' => 'Science', 'is_class_teacher' => false],
            ],
        ];

        foreach ($matrix as $email => $assignments) {
            $teacher = User::query()->where('email', $email)->first();

            if (! $teacher) {
                continue;
            }

            foreach ($assignments as $assignment) {
                TeacherAssignment::query()->updateOrCreate(
                    [
                        'teacher_id' => $teacher->id,
                        'class_name' => $assignment['class_name'],
                        'section' => $assignment['section'],
                        'subject' => $assignment['subject'],
                    ],
                    [
                        'is_class_teacher' => $assignment['is_class_teacher'],
                    ]
                );
            }
        }
    }
}

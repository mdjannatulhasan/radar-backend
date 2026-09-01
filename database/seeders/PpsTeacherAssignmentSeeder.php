<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Pps\TeacherAssignment;
use Illuminate\Database\Seeder;
use SmsCore\Models\User;

/**
 * Who teaches what, where.
 *
 * pps_teacher_assignments no longer stores (teacher_id -> users, class_name,
 * section, subject) strings. It is now (teacher_id -> teachers, section_id ->
 * sections, subject_id -> subjects), so each row below resolves through the
 * taxonomy PpsAdministrationSeeder builds. A login with no teachers row gets
 * one created for it — that record, not the user id, is what an assignment
 * points at.
 */
class PpsTeacherAssignmentSeeder extends Seeder
{
    public function run(): void
    {
        $matrix = [
            'teacher.math@pps.local' => [
                ['class_name' => '8', 'section' => 'A', 'subject' => 'MTH', 'is_class_teacher' => true],
                ['class_name' => '9', 'section' => 'A', 'subject' => 'MTH', 'is_class_teacher' => false],
                ['class_name' => '10', 'section' => 'A', 'subject' => 'MTH', 'is_class_teacher' => false],
            ],
            'teacher.english@pps.local' => [
                ['class_name' => '7', 'section' => 'B', 'subject' => 'ENG', 'is_class_teacher' => true],
                ['class_name' => '8', 'section' => 'A', 'subject' => 'ENG', 'is_class_teacher' => false],
                ['class_name' => '10', 'section' => 'A', 'subject' => 'ENG', 'is_class_teacher' => false],
            ],
            'teacher.science@pps.local' => [
                ['class_name' => '6', 'section' => 'A', 'subject' => 'SCIENCE', 'is_class_teacher' => true],
                ['class_name' => '8', 'section' => 'A', 'subject' => 'SCIENCE', 'is_class_teacher' => false],
                ['class_name' => '10', 'section' => 'A', 'subject' => 'SCIENCE', 'is_class_teacher' => false],
            ],
        ];

        // Standalone-safe: this seeder is not called from DatabaseSeeder, so it
        // must be able to build the taxonomy and catalogue it resolves against.
        foreach (PpsAdministrationSeeder::CORE_SUBJECTS as $subject) {
            PpsAdministrationSeeder::subject($subject['short'], $subject['full']);
        }

        $schoolId = PpsAdministrationSeeder::school()->id;

        foreach ($matrix as $email => $assignments) {
            $user = User::query()->where('email', $email)->first();

            if (! $user) {
                continue;
            }

            $teacher = PpsAdministrationSeeder::teacherFor($user);

            foreach ($assignments as $assignment) {
                $subject = PpsAdministrationSeeder::findSubject($assignment['subject']);

                TeacherAssignment::query()->updateOrCreate(
                    [
                        'teacher_id' => $teacher->id,
                        'section_id' => PpsAdministrationSeeder::section(
                            $assignment['class_name'],
                            $assignment['section'],
                        )->id,
                        'subject_id' => $subject?->id,
                    ],
                    [
                        'school_id' => $schoolId,
                        'is_class_teacher' => $assignment['is_class_teacher'],
                    ]
                );
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Pps\TeacherAssignment;
use Illuminate\Database\Seeder;
use SmsCore\Models\Section;
use SmsCore\Models\Teacher;

/**
 * Who teaches what, where.
 *
 * Was: a hand-written matrix of five invented teachers against classes 6-10,
 * sections A/B and five invented subject codes. All three of those are real
 * now — 159 imported teachers, 98 real sections, 137 real subjects — so the
 * matrix is gone and assignments are dealt from the actual rows.
 *
 * pps_teacher_assignments.teacher_id points at `teachers`, not at `users`: a
 * teacher is a person on staff, distinct from their login, and 10 of the 159
 * imported teachers have no account at all.
 *
 * Where the import already knows a section's class teacher
 * (sections.class_teacher_id — set for 60 of the 98), that teacher is the one
 * marked is_class_teacher rather than whichever teacher the round-robin
 * happened to land on.
 */
class PpsTeacherAssignmentSeeder extends Seeder
{
    /** Subjects assigned per section. */
    private const SUBJECTS_PER_SECTION = 5;

    public function run(): void
    {
        // Imported staff only. PpsDemoSeeder adds its own "RADAR Demo Teacher"
        // afterwards; letting that row into the pool would change the modulus
        // on the next run, shift every teacher one place, and leave the
        // previous run's assignments behind as orphans instead of updating
        // them. Excluding it keeps the deal-out stable across re-seeds.
        $teachers = Teacher::query()
            ->where('school_id', PpsAdministrationSeeder::school()->id)
            ->where('is_active', true)
            ->whereDoesntHave('user', fn ($q) => $q->where(
                'email', 'like', '%@'.PpsAdministrationSeeder::DEMO_DOMAIN
            ))
            ->orderBy('id')
            ->get();

        $sections = PpsAdministrationSeeder::demoSections();

        if ($teachers->isEmpty()) {
            throw new \RuntimeException(
                'No teachers in this tenant. Run sms:import:otoroutine before seeding assignments.'
            );
        }

        $teacherCount = $teachers->count();
        $created = 0;

        foreach ($sections->values() as $i => $section) {
            /** @var Section $section */
            $classLevel = $section->classLevel;

            if ($classLevel === null) {
                continue;
            }

            $subjects = PpsAdministrationSeeder::examinableSubjects(
                $classLevel,
                self::SUBJECTS_PER_SECTION,
            );

            foreach ($subjects->values() as $j => $subject) {
                // Offsetting by the section index as well as the subject index
                // spreads the 159 teachers over the sections instead of giving
                // every section the same first five.
                $teacher = $teachers[($i * self::SUBJECTS_PER_SECTION + $j) % $teacherCount];

                // The section's real class teacher keeps the flag if they are
                // assigned here at all; otherwise the first subject's teacher
                // stands in, so every section has exactly one.
                $isClassTeacher = $section->class_teacher_id !== null
                    ? $section->class_teacher_id === $teacher->id
                    : $j === 0;

                TeacherAssignment::query()->updateOrCreate(
                    [
                        'teacher_id' => $teacher->id,
                        'section_id' => $section->id,
                        'subject_id' => $subject->id,
                    ],
                    [
                        'school_id' => $section->school_id,
                        'is_class_teacher' => $isClassTeacher,
                    ],
                );

                $created++;
            }

            // A section whose real class teacher teaches none of its subjects
            // would otherwise have no class teacher at all. Give them the first
            // subject so the teacher workspace has an owner for the section.
            if ($section->class_teacher_id !== null
                && $subjects->isNotEmpty()
                && ! TeacherAssignment::query()
                    ->where('section_id', $section->id)
                    ->where('is_class_teacher', true)
                    ->exists()
            ) {
                TeacherAssignment::query()->updateOrCreate(
                    [
                        'teacher_id' => $section->class_teacher_id,
                        'section_id' => $section->id,
                        'subject_id' => $subjects->first()->id,
                    ],
                    [
                        'school_id' => $section->school_id,
                        'is_class_teacher' => true,
                    ],
                );

                $created++;
            }
        }

        $this->command?->info(
            "Seeded {$created} teacher assignments across {$sections->count()} sections."
        );
    }
}

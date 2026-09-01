<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Pps\Exam;
use App\Models\Pps\ExamClassMap;
use App\Models\Pps\ExamComponent;
use App\Models\Pps\ExamType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use SmsCore\Models\AcademicYear;
use SmsCore\Models\ClassLevel;
use SmsCore\Models\Level;
use SmsCore\Models\School;
use SmsCore\Models\Section;
use SmsCore\Models\SectionName;
use SmsCore\Models\Student;
use SmsCore\Models\StudentEnrollment;
use SmsCore\Models\Subject;
use SmsCore\Models\Teacher;
use SmsCore\Models\User;
use SmsCore\Models\Version;

/**
 * Builds the shared sms-core taxonomy every other RADAR seeder hangs off:
 * school -> levels x versions -> class_levels -> sections, plus the academic
 * year, the subject catalogue and the mid-term exams.
 *
 * The old single-tenant tables this replaced are gone: pps_departments and
 * pps_streams were duplicates of each other and are now class_levels.group;
 * pps_classes / pps_sections / pps_class_configs are class_levels /
 * section_names / sections.
 *
 * The static resolvers are the seeder equivalent of tests/Support/TaxonomyFixtures:
 * every one is firstOrCreate, so any seeder may call them in any order and run
 * standalone. They are static rather than instance methods precisely so the
 * other seeders can reuse them without a second copy of the chain.
 */
class PpsAdministrationSeeder extends Seeder
{
    public const SCHOOL_NAME = 'PPS Demo School';

    public const SCHOOL_SLUG = 'pps-demo';

    public const LEVEL_SCHOOL = 'School';

    public const LEVEL_COLLEGE = 'College';

    public const VERSION_BANGLA = 'Bangla';

    /**
     * Every class RADAR's demo data knows about.
     *
     * `group` carries what pps_departments / pps_streams used to: classes 9 and
     * 10 were tagged Science by the old ClassSection seed, and class 12 is the
     * College-level science stream. Classes below 9 had no real stream — the
     * old "General" department was a placeholder, and the column is nullable.
     *
     * numeric_order follows the sms-core convention: Nursery=0, KG=1, Class 1=2
     * … Class 12=13, so class N sorts at N+1.
     *
     * @var array<string, array{level:string, group:?string, numeric_order:int}>
     */
    public const CLASS_LEVELS = [
        '4' => ['level' => self::LEVEL_SCHOOL, 'group' => null, 'numeric_order' => 5],
        '5' => ['level' => self::LEVEL_SCHOOL, 'group' => null, 'numeric_order' => 6],
        '6' => ['level' => self::LEVEL_SCHOOL, 'group' => null, 'numeric_order' => 7],
        '7' => ['level' => self::LEVEL_SCHOOL, 'group' => null, 'numeric_order' => 8],
        '8' => ['level' => self::LEVEL_SCHOOL, 'group' => null, 'numeric_order' => 9],
        '9' => ['level' => self::LEVEL_SCHOOL, 'group' => 'science', 'numeric_order' => 10],
        '10' => ['level' => self::LEVEL_SCHOOL, 'group' => 'science', 'numeric_order' => 11],
        '12' => ['level' => self::LEVEL_COLLEGE, 'group' => 'science', 'numeric_order' => 13],
    ];

    /** The classes that actually have taught sections, and their section names. */
    public const SECTIONED_CLASSES = ['6', '7', '8', '9', '10'];

    public const SECTION_NAMES = ['A', 'B'];

    /**
     * The core subject catalogue (was pps_subjects, minus department_id).
     *
     * @var array<int, array{short:string, full:string}>
     */
    public const CORE_SUBJECTS = [
        ['short' => 'BAN', 'full' => 'Bangla'],
        ['short' => 'ENG', 'full' => 'English'],
        ['short' => 'MTH', 'full' => 'Mathematics'],
        ['short' => 'SCIENCE', 'full' => 'Science'],
        ['short' => 'SOC', 'full' => 'Social Studies'],
    ];

    public function run(): void
    {
        $school = self::school();

        self::academicYear();

        $superadmin = User::query()->firstOrCreate(['email' => 'superadmin@pps.local'], [
            'school_id' => $school->id,
            'name' => 'RADAR Super Admin',
            'role' => 'superadmin',
            'password' => Hash::make(PpsDemoSeeder::DEMO_PASSWORD),
        ]);

        // Classes 6–10, sections A and B — what pps_class_sections / pps_classes /
        // pps_sections / pps_class_configs together used to express.
        foreach (self::SECTIONED_CLASSES as $className) {
            foreach (self::SECTION_NAMES as $sectionName) {
                self::section($className, $sectionName);
            }
        }

        foreach (self::CORE_SUBJECTS as $subject) {
            self::subject($subject['short'], $subject['full'], self::LEVEL_SCHOOL);
        }

        $this->seedMidTermExams($superadmin);
    }

    // ── Taxonomy resolvers ─────────────────────────────────────────────────────

    public static function school(): School
    {
        return School::query()->firstOrCreate(
            ['slug' => self::SCHOOL_SLUG],
            ['name' => self::SCHOOL_NAME],
        );
    }

    public static function academicYear(): AcademicYear
    {
        $year = (string) now()->year;

        return AcademicYear::query()->firstOrCreate(
            ['school_id' => self::school()->id, 'name' => $year],
            [
                'start_date' => $year.'-01-01',
                'end_date' => $year.'-12-31',
                'is_current' => true,
            ],
        );
    }

    public static function level(string $name): Level
    {
        return Level::query()->firstOrCreate(
            ['school_id' => self::school()->id, 'name' => $name],
            ['sort_order' => $name === self::LEVEL_SCHOOL ? 1 : 2],
        );
    }

    public static function version(string $name = self::VERSION_BANGLA): Version
    {
        return Version::query()->firstOrCreate(
            ['school_id' => self::school()->id, 'name' => $name],
            ['sort_order' => $name === self::VERSION_BANGLA ? 1 : 2],
        );
    }

    /** A class as taught: name + level + version, carrying its group. */
    public static function classLevel(string $name): ClassLevel
    {
        $spec = self::CLASS_LEVELS[$name] ?? ['level' => self::LEVEL_SCHOOL, 'group' => null, 'numeric_order' => null];

        return ClassLevel::query()->firstOrCreate(
            [
                'school_id' => self::school()->id,
                'level_id' => self::level($spec['level'])->id,
                'version_id' => self::version()->id,
                'name' => $name,
            ],
            [
                'group' => $spec['group'],
                'numeric_order' => $spec['numeric_order'],
                'is_active' => true,
            ],
        );
    }

    public static function sectionName(string $name): SectionName
    {
        return SectionName::query()->firstOrCreate(
            ['school_id' => self::school()->id, 'name' => $name],
            ['sort_order' => ord($name) - ord('A')],
        );
    }

    /** The concrete class+section a student sits in, e.g. ("8", "A"). */
    public static function section(string $className, string $sectionName): Section
    {
        return Section::query()->firstOrCreate(
            [
                'school_id' => self::school()->id,
                'class_level_id' => self::classLevel($className)->id,
                'section_name_id' => self::sectionName($sectionName)->id,
            ],
            ['capacity' => 45, 'is_active' => true],
        );
    }

    /** subjects has no `name` and no `department_id`: full_name + short_name, scoped by level. */
    public static function subject(string $shortName, string $fullName, string $level = self::LEVEL_SCHOOL): Subject
    {
        return Subject::query()->firstOrCreate(
            [
                'school_id' => self::school()->id,
                'level_id' => self::level($level)->id,
                'version_id' => self::version()->id,
                'short_name' => $shortName,
            ],
            ['full_name' => $fullName, 'is_active' => true],
        );
    }

    public static function findSubject(string $shortName): ?Subject
    {
        return Subject::query()
            ->where('school_id', self::school()->id)
            ->where('short_name', $shortName)
            ->first();
    }

    /**
     * The staff record behind a login. users.id is NOT a teacher id any more —
     * pps_teacher_assignments and pps_classroom_ratings both point at teachers.
     */
    public static function teacherFor(User $user): Teacher
    {
        return Teacher::query()->firstOrCreate(
            ['user_id' => $user->id],
            [
                'school_id' => $user->school_id ?? self::school()->id,
                'full_name' => $user->name,
                'is_active' => true,
            ],
        );
    }

    /** Put a student in a class+section for the current year. */
    public static function enroll(Student $student, string $className, string $sectionName, ?int $rollNumber = null): StudentEnrollment
    {
        return StudentEnrollment::query()->updateOrCreate(
            [
                'student_id' => $student->id,
                'academic_year_id' => self::academicYear()->id,
            ],
            [
                'school_id' => self::school()->id,
                'section_id' => self::section($className, $sectionName)->id,
                'roll_number' => $rollNumber,
                'status' => 'active',
            ],
        );
    }

    /**
     * An exam scoped to one class, with a single 100-mark written component and
     * one class-map row per subject. This is the shape the old
     * pps_exam_definitions rows (one per class x subject, total_marks 100)
     * translate into: the class x subject pair moved out of the exam row and
     * into pps_exam_class_map.
     *
     * @param  array<int, string>  $subjectShortNames
     */
    public static function examForClass(
        ExamType $type,
        string $className,
        string $title,
        int $term,
        string $examDate,
        array $subjectShortNames,
        ?int $createdBy = null,
        float $totalMarks = 100.0,
    ): Exam {
        $year = (int) now()->year;

        $exam = Exam::query()->updateOrCreate(
            ['title' => $title, 'academic_year' => $year],
            [
                'exam_type_id' => $type->id,
                'term' => $term,
                'exam_date' => $examDate,
                'scope' => 'class',
                'status' => 'published',
                'created_by' => $createdBy,
                'is_active' => true,
            ],
        );

        ExamComponent::query()->updateOrCreate(
            ['exam_id' => $exam->id, 'code' => 'WRITTEN'],
            [
                'name' => 'Written',
                'max_raw_marks' => $totalMarks,
                'max_contribution' => $totalMarks,
                'sort_order' => 1,
            ],
        );

        $classLevelId = self::classLevel($className)->id;

        foreach ($subjectShortNames as $shortName) {
            $subject = self::findSubject($shortName);

            if ($subject === null) {
                continue;
            }

            ExamClassMap::query()->updateOrCreate([
                'exam_id' => $exam->id,
                'class_level_id' => $classLevelId,
                'section_id' => null,
                'subject_id' => $subject->id,
            ], []);
        }

        return $exam;
    }

    public static function examType(string $code, string $name, bool $isTerminal): ExamType
    {
        return ExamType::query()->updateOrCreate(
            ['code' => $code],
            ['name' => $name, 'is_terminal' => $isTerminal, 'is_system' => true, 'is_active' => true],
        );
    }

    // ── Mid-term exams ─────────────────────────────────────────────────────────

    private function seedMidTermExams(User $superadmin): void
    {
        $type = self::examType('mid_term', 'Mid Term', false);
        $examDate = now()->startOfMonth()->addDays(18)->toDateString();
        $subjects = array_column(self::CORE_SUBJECTS, 'short');

        foreach (self::SECTIONED_CLASSES as $className) {
            self::examForClass(
                $type,
                $className,
                "Mid Term — Class {$className}",
                1,
                $examDate,
                $subjects,
                $superadmin->id,
            );
        }
    }
}

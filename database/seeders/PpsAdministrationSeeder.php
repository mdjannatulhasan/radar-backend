<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Pps\Exam;
use App\Models\Pps\ExamClassMap;
use App\Models\Pps\ExamComponent;
use App\Models\Pps\ExamType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use SmsCore\Models\AcademicYear;
use SmsCore\Models\ClassLevel;
use SmsCore\Models\School;
use SmsCore\Models\Section;
use SmsCore\Models\Student;
use SmsCore\Models\StudentEnrollment;
use SmsCore\Models\Subject;
use SmsCore\Models\Teacher;
use SmsCore\Models\User;
use SmsCore\Support\TeacherShortCode;

/**
 * Resolves the real school structure the RADAR demo data hangs off, and adds
 * the few rows a demo needs that a real import cannot supply.
 *
 * This seeder used to CREATE a synthetic taxonomy — a "PPS Demo School" with
 * classes 6-10 and sections A/B. It no longer does. The taxonomy is now real:
 * `sms:import:otoroutine` brings in Cantonment Public School & College's own
 * levels, versions, class_levels, section_names, sections, subjects, teachers
 * and designations, and this seeder DISCOVERS them. Creating a second school
 * beside the imported one is exactly the bug this rewrite exists to prevent,
 * so every resolver below reads; none of them invents structure.
 *
 * What it does still create is the part of a demo that no source system holds:
 * the role logins RADAR needs to show its permission model (the import carries
 * only `admin` and `teacher` accounts, and no superadmin at all), and a set of
 * exams with multiple weighted components so the marks grid has something to
 * render.
 */
class PpsAdministrationSeeder extends Seeder
{
    /**
     * Password for every account this seeder creates. DEV/DEMO ONLY — these
     * accounts exist to demonstrate the product, are documented in the README
     * of the seed run, and must never be created in a production tenant.
     */
    public const DEMO_PASSWORD = 'PpsDemo2026!';

    /** Domain for accounts this seeder owns, so they are distinguishable from imported staff. */
    public const DEMO_DOMAIN = 'radar.local';

    /**
     * How many sections of each class get a demo cohort.
     *
     * One per class, so every class in every version and group has students and
     * no filter combination in the UI lands on an empty roster; a second one
     * from Class 9 up (numeric_order 10+), because that is where RADAR's risk
     * engine, exam components and counselling flows actually earn their keep,
     * and because a same-class/different-section comparison needs two.
     */
    public const SECTIONS_PER_CLASS = 1;

    public const SECTIONS_PER_SENIOR_CLASS = 2;

    /** numeric_order of Class 9 — Nursery=0, KG=1, Class 1=2 … Class 12=13. */
    public const SENIOR_FROM_NUMERIC_ORDER = 10;

    /** Examinable subjects per class. */
    public const SUBJECTS_PER_EXAM = 6;

    /**
     * Subjects that exist on the timetable but are never sat as an exam. The
     * imported catalogue mixes both — "Library", "Disposal" and "Games Class"
     * are periods, not papers — and putting marks against them would produce a
     * marks grid no teacher would recognise.
     *
     * @var array<int, string>
     */
    private const NON_EXAMINABLE_SHORT_NAMES = [
        'Art', 'CoCu', 'Games', 'Project', 'Lib', 'Disp', 'PEd', 'Club', 'CEd',
    ];

    /**
     * The weighted components every demo exam is broken into. Sums to 100, and
     * gives the marks grid more than one column to render — the whole point of
     * pps_exam_components over the old single total_marks field.
     *
     * @var array<int, array{code:string, name:string, max:float}>
     */
    private const EXAM_COMPONENTS = [
        ['code' => 'WRITTEN', 'name' => 'Written', 'max' => 70.0],
        ['code' => 'MCQ', 'name' => 'MCQ', 'max' => 20.0],
        ['code' => 'CA', 'name' => 'Continuous Assessment', 'max' => 10.0],
    ];

    private static ?School $schoolCache = null;

    private static ?AcademicYear $yearCache = null;

    private static ?Collection $demoSectionCache = null;

    private static ?string $passwordHashCache = null;

    public function run(): void
    {
        $school = self::school();
        $year = self::academicYear();

        $this->command?->info(sprintf(
            'Seeding RADAR demo data into "%s" (academic year %s).',
            $school->name,
            $year->name,
        ));

        $sections = self::demoSections();

        $this->command?->info(sprintf(
            'Demo cohort will cover %d of %d active sections across %d classes.',
            $sections->count(),
            Section::query()->where('is_active', true)->count(),
            $sections->pluck('class_level_id')->unique()->count(),
        ));

        // Exams are PpsExamSeeder's job; it runs next and uses the resolvers below.
    }

    // ── Discovery ──────────────────────────────────────────────────────────────

    /**
     * The one school in this tenant. sms-core is schema-per-tenant, so a tenant
     * holds exactly one school; taking the lowest id is deterministic rather
     * than arbitrary.
     */
    public static function school(): School
    {
        if (self::$schoolCache !== null) {
            return self::$schoolCache;
        }

        $school = School::query()->orderBy('id')->first();

        if ($school === null) {
            throw new \RuntimeException(
                'No school in this tenant. Run: php artisan tenants:run sms:import:otoroutine --tenants=<slug>'
            );
        }

        return self::$schoolCache = $school;
    }

    /** The academic year enrollments are written against. */
    public static function academicYear(): AcademicYear
    {
        if (self::$yearCache !== null) {
            return self::$yearCache;
        }

        $year = AcademicYear::query()
            ->where('school_id', self::school()->id)
            ->orderByDesc('is_current')
            ->orderByDesc('start_date')
            ->first();

        if ($year === null) {
            throw new \RuntimeException(
                'No academic year in this tenant. Run sms:import:otoroutine before seeding demo data.'
            );
        }

        return self::$yearCache = $year;
    }

    /**
     * The sections the demo cohort is seeded into.
     *
     * Deterministically ordered — class by numeric_order, then section by its
     * name's sort_order — so two seed runs pick the same sections and
     * updateOrCreate stays idempotent.
     *
     * @return Collection<int, Section>
     */
    public static function demoSections(): Collection
    {
        if (self::$demoSectionCache !== null) {
            return self::$demoSectionCache;
        }

        $all = Section::query()
            ->where('school_id', self::school()->id)
            ->where('is_active', true)
            ->with(['classLevel.level', 'classLevel.version', 'sectionName'])
            ->get();

        if ($all->isEmpty()) {
            throw new \RuntimeException(
                'No sections found. Run sms:import:otoroutine before seeding demo data.'
            );
        }

        $picked = $all
            ->sortBy(fn (Section $s) => [
                $s->classLevel?->numeric_order ?? 999,
                $s->classLevel?->version?->name ?? '',
                $s->classLevel?->group ?? '',
                $s->sectionName?->sort_order ?? 999,
                $s->id,
            ])
            ->groupBy('class_level_id')
            ->map(function (Collection $sections): Collection {
                $order = $sections->first()->classLevel?->numeric_order;

                $take = $order !== null && $order >= self::SENIOR_FROM_NUMERIC_ORDER
                    ? self::SECTIONS_PER_SENIOR_CLASS
                    : self::SECTIONS_PER_CLASS;

                return $sections->take($take);
            })
            ->flatten()
            ->values();

        return self::$demoSectionCache = $picked;
    }

    /**
     * The subjects a class actually sits exams in.
     *
     * subjects carries level + version but no class, so every class in a level
     * shares a pool; the non-examinable periods are filtered out and the first
     * few taken, deterministically by id.
     *
     * @return Collection<int, Subject>
     */
    public static function examinableSubjects(ClassLevel $classLevel, int $limit = self::SUBJECTS_PER_EXAM): Collection
    {
        return Subject::query()
            ->where('school_id', self::school()->id)
            ->where('level_id', $classLevel->level_id)
            ->where('version_id', $classLevel->version_id)
            ->where('is_active', true)
            ->whereNotIn('short_name', self::NON_EXAMINABLE_SHORT_NAMES)
            ->where('full_name', 'not like', '%(Lab)%')
            ->orderBy('id')
            ->take($limit)
            ->get();
    }

    /**
     * Real teachers, preferring those already linked to a login — the demo
     * writes users.id into pps_attendance.marked_by and pps_marks.entered_by,
     * so a teacher without an account cannot be the one who entered a mark.
     *
     * @return Collection<int, Teacher>
     */
    public static function realTeachers(int $limit = 12): Collection
    {
        return Teacher::query()
            ->where('school_id', self::school()->id)
            ->where('is_active', true)
            ->whereNotNull('user_id')
            ->with('user')
            ->orderBy('id')
            ->take($limit)
            ->get()
            ->filter(fn (Teacher $t) => $t->user !== null)
            ->values();
    }

    // ── Demo accounts ──────────────────────────────────────────────────────────

    /**
     * A login for one RADAR role.
     *
     * The imported staff carry only `admin` and `teacher`, and the source's only
     * super_admin was Autoroutine's own platform account (school_id NULL),
     * correctly excluded from the tenant. Without these there is no way into the
     * app at all, let alone a way to show the permission model.
     */
    public static function demoUser(string $role, string $name, ?string $email = null): User
    {
        $email ??= $role.'@'.self::DEMO_DOMAIN;

        $user = User::query()->firstOrNew(['email' => $email]);

        $user->fill([
            'school_id' => self::school()->id,
            'name' => $name,
            'role' => $role,
            'password' => self::demoPasswordHash(),
        ]);

        if ($user->isDirty() || ! $user->exists) {
            $user->save();
        }

        return $user;
    }

    /**
     * The demo password, hashed once.
     *
     * Every account this seeder creates shares one password by design, so they
     * share one hash too: bcrypt at the configured cost is ~250ms, and hashing
     * it per row would put minutes of pure CPU into a seed that is otherwise
     * I/O bound. The shared salt leaks nothing that the shared plaintext does
     * not already leak. DEV/DEMO ONLY.
     */
    public static function demoPasswordHash(): string
    {
        return self::$passwordHashCache ??= Hash::make(self::DEMO_PASSWORD);
    }

    /**
     * The staff record behind a login; users.id is not a teacher id.
     *
     * teachers.short_code is NOT NULL — this seeder creating a teacher without
     * one is the single reason the column used to be nullable. The code is
     * derived from the name the same way the backfill migration derives it, and
     * de-duplicated against the 159 imported CPSCS staff.
     */
    public static function teacherFor(User $user): Teacher
    {
        $schoolId = $user->school_id ?? self::school()->id;

        $existing = Teacher::query()->where('user_id', $user->id)->first();

        if ($existing !== null) {
            // Re-run over a tenant seeded before short_code was required.
            if ((string) $existing->short_code === '') {
                $existing->update(['short_code' => self::deriveShortCode($user->name, $schoolId)]);
            }

            return $existing;
        }

        return Teacher::query()->create([
            'user_id' => $user->id,
            'school_id' => $schoolId,
            'full_name' => $user->name,
            'short_code' => self::deriveShortCode($user->name, $schoolId),
            'is_active' => true,
        ]);
    }

    /** A short code for $name that no other teacher in $schoolId already holds. */
    private static function deriveShortCode(string $name, int $schoolId): string
    {
        return TeacherShortCode::unique(
            $name,
            Teacher::query()->where('school_id', $schoolId)->pluck('short_code')->all(),
        );
    }

    /** Put a student in a real section for the current academic year. */
    public static function enroll(Student $student, Section $section, ?int $rollNumber = null): StudentEnrollment
    {
        return StudentEnrollment::query()->updateOrCreate(
            [
                'student_id' => $student->id,
                'academic_year_id' => self::academicYear()->id,
            ],
            [
                'school_id' => $section->school_id,
                'section_id' => $section->id,
                'roll_number' => $rollNumber,
                'status' => 'active',
            ],
        );
    }

    public static function examType(string $code, string $name, bool $isTerminal): ExamType
    {
        return ExamType::query()->updateOrCreate(
            ['code' => $code],
            ['name' => $name, 'is_terminal' => $isTerminal, 'is_system' => true, 'is_active' => true],
        );
    }

    // ── Exams ──────────────────────────────────────────────────────────────────

    /** Class name qualified by version, so "Class 9" Bangla and English are distinct exams. */
    public static function classLabel(ClassLevel $classLevel): string
    {
        $version = $classLevel->version?->name;

        return $version === null ? $classLevel->name : "{$classLevel->name} ({$version})";
    }

    /**
     * @param  Collection<int, Subject>  $subjects
     */
    public static function examFor(
        ExamType $type,
        ClassLevel $classLevel,
        string $title,
        int $term,
        string $examDate,
        Collection $subjects,
        ?int $createdBy,
        int $academicYear,
    ): Exam {
        $exam = Exam::query()->updateOrCreate(
            ['title' => $title, 'academic_year' => $academicYear],
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

        foreach (self::EXAM_COMPONENTS as $i => $component) {
            ExamComponent::query()->updateOrCreate(
                ['exam_id' => $exam->id, 'code' => $component['code']],
                [
                    'name' => $component['name'],
                    'max_raw_marks' => $component['max'],
                    'max_contribution' => $component['max'],
                    'sort_order' => $i + 1,
                ],
            );
        }

        foreach ($subjects as $subject) {
            ExamClassMap::query()->updateOrCreate([
                'exam_id' => $exam->id,
                'class_level_id' => $classLevel->id,
                'section_id' => null,
                'subject_id' => $subject->id,
            ], []);
        }

        return $exam;
    }

    /** Test/re-run hygiene: the caches above outlive a single seeder instance. */
    public static function flushCaches(): void
    {
        self::$schoolCache = null;
        self::$yearCache = null;
        self::$demoSectionCache = null;
        self::$passwordHashCache = null;
    }
}

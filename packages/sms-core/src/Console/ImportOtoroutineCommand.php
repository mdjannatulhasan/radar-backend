<?php

declare(strict_types=1);

namespace SmsCore\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use SmsCore\Models\AcademicYear;
use SmsCore\Models\ClassLevel;
use SmsCore\Models\Designation;
use SmsCore\Models\Level;
use SmsCore\Models\School;
use SmsCore\Models\Section;
use SmsCore\Models\SectionName;
use SmsCore\Models\Subject;
use SmsCore\Models\Teacher;
use SmsCore\Models\User;
use SmsCore\Models\Version;

/**
 * One-time import of real school data from otoroutine's MySQL database into
 * the currently-initialised tenant schema.
 *
 * Idempotent: every write is a firstOrCreate/updateOrCreate keyed on natural
 * identity, so re-running reconciles rather than duplicating.
 *
 * The source connection is read-only by construction — every statement issued
 * against it in this class goes through src(), which only ever builds a
 * SELECT.
 *
 * otoroutine's `tracks` table is NOT imported. A track was exactly
 * level x version, and class_levels already carries both FKs. otoroutine's
 * routine-only columns (subjects.is_hard/is_special/placement_policy/
 * period_selector/period_index/special_teacher_mode/special_days,
 * class_levels.class_teacher_all) are skipped for the same reason: nothing in
 * RADAR consumes them, and they arrive in sms-core when Autoroutine merges.
 */
class ImportOtoroutineCommand extends Command
{
    protected $signature = 'sms:import:otoroutine
        {--source-school=1 : schools.id in the otoroutine database}
        {--connection=otoroutine : Laravel connection name for the source}';

    protected $description = 'Import levels, versions, class levels, sections, teachers, subjects and users from otoroutine.';

    private string $conn;

    private int $sourceSchoolId;

    private School $school;

    /** Source id => local id, per table. */
    private array $map = [];

    public function handle(): int
    {
        $this->conn = (string) $this->option('connection');
        $this->sourceSchoolId = (int) $this->option('source-school');

        if (! tenancy()->initialized && ! app()->runningUnitTests()) {
            $this->error('No tenant initialised. Run with --tenants=<slug> via tenants:run, or initialise tenancy first.');

            return self::FAILURE;
        }

        $src = DB::connection($this->conn);

        $sourceSchool = $src->table('schools')->where('id', $this->sourceSchoolId)->first();

        if (! $sourceSchool) {
            $this->error("School {$this->sourceSchoolId} not found on connection '{$this->conn}'.");

            return self::FAILURE;
        }

        DB::transaction(function () use ($sourceSchool): void {
            $this->importSchool($sourceSchool);
            $this->importLevelsAndVersions();
            $this->importClassLevels();
            $this->importSectionNames();
            $this->importUsers();
            $this->importDesignations();
            $this->importTeachers();
            $this->importSections();
            $this->importSubjects();
            $this->importAcademicYears();
        });

        $this->newLine();
        $this->info('Import complete:');
        $this->table(['Entity', 'Rows'], [
            ['levels', Level::count()],
            ['versions', Version::count()],
            ['class_levels', ClassLevel::count()],
            ['section_names', SectionName::count()],
            ['sections', Section::count()],
            ['designations', Designation::count()],
            ['teachers', Teacher::count()],
            ['subjects', Subject::count()],
            ['users', User::count()],
            ['academic_years', AcademicYear::count()],
        ]);

        return self::SUCCESS;
    }

    /**
     * The only way this class touches the source: a school-scoped SELECT
     * builder. Nothing here ever writes.
     */
    private function src(string $table): Builder
    {
        return DB::connection($this->conn)->table($table)->where('school_id', $this->sourceSchoolId);
    }

    private function importSchool(object $row): void
    {
        $this->school = School::firstOrCreate(
            ['slug' => $row->slug ?? 'school-'.$this->sourceSchoolId],
            ['name' => $row->name]
        );

        // Make every subsequent BelongsToSchool write land on this school.
        app()->instance('sms.school_id', $this->school->id);

        $this->line("school: {$this->school->name} (#{$this->school->id})");
    }

    private function importLevelsAndVersions(): void
    {
        foreach ($this->src('levels')->orderBy('id')->get() as $i => $row) {
            $local = Level::updateOrCreate(
                ['school_id' => $this->school->id, 'name' => $row->name],
                ['sort_order' => $i]
            );
            $this->map['levels'][$row->id] = $local->id;
        }

        foreach ($this->src('versions')->orderBy('id')->get() as $i => $row) {
            $local = Version::updateOrCreate(
                ['school_id' => $this->school->id, 'name' => $row->name],
                ['sort_order' => $i]
            );
            $this->map['versions'][$row->id] = $local->id;
        }

        $this->line('levels + versions imported');
    }

    private function importClassLevels(): void
    {
        foreach ($this->src('class_levels')->orderBy('id')->get() as $row) {
            $levelId = $this->map['levels'][$row->level_id] ?? null;
            $versionId = $this->map['versions'][$row->version_id] ?? null;

            if ($levelId === null || $versionId === null) {
                $this->warn("class_level #{$row->id} '{$row->name}' has no level/version — skipped");

                continue;
            }

            $local = ClassLevel::updateOrCreate(
                [
                    'school_id' => $this->school->id,
                    'level_id' => $levelId,
                    'version_id' => $versionId,
                    'name' => $row->name,
                ],
                [
                    'group' => $row->group,
                    'numeric_order' => $this->numericOrder($row->name),
                    'max_periods_per_day' => $row->max_periods_per_day,
                    'start_period' => $row->start_period,
                    'end_period' => $row->end_period,
                    'tiffin_period' => $row->tiffin_period,
                    'day_periods' => $this->decodeJson($row->day_periods),
                    'is_active' => true,
                ]
            );

            $this->map['class_levels'][$row->id] = $local->id;
        }

        $this->line('class_levels imported: '.count($this->map['class_levels'] ?? []));
    }

    /**
     * Sortable rank across the whole ladder: Nursery=0, KG=1, Class 1=2 …
     * Class 12=13. Group suffixes ("Class 11 (Science)") are ignored, so all
     * three Class 11 groups sort together.
     */
    private function numericOrder(string $name): ?int
    {
        $n = strtolower(trim($name));

        if (str_starts_with($n, 'nursery')) {
            return 0;
        }

        if (str_starts_with($n, 'kg') || str_starts_with($n, 'k.g')) {
            return 1;
        }

        if (preg_match('/(\d+)/', $n, $m)) {
            return ((int) $m[1]) + 1;
        }

        return null;
    }

    private function decodeJson(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function importSectionNames(): void
    {
        foreach ($this->src('section_names')->orderBy('name')->get() as $i => $row) {
            $local = SectionName::updateOrCreate(
                ['school_id' => $this->school->id, 'name' => $row->name],
                ['sort_order' => $i]
            );
            $this->map['section_names'][$row->id] = $local->id;
        }

        $this->line('section_names imported: '.count($this->map['section_names'] ?? []));
    }

    private function importUsers(): void
    {
        foreach ($this->src('users')->orderBy('id')->get() as $row) {
            // The lookup key has to be canonicalised exactly the way the model
            // canonicalises it on write: User::setEmailAttribute lowercases,
            // so a source address like `akkas9341@gmail.Com` (there is one)
            // gets STORED lowercased. Matching on the raw source value then
            // misses on a re-run — Postgres `=` is case-sensitive, unlike
            // MySQL's default collation — and the import dies on
            // users_email_unique instead of being idempotent.
            $email = mb_strtolower(trim((string) $row->email));

            $local = User::updateOrCreate(
                ['email' => $email],
                [
                    'school_id' => $this->school->id,
                    'name' => $row->name,
                    'phone' => $row->phone ?? null,
                    'role' => $this->mapRole($row->role),
                    // Password hashes carry over verbatim; both apps use bcrypt.
                    'password' => $row->password,
                    'is_active' => (bool) $row->is_active,
                ]
            );

            $this->map['users'][$row->id] = $local->id;
        }

        $this->line('users imported: '.count($this->map['users'] ?? []));
    }

    /**
     * otoroutine roles -> RADAR roles. RADAR's role strings drive
     * role_permissions and the pps.can middleware.
     */
    private function mapRole(string $role): string
    {
        return match ($role) {
            'super_admin' => 'superadmin',
            'school_admin' => 'admin',
            'teacher' => 'teacher',
            'coordinator' => 'coordinator',
            'vice_principal' => 'principal',
            'principal' => 'principal',
            default => $role,
        };
    }

    private function importDesignations(): void
    {
        foreach ($this->src('designations')->orderBy('rank')->get() as $row) {
            $local = Designation::updateOrCreate(
                ['school_id' => $this->school->id, 'title' => $row->title],
                ['rank' => $row->rank]
            );
            $this->map['designations'][$row->id] = $local->id;
        }

        $this->line('designations imported: '.count($this->map['designations'] ?? []));
    }

    private function importTeachers(): void
    {
        foreach ($this->src('teachers')->orderBy('id')->get() as $row) {
            $local = Teacher::updateOrCreate(
                [
                    'school_id' => $this->school->id,
                    'short_code' => $row->short_code,
                ],
                [
                    'full_name' => $row->full_name,
                    'designation_id' => $this->map['designations'][$row->designation_id] ?? null,
                    'contact_phone' => $row->contact_phone,
                    'contact_email' => $row->contact_email,
                    'education' => $row->education,
                    'max_weekly_periods' => $row->max_weekly_periods,
                    'is_active' => (bool) $row->is_active,
                    'user_id' => $this->map['users'][$row->user_id] ?? null,
                ]
            );

            $this->map['teachers'][$row->id] = $local->id;
        }

        $this->line('teachers imported: '.count($this->map['teachers'] ?? []));
    }

    private function importSections(): void
    {
        foreach ($this->src('sections')->orderBy('id')->get() as $row) {
            $classLevelId = $this->map['class_levels'][$row->class_level_id] ?? null;

            if ($classLevelId === null) {
                $this->warn("section #{$row->id} '{$row->name}' has no imported class_level — skipped");

                continue;
            }

            // Older otoroutine rows predate section_names; fall back to the
            // free-text name and canonicalise it on the way in.
            $sectionNameId = $this->map['section_names'][$row->section_name_id] ?? null;

            if ($sectionNameId === null) {
                $sectionNameId = SectionName::firstOrCreate([
                    'school_id' => $this->school->id,
                    'name' => $row->name,
                ])->id;
            }

            $local = Section::updateOrCreate(
                [
                    'class_level_id' => $classLevelId,
                    'section_name_id' => $sectionNameId,
                ],
                [
                    'school_id' => $this->school->id,
                    'class_teacher_id' => $this->map['teachers'][$row->class_teacher_id] ?? null,
                    'is_active' => true,
                ]
            );

            $this->map['sections'][$row->id] = $local->id;
        }

        $this->line('sections imported: '.count($this->map['sections'] ?? []));
    }

    private function importSubjects(): void
    {
        foreach ($this->src('subjects')->orderBy('id')->get() as $row) {
            $local = Subject::updateOrCreate(
                [
                    'school_id' => $this->school->id,
                    'level_id' => $this->map['levels'][$row->level_id] ?? null,
                    'version_id' => $this->map['versions'][$row->version_id] ?? null,
                    'short_name' => $row->short_name,
                ],
                [
                    'full_name' => $row->full_name,
                    'default_periods' => $row->default_periods,
                    'is_optional' => (bool) $row->is_optional,
                    'counts_as_class' => (bool) $row->counts_as_class,
                    'is_active' => true,
                ]
            );

            $this->map['subjects'][$row->id] = $local->id;
        }

        $this->line('subjects imported: '.count($this->map['subjects'] ?? []));
    }

    private function importAcademicYears(): void
    {
        foreach ($this->src('academic_years')->orderBy('id')->get() as $row) {
            $local = AcademicYear::updateOrCreate(
                ['school_id' => $this->school->id, 'name' => $row->name],
                [
                    'start_date' => $row->start_date,
                    'end_date' => $row->end_date,
                    'is_current' => (bool) $row->is_current,
                ]
            );

            $this->map['academic_years'][$row->id] = $local->id;
        }

        $this->line('academic_years imported: '.count($this->map['academic_years'] ?? []));
    }
}

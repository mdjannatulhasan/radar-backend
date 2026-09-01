<?php

declare(strict_types=1);

namespace Tests\Feature\SmsCore;

use Illuminate\Foundation\Testing\RefreshDatabase;
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
use Tests\TestCase;

class ImportOtoroutineTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Builds a miniature otoroutine source inside the `otoroutine_src` schema
     * on the same Postgres server, then points the `otoroutine` connection at
     * it. Same column names as the real MySQL source, so the importer's SQL is
     * exercised for real and CI needs no MySQL.
     *
     * All of the fixture DDL and every insert goes through the `otoroutine`
     * connection itself, never the default one. `pgsql` is in
     * $connectionsToTransact, so anything created on it is invisible to the
     * separate PDO session behind `otoroutine`; the source schema has to be
     * built by the same session that later reads it. `otoroutine` is not
     * transacted, so tearDown drops the schema explicitly.
     */
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.connections.otoroutine', array_merge(
            config('database.connections.pgsql'),
            ['search_path' => 'otoroutine_src']
        ));
        DB::purge('otoroutine');

        $src = DB::connection('otoroutine');

        $src->statement('DROP SCHEMA IF EXISTS otoroutine_src CASCADE');
        $src->statement('CREATE SCHEMA otoroutine_src');

        $src->statement('CREATE TABLE schools (id bigint primary key, name text, slug text)');
        $src->statement('CREATE TABLE levels (id bigint primary key, school_id bigint, name text)');
        $src->statement('CREATE TABLE versions (id bigint primary key, school_id bigint, name text)');
        $src->statement('CREATE TABLE class_levels (id bigint primary key, school_id bigint, level_id bigint, version_id bigint, "group" text, name text, max_periods_per_day int, start_period int, end_period int, tiffin_period int, day_periods text)');
        $src->statement('CREATE TABLE section_names (id bigint primary key, school_id bigint, name text)');
        $src->statement('CREATE TABLE sections (id bigint primary key, school_id bigint, class_level_id bigint, section_name_id bigint, name text, class_teacher_id bigint)');
        $src->statement('CREATE TABLE designations (id bigint primary key, school_id bigint, title text, rank int)');
        $src->statement('CREATE TABLE teachers (id bigint primary key, school_id bigint, full_name text, short_code text, designation_id bigint, contact_phone text, contact_email text, education text, max_weekly_periods int, is_active boolean, user_id bigint)');
        $src->statement('CREATE TABLE subjects (id bigint primary key, school_id bigint, level_id bigint, version_id bigint, full_name text, short_name text, default_periods numeric, is_optional boolean, counts_as_class boolean)');
        $src->statement('CREATE TABLE academic_years (id bigint primary key, school_id bigint, name text, start_date date, end_date date, is_current boolean)');
        $src->statement('CREATE TABLE users (id bigint primary key, school_id bigint, name text, email text, phone text, role text, password text, is_active boolean)');

        $src->table('schools')->insert(['id' => 1, 'name' => 'CPSCS Saidpur', 'slug' => 'cpscs']);
        $src->table('levels')->insert([
            ['id' => 1, 'school_id' => 1, 'name' => 'School'],
            ['id' => 2, 'school_id' => 1, 'name' => 'College'],
        ]);
        $src->table('versions')->insert([
            ['id' => 1, 'school_id' => 1, 'name' => 'Bangla'],
            ['id' => 2, 'school_id' => 1, 'name' => 'English'],
        ]);
        $src->table('class_levels')->insert([
            ['id' => 10, 'school_id' => 1, 'level_id' => 1, 'version_id' => 1, 'group' => null, 'name' => 'Class 9', 'max_periods_per_day' => 8, 'start_period' => 1, 'end_period' => 8, 'tiffin_period' => 4, 'day_periods' => null],
            ['id' => 14, 'school_id' => 1, 'level_id' => 2, 'version_id' => 1, 'group' => 'science', 'name' => 'Class 11 (Science)', 'max_periods_per_day' => 8, 'start_period' => 1, 'end_period' => 8, 'tiffin_period' => 4, 'day_periods' => null],
            // No level/version: the importer must warn and skip rather than
            // blow up on the NOT NULL FKs.
            ['id' => 99, 'school_id' => 1, 'level_id' => null, 'version_id' => null, 'group' => null, 'name' => 'Orphan Class', 'max_periods_per_day' => 8, 'start_period' => 1, 'end_period' => 8, 'tiffin_period' => 4, 'day_periods' => null],
        ]);
        $src->table('section_names')->insert([
            ['id' => 1, 'school_id' => 1, 'name' => 'A'],
            ['id' => 12, 'school_id' => 1, 'name' => 'Shapla'],
        ]);
        $src->table('sections')->insert([
            ['id' => 100, 'school_id' => 1, 'class_level_id' => 10, 'section_name_id' => 1, 'name' => 'A', 'class_teacher_id' => 500],
            ['id' => 101, 'school_id' => 1, 'class_level_id' => 14, 'section_name_id' => 12, 'name' => 'Shapla', 'class_teacher_id' => null],
            // Hangs off the skipped class_level, so it must be skipped too.
            ['id' => 102, 'school_id' => 1, 'class_level_id' => 99, 'section_name_id' => 1, 'name' => 'A', 'class_teacher_id' => null],
        ]);
        $src->table('designations')->insert(['id' => 3, 'school_id' => 1, 'title' => 'Assistant Teacher', 'rank' => 3]);
        $src->table('users')->insert([
            ['id' => 900, 'school_id' => 1, 'name' => 'Md. Zillur Rahman', 'email' => 'zillur@cpscs.test', 'phone' => '01517837838', 'role' => 'teacher', 'password' => bcrypt('secret'), 'is_active' => true],
            // Mixed case on purpose: the real source has exactly one of these,
            // and User lowercases email on write. See
            // test_import_is_idempotent_for_mixed_case_emails.
            ['id' => 901, 'school_id' => 1, 'name' => 'Md. Akkas Ali Sarker', 'email' => 'akkas@gmail.Com', 'phone' => null, 'role' => 'school_admin', 'password' => bcrypt('secret'), 'is_active' => true],
        ]);
        $src->table('teachers')->insert(['id' => 500, 'school_id' => 1, 'full_name' => 'Md. Zillur Rahman', 'short_code' => 'ZR', 'designation_id' => 3, 'contact_phone' => '01517837838', 'contact_email' => null, 'education' => null, 'max_weekly_periods' => 24, 'is_active' => true, 'user_id' => 900]);
        $src->table('subjects')->insert(['id' => 700, 'school_id' => 1, 'level_id' => 1, 'version_id' => 1, 'full_name' => 'Bangla 1st Paper', 'short_name' => 'B1', 'default_periods' => 1, 'is_optional' => false, 'counts_as_class' => true]);
        $src->table('academic_years')->insert(['id' => 1, 'school_id' => 1, 'name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'is_current' => true]);
    }

    protected function tearDown(): void
    {
        try {
            DB::connection('otoroutine')->statement('DROP SCHEMA IF EXISTS otoroutine_src CASCADE');
        } catch (\Throwable) {
            // The schema is dropped and recreated by the next setUp anyway;
            // a failure here must not mask the test's own failure.
        }

        DB::purge('otoroutine');

        parent::tearDown();
    }

    public function test_import_creates_the_full_taxonomy(): void
    {
        $this->artisan('sms:import:otoroutine', ['--source-school' => 1])
            ->assertSuccessful();

        $school = School::where('slug', 'cpscs')->firstOrFail();

        $this->assertSame('CPSCS Saidpur', $school->name);
        $this->assertSame(2, Level::count());
        $this->assertSame(2, Version::count());
        $this->assertSame(2, ClassLevel::count());
        $this->assertSame(2, Section::count());
        $this->assertSame(1, Teacher::count());
        $this->assertSame(1, Subject::count());
        $this->assertSame(2, SectionName::count());
        $this->assertSame(1, Designation::count());
        $this->assertSame(1, AcademicYear::count());
        $this->assertSame(2, User::count());
        $this->assertSame(1, User::where('email', 'zillur@cpscs.test')->count());
        // school_admin -> admin
        $this->assertSame('admin', User::where('name', 'Md. Akkas Ali Sarker')->value('role'));

        $science = ClassLevel::where('name', 'Class 11 (Science)')->firstOrFail();
        $this->assertSame('College', $science->level->name);
        $this->assertSame('Bangla', $science->version->name);
        $this->assertSame('science', $science->group);
        $this->assertSame(12, $science->numeric_order);

        $nine = ClassLevel::where('name', 'Class 9')->firstOrFail();
        $this->assertSame(10, $nine->numeric_order);
        $this->assertNull($nine->group);
    }

    public function test_import_skips_class_levels_without_a_level_or_version(): void
    {
        $this->artisan('sms:import:otoroutine', ['--source-school' => 1])
            ->expectsOutputToContain('Orphan Class')
            ->assertSuccessful();

        $this->assertFalse(ClassLevel::where('name', 'Orphan Class')->exists());
        $this->assertSame(2, Section::count());
    }

    public function test_import_links_teachers_to_their_users_and_designations(): void
    {
        $this->artisan('sms:import:otoroutine', ['--source-school' => 1])->assertSuccessful();

        $teacher = Teacher::where('short_code', 'ZR')->firstOrFail();

        $this->assertSame('Md. Zillur Rahman', $teacher->full_name);
        $this->assertSame('Assistant Teacher', $teacher->designation->title);
        $this->assertSame('zillur@cpscs.test', $teacher->user->email);
        $this->assertSame('01517837838', $teacher->contact_phone);
    }

    /**
     * Both apps hash with bcrypt, so the hash is carried across verbatim
     * rather than being re-hashed (which would invalidate every password).
     */
    public function test_import_carries_password_hashes_over_verbatim(): void
    {
        $sourceHash = DB::connection('otoroutine')->table('users')->where('id', 900)->value('password');

        $this->artisan('sms:import:otoroutine', ['--source-school' => 1])->assertSuccessful();

        $stored = DB::table('users')->where('email', 'zillur@cpscs.test')->value('password');

        $this->assertSame($sourceHash, $stored);
    }

    public function test_import_links_class_teachers_to_sections(): void
    {
        $this->artisan('sms:import:otoroutine', ['--source-school' => 1])->assertSuccessful();

        $section = Section::whereHas('sectionName', fn ($q) => $q->where('name', 'A'))->firstOrFail();

        $this->assertSame('ZR', $section->classTeacher->short_code);
    }

    public function test_import_never_writes_to_the_source(): void
    {
        $this->artisan('sms:import:otoroutine', ['--source-school' => 1])->assertSuccessful();

        $src = DB::connection('otoroutine');

        $this->assertSame(3, (int) $src->table('class_levels')->count());
        $this->assertSame(1, (int) $src->table('teachers')->count());
        $this->assertSame(2, (int) $src->table('users')->count());
        $this->assertSame('Md. Zillur Rahman', $src->table('teachers')->where('id', 500)->value('full_name'));
    }

    /**
     * Regression: User lowercases email on write, so a mixed-case source
     * address is STORED lowercased. Keying the lookup on the raw source value
     * then misses on a re-run — Postgres `=` is case-sensitive, unlike MySQL's
     * default collation — and the import dies on users_email_unique instead of
     * reconciling. Caught against the real CPSCS data, which has one such
     * address.
     */
    public function test_import_is_idempotent_for_mixed_case_emails(): void
    {
        $this->artisan('sms:import:otoroutine', ['--source-school' => 1])->assertSuccessful();
        $this->artisan('sms:import:otoroutine', ['--source-school' => 1])->assertSuccessful();

        $this->assertSame(1, User::where('email', 'akkas@gmail.com')->count());
        $this->assertSame(2, User::count());
    }

    public function test_import_is_idempotent(): void
    {
        $this->artisan('sms:import:otoroutine', ['--source-school' => 1])->assertSuccessful();

        $before = $this->snapshot();

        $this->artisan('sms:import:otoroutine', ['--source-school' => 1])->assertSuccessful();

        $this->assertSame($before, $this->snapshot());

        $this->assertSame(2, ClassLevel::count());
        $this->assertSame(1, Teacher::count());
        $this->assertSame(1, School::count());
    }

    /**
     * Every imported row, keyed by identity — so a second run that silently
     * repointed an FK or duplicated a row shows up as a diff, not just a count
     * that happens to match.
     *
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        $rows = fn (string $table, array $columns): array => DB::table($table)
            ->orderBy('id')
            ->get($columns)
            ->map(fn (object $row): array => (array) $row)
            ->all();

        return [
            'schools' => $rows('schools', ['id', 'name', 'slug']),
            'levels' => $rows('levels', ['id', 'name', 'sort_order']),
            'versions' => $rows('versions', ['id', 'name', 'sort_order']),
            'class_levels' => $rows('class_levels', ['id', 'name', 'group', 'numeric_order', 'level_id', 'version_id']),
            'section_names' => $rows('section_names', ['id', 'name']),
            'sections' => $rows('sections', ['id', 'class_level_id', 'section_name_id', 'class_teacher_id']),
            'designations' => $rows('designations', ['id', 'title', 'rank']),
            'teachers' => $rows('teachers', ['id', 'full_name', 'short_code', 'designation_id', 'user_id']),
            'subjects' => $rows('subjects', ['id', 'full_name', 'short_name', 'level_id', 'version_id']),
            'users' => $rows('users', ['id', 'name', 'email', 'role', 'password']),
            'academic_years' => $rows('academic_years', ['id', 'name', 'is_current']),
        ];
    }
}

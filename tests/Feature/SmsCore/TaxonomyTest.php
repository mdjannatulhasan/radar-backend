<?php

declare(strict_types=1);

namespace Tests\Feature\SmsCore;

use Illuminate\Foundation\Testing\RefreshDatabase;
use SmsCore\Models\ClassLevel;
use SmsCore\Models\Level;
use SmsCore\Models\School;
use SmsCore\Models\Section;
use SmsCore\Models\SectionName;
use SmsCore\Models\Version;
use Tests\TestCase;

class TaxonomyTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_section_resolves_its_full_level_and_version_chain(): void
    {
        $school  = School::create(['name' => 'CPSCS', 'slug' => 'cpscs']);
        $college = Level::create(['school_id' => $school->id, 'name' => 'College', 'sort_order' => 1]);
        $english = Version::create(['school_id' => $school->id, 'name' => 'English', 'sort_order' => 1]);

        $classLevel = ClassLevel::create([
            'school_id' => $school->id,
            'level_id' => $college->id,
            'version_id' => $english->id,
            'name' => 'Class 11 (Science)',
            'group' => 'science',
            'numeric_order' => 12,
        ]);

        $name = SectionName::create(['school_id' => $school->id, 'name' => 'Shapla']);

        $section = Section::create([
            'school_id' => $school->id,
            'class_level_id' => $classLevel->id,
            'section_name_id' => $name->id,
        ]);

        $section->load('classLevel.level', 'classLevel.version', 'sectionName');

        $this->assertSame('College', $section->classLevel->level->name);
        $this->assertSame('English', $section->classLevel->version->name);
        $this->assertSame('science', $section->classLevel->group);
        $this->assertSame('Shapla', $section->sectionName->name);
        $this->assertSame('Class 11 (Science) — Shapla', $section->display_name);
    }

    public function test_the_same_class_name_can_exist_in_both_versions(): void
    {
        $school  = School::create(['name' => 'CPSCS', 'slug' => 'cpscs']);
        $level   = Level::create(['school_id' => $school->id, 'name' => 'School']);
        $bangla  = Version::create(['school_id' => $school->id, 'name' => 'Bangla']);
        $english = Version::create(['school_id' => $school->id, 'name' => 'English']);

        $base = ['school_id' => $school->id, 'level_id' => $level->id, 'name' => 'Class 9', 'numeric_order' => 10];

        ClassLevel::create($base + ['version_id' => $bangla->id]);
        ClassLevel::create($base + ['version_id' => $english->id]);

        $this->assertSame(2, ClassLevel::where('name', 'Class 9')->count());
    }

    public function test_an_invalid_group_is_rejected_by_the_database(): void
    {
        $school = School::create(['name' => 'CPSCS', 'slug' => 'cpscs']);
        $level  = Level::create(['school_id' => $school->id, 'name' => 'College']);
        $ver    = Version::create(['school_id' => $school->id, 'name' => 'Bangla']);

        $this->expectException(\Illuminate\Database\QueryException::class);

        ClassLevel::create([
            'school_id' => $school->id,
            'level_id' => $level->id,
            'version_id' => $ver->id,
            'name' => 'Class 11 (Agriculture)',
            'group' => 'agriculture',
        ]);
    }
}

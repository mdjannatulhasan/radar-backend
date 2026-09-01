<?php

declare(strict_types=1);

namespace Tests\Feature\Pps;

use Illuminate\Foundation\Testing\RefreshDatabase;
use SmsCore\Models\ClassLevel;
use SmsCore\Models\Level;
use SmsCore\Models\School;
use SmsCore\Models\Section;
use SmsCore\Models\SectionName;
use SmsCore\Models\User;
use SmsCore\Models\Version;
use Tests\TestCase;

class ClassStructureTest extends TestCase
{
    use RefreshDatabase;

    private function seedStructure(): School
    {
        $school  = School::create(['name' => 'CPSCS', 'slug' => 'cpscs']);
        $sch     = Level::create(['school_id' => $school->id, 'name' => 'School', 'sort_order' => 0]);
        $col     = Level::create(['school_id' => $school->id, 'name' => 'College', 'sort_order' => 1]);
        $bangla  = Version::create(['school_id' => $school->id, 'name' => 'Bangla', 'sort_order' => 0]);
        $english = Version::create(['school_id' => $school->id, 'name' => 'English', 'sort_order' => 1]);

        $a      = SectionName::create(['school_id' => $school->id, 'name' => 'A']);
        $shapla = SectionName::create(['school_id' => $school->id, 'name' => 'Shapla']);

        $bv9 = ClassLevel::create(['school_id' => $school->id, 'level_id' => $sch->id, 'version_id' => $bangla->id,  'name' => 'Class 9', 'numeric_order' => 10]);
        $ev9 = ClassLevel::create(['school_id' => $school->id, 'level_id' => $sch->id, 'version_id' => $english->id, 'name' => 'Class 9', 'numeric_order' => 10]);
        $c11 = ClassLevel::create(['school_id' => $school->id, 'level_id' => $col->id, 'version_id' => $bangla->id,  'name' => 'Class 11 (Science)', 'group' => 'science', 'numeric_order' => 12]);

        Section::create(['school_id' => $school->id, 'class_level_id' => $bv9->id, 'section_name_id' => $a->id]);
        Section::create(['school_id' => $school->id, 'class_level_id' => $ev9->id, 'section_name_id' => $a->id]);
        Section::create(['school_id' => $school->id, 'class_level_id' => $c11->id, 'section_name_id' => $shapla->id]);

        return $school;
    }

    private function actingAsAdmin(School $school): self
    {
        $user = User::create([
            'school_id' => $school->id,
            'name' => 'Admin',
            'email' => 'admin@cpscs.test',
            'password' => 'secret',
            'role' => 'admin',
        ]);

        return $this->actingAs($user, 'sanctum');
    }

    public function test_structure_returns_class_levels_with_level_version_and_group(): void
    {
        $school = $this->seedStructure();

        $response = $this->actingAsAdmin($school)
            ->getJson('/api/v1/pps/classes/structure')
            ->assertOk();

        $response->assertJsonStructure([
            'levels'   => [['id', 'name']],
            'versions' => [['id', 'name']],
            'classes'  => [[
                'id', 'name', 'group', 'numeric_order',
                'level_id', 'level_name',
                'version_id', 'version_name',
                'sections' => [['id', 'name']],
            ]],
        ]);

        $this->assertCount(2, $response->json('levels'));
        $this->assertCount(2, $response->json('versions'));
        $this->assertCount(3, $response->json('classes'));
    }

    public function test_the_same_class_name_appears_once_per_version(): void
    {
        $school = $this->seedStructure();

        $classes = $this->actingAsAdmin($school)
            ->getJson('/api/v1/pps/classes/structure')
            ->assertOk()
            ->json('classes');

        $class9 = array_values(array_filter($classes, fn ($c) => $c['name'] === 'Class 9'));

        $this->assertCount(2, $class9, 'Class 9 must appear once for Bangla and once for English');
        $this->assertNotSame($class9[0]['version_name'], $class9[1]['version_name']);
    }

    public function test_structure_can_be_filtered_by_version(): void
    {
        $school = $this->seedStructure();

        $english = Version::where('name', 'English')->firstOrFail();

        $classes = $this->actingAsAdmin($school)
            ->getJson('/api/v1/pps/classes/structure?version_id='.$english->id)
            ->assertOk()
            ->json('classes');

        $this->assertCount(1, $classes);
        $this->assertSame('English', $classes[0]['version_name']);
    }

    public function test_structure_can_be_filtered_by_level(): void
    {
        $school = $this->seedStructure();

        $college = Level::where('name', 'College')->firstOrFail();

        $classes = $this->actingAsAdmin($school)
            ->getJson('/api/v1/pps/classes/structure?level_id='.$college->id)
            ->assertOk()
            ->json('classes');

        $this->assertCount(1, $classes);
        $this->assertSame('science', $classes[0]['group']);
    }
}

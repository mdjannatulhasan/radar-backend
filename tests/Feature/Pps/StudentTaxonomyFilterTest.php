<?php

declare(strict_types=1);

namespace Tests\Feature\Pps;

use Illuminate\Foundation\Testing\RefreshDatabase;
use SmsCore\Models\AcademicYear;
use SmsCore\Models\ClassLevel;
use SmsCore\Models\Level;
use SmsCore\Models\School;
use SmsCore\Models\Section;
use SmsCore\Models\SectionName;
use SmsCore\Models\Student;
use SmsCore\Models\StudentEnrollment;
use SmsCore\Models\User;
use SmsCore\Models\Version;
use Tests\TestCase;

class StudentTaxonomyFilterTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private Version $bangla;

    private Version $english;

    private Section $bvSection;

    private Section $evSection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school  = School::create(['name' => 'CPSCS', 'slug' => 'cpscs']);
        $level         = Level::create(['school_id' => $this->school->id, 'name' => 'School']);
        $this->bangla  = Version::create(['school_id' => $this->school->id, 'name' => 'Bangla']);
        $this->english = Version::create(['school_id' => $this->school->id, 'name' => 'English']);

        $year = AcademicYear::create([
            'school_id' => $this->school->id,
            'name' => '2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'is_current' => true,
        ]);

        $a = SectionName::create(['school_id' => $this->school->id, 'name' => 'A']);

        $bv9 = ClassLevel::create(['school_id' => $this->school->id, 'level_id' => $level->id, 'version_id' => $this->bangla->id,  'name' => 'Class 9', 'numeric_order' => 10]);
        $ev9 = ClassLevel::create(['school_id' => $this->school->id, 'level_id' => $level->id, 'version_id' => $this->english->id, 'name' => 'Class 9', 'numeric_order' => 10]);

        $this->bvSection = Section::create(['school_id' => $this->school->id, 'class_level_id' => $bv9->id, 'section_name_id' => $a->id]);
        $this->evSection = Section::create(['school_id' => $this->school->id, 'class_level_id' => $ev9->id, 'section_name_id' => $a->id]);

        foreach ([[$this->bvSection, 'BV'], [$this->evSection, 'EV']] as [$section, $tag]) {
            foreach (range(1, 3) as $n) {
                $student = Student::create([
                    'school_id' => $this->school->id,
                    'student_code' => "{$tag}-{$n}",
                    'name' => "{$tag} Student {$n}",
                ]);

                StudentEnrollment::create([
                    'school_id' => $this->school->id,
                    'student_id' => $student->id,
                    'academic_year_id' => $year->id,
                    'section_id' => $section->id,
                    'roll_number' => $n,
                ]);
            }
        }

        $admin = User::create([
            'school_id' => $this->school->id,
            'name' => 'Admin',
            'email' => 'admin@cpscs.test',
            'password' => 'secret',
            'role' => 'admin',
        ]);

        $this->actingAs($admin, 'sanctum');
    }

    public function test_unfiltered_index_returns_every_student(): void
    {
        $this->getJson('/api/v1/pps/students')
            ->assertOk()
            ->assertJsonCount(6, 'data');
    }

    public function test_students_can_be_filtered_by_version(): void
    {
        $data = $this->getJson('/api/v1/pps/students?version_id='.$this->english->id)
            ->assertOk()
            ->json('data');

        $this->assertCount(3, $data);

        foreach ($data as $row) {
            $this->assertStringStartsWith('EV', $row['student_code']);
        }
    }

    public function test_students_can_be_filtered_by_section(): void
    {
        $this->getJson('/api/v1/pps/students?section_id='.$this->bvSection->id)
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_each_student_row_carries_its_class_version_and_section(): void
    {
        $row = $this->getJson('/api/v1/pps/students?section_id='.$this->evSection->id)
            ->assertOk()
            ->json('data.0');

        $this->assertSame('Class 9', $row['class_name']);
        $this->assertSame('A', $row['section_name']);
        $this->assertSame('English', $row['version_name']);
        $this->assertSame('School', $row['level_name']);
    }
}

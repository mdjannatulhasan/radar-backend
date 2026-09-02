<?php

declare(strict_types=1);

namespace Tests\Feature\Pps;

use App\Models\Pps\ExamClassMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use SmsCore\Models\User;
use Tests\TestCase;

/**
 * "Class 9" exists in the Bangla version AND the English version and they are
 * different cohorts. Everything here pins that the marks surface can tell them
 * apart — the vocabulary it offers, and the roster it builds from a choice.
 */
class MarksMetaTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): self
    {
        $user = $this->createUser([
            'name' => 'Admin',
            'email' => 'admin@marks.test',
            'role' => 'admin',
            'password' => 'secret',
        ]);

        return $this->actingAs($user, 'sanctum');
    }

    public function test_meta_classes_carry_level_version_and_their_own_sections(): void
    {
        // Same name, different version: two rows that a name cannot separate.
        $this->section('Class 9', 'Shapla', 'Bangla');
        $this->section('Class 9', 'Orchid', 'English');

        $response = $this->actingAsAdmin()
            ->getJson('/api/v1/pps/marks/meta')
            ->assertOk();

        $response->assertJsonStructure([
            'classes' => [[
                'id', 'name', 'group',
                'level_id', 'level_name',
                'version_id', 'version_name', 'full_label',
                'sections' => [['id', 'name']],
            ]],
            'exams',
            'subjects',
        ]);

        $classes = collect($response->json('classes'))->where('name', 'Class 9')->values();

        $this->assertCount(2, $classes, 'Class 9 must appear once per version');
        $this->assertSame(
            ['Bangla', 'English'],
            $classes->pluck('version_name')->sort()->values()->all(),
        );
        $this->assertNotSame($classes[0]['id'], $classes[1]['id']);
        $this->assertNotSame($classes[0]['full_label'], $classes[1]['full_label']);

        // Each class offers only ITS sections, not the school-wide vocabulary.
        $sectionsByVersion = $classes->mapWithKeys(fn (array $c) => [
            $c['version_name'] => collect($c['sections'])->pluck('name')->all(),
        ]);

        $this->assertSame(['Shapla'], $sectionsByVersion['Bangla']);
        $this->assertSame(['Orchid'], $sectionsByVersion['English']);
    }

    public function test_the_flat_section_vocabulary_is_no_longer_offered(): void
    {
        $this->section('Class 9', 'Shapla', 'Bangla');

        $this->actingAsAdmin()
            ->getJson('/api/v1/pps/marks/meta')
            ->assertOk()
            ->assertJsonMissingPath('sections');
    }

    /**
     * The bug this replaces: the grid sent a `class_name` string, so "Class 9"
     * pulled both versions' cohorts into one roster. With ids the two are
     * disjoint.
     */
    public function test_marks_roster_differs_between_the_two_versions_of_one_class(): void
    {
        $banglaSection = $this->section('Class 9', 'Shapla', 'Bangla');
        $englishSection = $this->section('Class 9', 'Orchid', 'English');

        $bangla = $this->makeStudent([
            'student_code' => 'BV9-01',
            'name' => 'Bangla Version Student',
            'class_name' => 'Class 9',
            'section' => 'Shapla',
            'version' => 'Bangla',
            'roll_number' => 1,
        ]);

        $english = $this->makeStudent([
            'student_code' => 'EV9-01',
            'name' => 'English Version Student',
            'class_name' => 'Class 9',
            'section' => 'Orchid',
            'version' => 'English',
            'roll_number' => 1,
        ]);

        // A global exam, so the roster is decided by the caller's filter alone.
        $exam = $this->exam('2026-06-15');
        $exam->update(['scope' => 'global']);
        $this->examComponent($exam);
        $subject = $this->subject('Mathematics', 'MATH');

        $this->actingAsAdmin();

        $banglaRows = $this->getJson(sprintf(
            '/api/v1/pps/marks?exam_id=%d&subject_id=%d&class_level_id=%d',
            $exam->id,
            $subject->id,
            $banglaSection->class_level_id,
        ))->assertOk()->json('rows');

        $englishRows = $this->getJson(sprintf(
            '/api/v1/pps/marks?exam_id=%d&subject_id=%d&class_level_id=%d',
            $exam->id,
            $subject->id,
            $englishSection->class_level_id,
        ))->assertOk()->json('rows');

        $this->assertSame([$bangla->id], collect($banglaRows)->pluck('student_id')->all());
        $this->assertSame([$english->id], collect($englishRows)->pluck('student_id')->all());
    }

    /**
     * The caller's filter intersects the exam's scope, never widens it. The
     * same set authorises writes in bulkStore, so a class the exam does not
     * cover has to resolve to nobody.
     */
    public function test_a_class_outside_the_exams_scope_yields_an_empty_roster(): void
    {
        $inScope = $this->section('Class 9', 'Shapla', 'Bangla');
        $outOfScope = $this->section('Class 9', 'Orchid', 'English');

        $this->makeStudent([
            'student_code' => 'BV9-01', 'name' => 'In Scope',
            'class_name' => 'Class 9', 'section' => 'Shapla', 'version' => 'Bangla', 'roll_number' => 1,
        ]);
        $this->makeStudent([
            'student_code' => 'EV9-01', 'name' => 'Out Of Scope',
            'class_name' => 'Class 9', 'section' => 'Orchid', 'version' => 'English', 'roll_number' => 1,
        ]);

        $exam = $this->exam('2026-06-15');           // scope 'class'
        $this->examComponent($exam);
        $subject = $this->subject('Mathematics', 'MATH');

        ExamClassMap::create([
            'exam_id' => $exam->id,
            'class_level_id' => $inScope->class_level_id,
            'section_id' => null,
            'subject_id' => $subject->id,
        ]);

        $this->actingAsAdmin();

        $this->assertCount(1, $this->getJson(sprintf(
            '/api/v1/pps/marks?exam_id=%d&subject_id=%d&class_level_id=%d',
            $exam->id, $subject->id, $inScope->class_level_id,
        ))->assertOk()->json('rows'));

        $this->assertSame([], $this->getJson(sprintf(
            '/api/v1/pps/marks?exam_id=%d&subject_id=%d&class_level_id=%d',
            $exam->id, $subject->id, $outOfScope->class_level_id,
        ))->assertOk()->json('rows'));
    }

    public function test_section_id_narrows_the_roster_within_a_class(): void
    {
        $shapla = $this->section('Class 9', 'Shapla', 'Bangla');
        $this->section('Class 9', 'Dolon', 'Bangla');

        $inShapla = $this->makeStudent([
            'student_code' => 'BV9-SH-01', 'name' => 'Shapla Student',
            'class_name' => 'Class 9', 'section' => 'Shapla', 'version' => 'Bangla', 'roll_number' => 1,
        ]);
        $this->makeStudent([
            'student_code' => 'BV9-DO-01', 'name' => 'Dolon Student',
            'class_name' => 'Class 9', 'section' => 'Dolon', 'version' => 'Bangla', 'roll_number' => 1,
        ]);

        $exam = $this->exam('2026-06-15');
        $exam->update(['scope' => 'global']);
        $this->examComponent($exam);
        $subject = $this->subject('Mathematics', 'MATH');

        $rows = $this->actingAsAdmin()->getJson(sprintf(
            '/api/v1/pps/marks?exam_id=%d&subject_id=%d&class_level_id=%d&section_id=%d',
            $exam->id, $subject->id, $shapla->class_level_id, $shapla->id,
        ))->assertOk()->json('rows');

        $this->assertSame([$inShapla->id], collect($rows)->pluck('student_id')->all());
    }
}

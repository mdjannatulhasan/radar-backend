<?php

declare(strict_types=1);

namespace Tests\Feature\Pps;

use App\Models\Pps\Exam;
use App\Models\Pps\ExamComponent;
use App\Models\Pps\Mark;
use Illuminate\Foundation\Testing\RefreshDatabase;
use SmsCore\Models\User;
use Tests\TestCase;

/**
 * The admin exam editor writes three tables, not one.
 *
 * `pps_exams` on its own is inert: marks are entered against
 * `pps_exam_components` (pps_marks.component_id points at one) and the students
 * an exam covers come from `pps_exam_class_map`. The endpoint used to write
 * only the parent row, so an exam created through the admin UI could never
 * receive a single mark — and the UI could not reach even that, because it
 * posted the pre-refactor `assessment_type` / `total_marks` / `scopes` shape
 * and was answered 422 every time.
 *
 * The delicate half is the update path: `pps_marks.component_id` is
 * `cascadeOnDelete`, so a reconcile that dropped and recreated components would
 * take every entered mark with it.
 */
class ExamEditorTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return $this->createUser([
            'name' => 'Exam Admin',
            'email' => 'exam.admin@example.test',
            'role' => 'superadmin',
            'password' => 'secret',
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'title' => 'Half Yearly 2026',
            'exam_type_id' => $this->examType('first_term', '1st Term')->id,
            'academic_year' => 2026,
            'term' => 1,
            'exam_date' => '2026-06-15',
            'scope' => 'class',
            'status' => 'published',
            'components' => [
                ['name' => 'Written', 'code' => 'WRITTEN', 'max_raw_marks' => 70, 'max_contribution' => 70],
                ['name' => 'MCQ', 'code' => 'MCQ', 'max_raw_marks' => 20, 'max_contribution' => 20],
                ['name' => 'Continuous Assessment', 'code' => 'CA', 'max_raw_marks' => 10, 'max_contribution' => 10],
            ],
        ], $overrides);
    }

    public function test_creating_an_exam_writes_its_components_and_class_maps(): void
    {
        $shapla = $this->section('Class 9', 'Shapla', 'Bangla');
        $dolon = $this->section('Class 9', 'Dolon', 'Bangla');
        $maths = $this->subject('Mathematics', 'MATH');

        $response = $this->signInPps($this->admin())
            ->postJson('/api/v1/pps/admin/exams', $this->payload([
                'class_maps' => [
                    // A named section of the class…
                    ['class_level_id' => $shapla->class_level_id, 'section_id' => $shapla->id, 'subject_id' => $maths->id],
                    // …and "every section of that class, every subject".
                    ['class_level_id' => $dolon->class_level_id, 'section_id' => null, 'subject_id' => null],
                ],
            ]))
            ->assertCreated();

        $examId = $response->json('exam.id');

        $this->assertSame(3, ExamComponent::where('exam_id', $examId)->count());
        $this->assertSame(
            ['WRITTEN', 'MCQ', 'CA'],
            ExamComponent::where('exam_id', $examId)->orderBy('sort_order')->pluck('code')->all(),
            'sort_order must follow the submitted order, since that is the marks grid column order',
        );

        $this->assertSame(2, Exam::find($examId)->classMaps()->count());
        $this->assertDatabaseHas('pps_exam_class_map', [
            'exam_id' => $examId,
            'class_level_id' => $shapla->class_level_id,
            'section_id' => $shapla->id,
            'subject_id' => $maths->id,
        ]);
        $this->assertDatabaseHas('pps_exam_class_map', [
            'exam_id' => $examId,
            'class_level_id' => $dolon->class_level_id,
            'section_id' => null,
            'subject_id' => null,
        ]);
    }

    /**
     * The old payload — `assessment_type`, `total_marks`, `code`, `scopes[]` —
     * is a leftover of the Assessment -> Marks refactor and names nothing the
     * schema still has. It must not quietly half-succeed.
     */
    public function test_an_exam_without_components_is_refused(): void
    {
        $this->signInPps($this->admin())
            ->postJson('/api/v1/pps/admin/exams', [
                'title' => 'Inert Exam',
                'exam_type_id' => $this->examType('first_term', '1st Term')->id,
                'academic_year' => 2026,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('components');

        $this->assertSame(0, Exam::count());
    }

    /**
     * A section belongs to exactly one class level. Pairing a class with a
     * section of a DIFFERENT class scopes the exam to the empty set — the two
     * filters intersect to nobody — and every later mark submission is refused
     * as out of scope, with nothing on screen to say why.
     */
    public function test_a_section_from_another_class_is_refused(): void
    {
        $bangla = $this->section('Class 9', 'Shapla', 'Bangla');
        $english = $this->section('Class 9', 'Orchid', 'English');

        $this->assertNotSame(
            $bangla->class_level_id,
            $english->class_level_id,
            'the two versions of Class 9 must be different class levels for this test to mean anything',
        );

        $this->signInPps($this->admin())
            ->postJson('/api/v1/pps/admin/exams', $this->payload([
                'class_maps' => [[
                    'class_level_id' => $bangla->class_level_id,
                    'section_id' => $english->id,
                    'subject_id' => null,
                ]],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('class_maps.0.section_id');

        $this->assertSame(0, Exam::count());
    }

    /**
     * The cascade this endpoint has to survive: editing an exam's title, its
     * class maps and the max marks of a component must not touch pps_marks.
     */
    public function test_updating_an_exam_preserves_marks_already_entered(): void
    {
        $shapla = $this->section('Class 9', 'Shapla', 'Bangla');
        $dolon = $this->section('Class 9', 'Dolon', 'Bangla');
        $maths = $this->subject('Mathematics', 'MATH');

        $student = $this->makeStudent([
            'student_code' => 'BV9-01',
            'name' => 'Shapla Student',
            'class_name' => 'Class 9',
            'section' => 'Shapla',
            'version' => 'Bangla',
            'roll_number' => 1,
        ]);

        $session = $this->signInPps($this->admin());

        $created = $session->postJson('/api/v1/pps/admin/exams', $this->payload([
            'class_maps' => [
                ['class_level_id' => $shapla->class_level_id, 'section_id' => $shapla->id, 'subject_id' => $maths->id],
            ],
        ]))->assertCreated();

        $examId = (int) $created->json('exam.id');
        $components = collect($created->json('exam.components'))->keyBy('code');
        $writtenId = (int) $components['WRITTEN']['id'];

        $mark = Mark::create([
            'component_id' => $writtenId,
            'student_id' => $student->id,
            'subject_id' => $maths->id,
            'marks_obtained' => 61.5,
        ]);

        $updated = $session->patchJson("/api/v1/pps/admin/exams/{$examId}", $this->payload([
            'title' => 'Half Yearly 2026 (revised)',
            'components' => [
                // Same rows, carried back by id — including a renamed one and a
                // changed ceiling — plus one that is genuinely new.
                ['id' => $writtenId, 'name' => 'Written (Theory)', 'code' => 'WRITTEN', 'max_raw_marks' => 60, 'max_contribution' => 60],
                ['id' => (int) $components['MCQ']['id'], 'name' => 'MCQ', 'code' => 'MCQ', 'max_raw_marks' => 20, 'max_contribution' => 20],
                ['id' => (int) $components['CA']['id'], 'name' => 'Continuous Assessment', 'code' => 'CA', 'max_raw_marks' => 10, 'max_contribution' => 10],
                ['name' => 'Practical', 'code' => 'PRAC', 'max_raw_marks' => 10, 'max_contribution' => 10],
            ],
            'class_maps' => [
                ['class_level_id' => $shapla->class_level_id, 'section_id' => $shapla->id, 'subject_id' => $maths->id],
                ['class_level_id' => $dolon->class_level_id, 'section_id' => $dolon->id, 'subject_id' => $maths->id],
            ],
        ]))->assertOk();

        $this->assertSame('Half Yearly 2026 (revised)', $updated->json('exam.title'));
        $this->assertCount(4, $updated->json('exam.components'));
        $this->assertCount(2, $updated->json('exam.class_maps'));

        // The component kept its id, so the mark is still attached to it.
        $this->assertSame('Written (Theory)', ExamComponent::find($writtenId)->name);
        $this->assertDatabaseHas('pps_marks', [
            'id' => $mark->id,
            'component_id' => $writtenId,
            'marks_obtained' => 61.5,
        ]);
        $this->assertSame(1, Mark::count());
    }

    /**
     * Dropping a component from the payload deletes the row, and
     * `pps_marks.component_id` is `cascadeOnDelete` — so a component that
     * already carries marks may not be dropped silently. The whole request is
     * refused and the transaction rolls back.
     */
    public function test_removing_a_component_that_already_has_marks_is_refused(): void
    {
        $shapla = $this->section('Class 9', 'Shapla', 'Bangla');
        $maths = $this->subject('Mathematics', 'MATH');

        $student = $this->makeStudent([
            'student_code' => 'BV9-01',
            'name' => 'Shapla Student',
            'class_name' => 'Class 9',
            'section' => 'Shapla',
            'version' => 'Bangla',
            'roll_number' => 1,
        ]);

        $session = $this->signInPps($this->admin());

        $created = $session->postJson('/api/v1/pps/admin/exams', $this->payload([
            'class_maps' => [
                ['class_level_id' => $shapla->class_level_id, 'section_id' => null, 'subject_id' => $maths->id],
            ],
        ]))->assertCreated();

        $examId = (int) $created->json('exam.id');
        $components = collect($created->json('exam.components'))->keyBy('code');
        $writtenId = (int) $components['WRITTEN']['id'];

        Mark::create([
            'component_id' => $writtenId,
            'student_id' => $student->id,
            'subject_id' => $maths->id,
            'marks_obtained' => 61.5,
        ]);

        $session->patchJson("/api/v1/pps/admin/exams/{$examId}", $this->payload([
            'title' => 'Half Yearly 2026 (trimmed)',
            'components' => [
                // WRITTEN is gone from the list, and it has marks.
                ['id' => (int) $components['MCQ']['id'], 'name' => 'MCQ', 'code' => 'MCQ', 'max_raw_marks' => 20, 'max_contribution' => 20],
            ],
            'class_maps' => [
                ['class_level_id' => $shapla->class_level_id, 'section_id' => null, 'subject_id' => $maths->id],
            ],
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('components');

        // Nothing was written: not the mark, not the component, not the title.
        $this->assertSame(1, Mark::count());
        $this->assertSame(3, ExamComponent::where('exam_id', $examId)->count());
        $this->assertSame('Half Yearly 2026', Exam::find($examId)->title);
    }

    /**
     * A component with no marks is removable — otherwise a typo would be
     * permanent.
     */
    public function test_removing_a_component_without_marks_succeeds(): void
    {
        $session = $this->signInPps($this->admin());

        $created = $session->postJson('/api/v1/pps/admin/exams', $this->payload())->assertCreated();

        $examId = (int) $created->json('exam.id');
        $components = collect($created->json('exam.components'))->keyBy('code');

        $session->patchJson("/api/v1/pps/admin/exams/{$examId}", $this->payload([
            'components' => [
                ['id' => (int) $components['WRITTEN']['id'], 'name' => 'Written', 'code' => 'WRITTEN', 'max_raw_marks' => 80, 'max_contribution' => 80],
                ['id' => (int) $components['MCQ']['id'], 'name' => 'MCQ', 'code' => 'MCQ', 'max_raw_marks' => 20, 'max_contribution' => 20],
            ],
        ]))->assertOk();

        $this->assertSame(
            ['WRITTEN', 'MCQ'],
            ExamComponent::where('exam_id', $examId)->orderBy('sort_order')->pluck('code')->all(),
        );
    }

    /**
     * A blank code is the common case in a form; the column is NOT NULL and
     * unique per exam, so one has to be derived rather than rejected.
     */
    public function test_a_blank_component_code_is_derived_from_the_name(): void
    {
        $created = $this->signInPps($this->admin())
            ->postJson('/api/v1/pps/admin/exams', $this->payload([
                'components' => [
                    ['name' => 'Written Paper', 'code' => '', 'max_raw_marks' => 70, 'max_contribution' => 70],
                    ['name' => 'Written Paper', 'code' => null, 'max_raw_marks' => 30, 'max_contribution' => 30],
                ],
            ]))
            ->assertCreated();

        $this->assertSame(
            ['WRITTEN_PAPER', 'WRITTEN_PAPER_2'],
            collect($created->json('exam.components'))->pluck('code')->all(),
        );
    }

    /**
     * The whole point: an exam built here is one the marks surface can actually
     * use. GET /marks/meta is what the grid populates its selector from.
     */
    public function test_a_created_exam_reaches_marks_meta_with_its_components(): void
    {
        $shapla = $this->section('Class 9', 'Shapla', 'Bangla');
        $maths = $this->subject('Mathematics', 'MATH');

        $created = $this->signInPps($this->admin())
            ->postJson('/api/v1/pps/admin/exams', $this->payload([
                'class_maps' => [
                    ['class_level_id' => $shapla->class_level_id, 'section_id' => null, 'subject_id' => $maths->id],
                ],
            ]))
            ->assertCreated();

        $examId = (int) $created->json('exam.id');

        $meta = $this->getJson('/api/v1/pps/marks/meta')->assertOk();

        $exam = collect($meta->json('exams'))->firstWhere('id', $examId);

        $this->assertNotNull($exam, 'a published, active exam must be offered to the marks grid');
        $this->assertSame(
            ['Written', 'MCQ', 'Continuous Assessment'],
            collect($exam['components'])->pluck('name')->all(),
        );
    }
}

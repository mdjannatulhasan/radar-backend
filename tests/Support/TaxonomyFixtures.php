<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Pps\ComputedScore;
use App\Models\Pps\Exam;
use App\Models\Pps\ExamComponent;
use App\Models\Pps\ExamType;
use App\Models\Pps\Mark;
use App\Models\Pps\TeacherAssignment;
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
 * Builds the level/version/class/section chain that RADAR's fixtures used to
 * express as two strings on the students row.
 *
 * Every helper is lazy and memoised: a test that never mentions a class never
 * creates a school, so tests that build their own taxonomy (TaxonomyTest) are
 * untouched by this. Everything is created inside the per-test transaction and
 * rolled back with it.
 */
trait TaxonomyFixtures
{
    private ?School $fixtureSchool = null;

    private ?AcademicYear $fixtureYear = null;

    /** @var array<string, mixed> */
    private array $fixtureCache = [];

    protected function school(): School
    {
        return $this->fixtureSchool ??= School::create([
            'name' => 'Test School',
            'slug' => 'test-school',
        ]);
    }

    protected function academicYear(): AcademicYear
    {
        return $this->fixtureYear ??= AcademicYear::create([
            'school_id' => $this->school()->id,
            'name' => '2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'is_current' => true,
        ]);
    }

    protected function level(string $name = 'School'): Level
    {
        return $this->fixtureCache['level.'.$name] ??= Level::firstOrCreate(
            ['school_id' => $this->school()->id, 'name' => $name],
        );
    }

    protected function version(string $name = 'Bangla'): Version
    {
        return $this->fixtureCache['version.'.$name] ??= Version::firstOrCreate(
            ['school_id' => $this->school()->id, 'name' => $name],
        );
    }

    protected function classLevel(string $name, string $version = 'Bangla', string $level = 'School'): ClassLevel
    {
        $key = "class.{$level}.{$version}.{$name}";

        return $this->fixtureCache[$key] ??= ClassLevel::firstOrCreate([
            'school_id' => $this->school()->id,
            'level_id' => $this->level($level)->id,
            'version_id' => $this->version($version)->id,
            'name' => $name,
        ]);
    }

    protected function sectionName(string $name): SectionName
    {
        return $this->fixtureCache['sname.'.$name] ??= SectionName::firstOrCreate(
            ['school_id' => $this->school()->id, 'name' => $name],
        );
    }

    /** The concrete class+section a student sits in, e.g. ("10", "A"). */
    protected function section(string $className, string $sectionName, string $version = 'Bangla', string $level = 'School'): Section
    {
        $key = "section.{$level}.{$version}.{$className}.{$sectionName}";

        return $this->fixtureCache[$key] ??= Section::firstOrCreate([
            'school_id' => $this->school()->id,
            'class_level_id' => $this->classLevel($className, $version, $level)->id,
            'section_name_id' => $this->sectionName($sectionName)->id,
        ]);
    }

    protected function subject(string $fullName, ?string $shortName = null): Subject
    {
        return $this->fixtureCache['subject.'.$fullName] ??= Subject::firstOrCreate(
            [
                'school_id' => $this->school()->id,
                'level_id' => null,
                'version_id' => null,
                'short_name' => $shortName ?? mb_substr($fullName, 0, 20),
            ],
            ['full_name' => $fullName],
        );
    }

    /**
     * Create a student and enrol them. `class_name` and `section` are accepted
     * as a convenience so fixtures read the way they always did; they are
     * translated into the enrollment chain rather than stored on the row.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function makeStudent(array $attributes): Student
    {
        $className = $attributes['class_name'] ?? null;
        $sectionName = $attributes['section'] ?? null;
        $version = $attributes['version'] ?? 'Bangla';
        $level = $attributes['level'] ?? 'School';
        unset($attributes['class_name'], $attributes['section'], $attributes['version'], $attributes['level']);

        $student = Student::create($attributes + ['school_id' => $this->school()->id]);

        if ($className !== null && $sectionName !== null) {
            StudentEnrollment::create([
                'school_id' => $this->school()->id,
                'student_id' => $student->id,
                'academic_year_id' => $this->academicYear()->id,
                'section_id' => $this->section($className, $sectionName, $version, $level)->id,
                'roll_number' => $attributes['roll_number'] ?? null,
            ]);

            $student->load('currentEnrollment.section.classLevel', 'currentEnrollment.section.sectionName');
        }

        return $student;
    }

    /** The staff record behind a login. Assignments hang off this, not the user. */
    protected function makeTeacher(User $user): Teacher
    {
        return $this->fixtureCache['teacher.'.$user->id] ??= Teacher::create([
            'school_id' => $this->school()->id,
            'full_name' => $user->name,
            'user_id' => $user->id,
        ]);
    }

    protected function assignTeacher(
        User $user,
        string $className,
        string $sectionName,
        ?string $subject = null,
        bool $isClassTeacher = false,
        string $version = 'Bangla',
        string $level = 'School',
    ): TeacherAssignment {
        return TeacherAssignment::create([
            'school_id' => $this->school()->id,
            'teacher_id' => $this->makeTeacher($user)->id,
            'section_id' => $this->section($className, $sectionName, $version, $level)->id,
            'subject_id' => $subject === null ? null : $this->subject($subject)->id,
            'is_class_teacher' => $isClassTeacher,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    protected function createUser(array $attributes): User
    {
        return User::create($attributes + ['school_id' => $this->school()->id]);
    }

    // ── Exams and marks ────────────────────────────────────────────────────────

    protected function examType(string $code = 'class_test', string $name = 'Class Test'): ExamType
    {
        return $this->fixtureCache['examType.'.$code] ??= ExamType::firstOrCreate(
            ['code' => $code],
            ['name' => $name, 'is_terminal' => false, 'is_system' => true, 'is_active' => true],
        );
    }

    /** One exam per date, carrying a single 100-mark written component. */
    protected function exam(string $examDate, string $code = 'class_test'): Exam
    {
        return $this->fixtureCache['exam.'.$code.'.'.$examDate] ??= Exam::create([
            'exam_type_id' => $this->examType($code)->id,
            'title' => ucfirst(str_replace('_', ' ', $code))." {$examDate}",
            'academic_year' => (int) substr($examDate, 0, 4),
            'term' => 1,
            'exam_date' => $examDate,
            'scope' => 'class',
            'status' => 'published',
            'is_active' => true,
        ]);
    }

    protected function examComponent(Exam $exam, float $maxMarks = 100.0): ExamComponent
    {
        return $this->fixtureCache['component.'.$exam->id] ??= ExamComponent::create([
            'exam_id' => $exam->id,
            'name' => 'Written',
            'code' => 'WRITTEN',
            'max_raw_marks' => $maxMarks,
            'max_contribution' => $maxMarks,
            'sort_order' => 1,
        ]);
    }

    /**
     * One subject result for one student, as the schema now expresses it.
     *
     * This is the successor to the single `pps_assessments` row fixtures used
     * to write. That flat row split in two: the raw entry is a `pps_marks` row
     * against an exam component, and the aggregate the risk engine reads is a
     * `pps_computed_scores` row — in production written by
     * ComputedScoreService::recomputeForExamSubject(). Both are created here
     * because the two are read by different code paths: class analytics and
     * teacher effectiveness join through Mark, while ScoreCalculatorService
     * averages ComputedScore.percentage.
     *
     * @return array{mark: Mark, score: ComputedScore}
     */
    protected function recordExamResult(
        Student $student,
        string $subject,
        string $examDate,
        float $percentage,
        ?int $enteredBy = null,
    ): array {
        $exam = $this->exam($examDate);
        $component = $this->examComponent($exam);
        $subjectModel = $this->subject($subject);

        $mark = Mark::create([
            'component_id' => $component->id,
            'student_id' => $student->id,
            'subject_id' => $subjectModel->id,
            'marks_obtained' => $percentage / 100 * $component->max_raw_marks,
            'entered_by' => $enteredBy,
            'recorded_at' => $examDate,
        ]);

        $score = ComputedScore::create([
            'exam_id' => $exam->id,
            'student_id' => $student->id,
            'subject_id' => $subjectModel->id,
            'total_obtained' => $percentage / 100 * $component->max_raw_marks,
            'total_possible' => $component->max_raw_marks,
            'percentage' => $percentage,
            'computed_at' => $examDate,
        ]);

        return ['mark' => $mark, 'score' => $score];
    }
}

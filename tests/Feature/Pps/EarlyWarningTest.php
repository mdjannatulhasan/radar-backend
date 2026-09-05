<?php

namespace Tests\Feature\Pps;

use App\Models\Pps\EarlyWarning;
use App\Models\Pps\PerformanceSnapshot;
use App\Models\Pps\PpsNotificationLog;
use App\Models\Pps\SchoolPpsConfig;
use App\Services\Pps\EarlyWarningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use SmsCore\Models\Designation;
use SmsCore\Models\TeacherLevelScope;
use Tests\TestCase;

class EarlyWarningTest extends TestCase
{
    use RefreshDatabase;

    public function test_config_defaults_and_warning_row(): void
    {
        $config = SchoolPpsConfig::current();
        $this->assertSame(40.0, $config->early_warning_risk_threshold);
        $this->assertSame(3, $config->early_warning_min_history);

        $student = $this->makeStudent(['student_code' => 'EW-1', 'name' => 'EW Student', 'class_name' => '8', 'section' => 'A']);
        $warning = EarlyWarning::create([
            'student_id' => $student->id,
            'snapshot_period' => '2026-09',
            'horizon_months' => 3,
            'category' => 'near',
            'current_risk' => 25,
            'projected_risk' => 47.5,
            'projected_overall' => 52.1,
            'drivers' => [['kind' => 'score', 'key' => 'attendance', 'slope' => -4.2]],
        ]);
        $this->assertSame('open', $warning->fresh()->status);
        $this->assertSame('attendance', $warning->fresh()->drivers[0]['key']);
    }

    /** Helper: monthly snapshots ending at 2026-09 with the given risk series (overall = 100 - risk). */
    private function seedRiskSeries(int $studentId, array $risks): void
    {
        $months = ['2026-04', '2026-05', '2026-06', '2026-07', '2026-08', '2026-09'];
        $months = array_slice($months, -count($risks));
        foreach ($risks as $i => $risk) {
            PerformanceSnapshot::query()->create([
                'student_id' => $studentId, 'snapshot_period' => $months[$i],
                'academic_score' => 100 - $risk, 'attendance_score' => 90 - $risk, 'behavior_score' => 70,
                'participation_score' => 70, 'extracurricular_score' => 70, 'overall_score' => 100 - $risk,
                'risk_score' => $risk, 'alert_level' => $risk >= 40 ? 'warning' : ($risk >= 20 ? 'watch' : 'none'),
                'trend_direction' => 'down',
                'snapshot_data' => ['subjects' => ['Mathematics' => ['avg' => 80 - $risk, 'count' => 2, 'trend' => []], 'English' => ['avg' => 75, 'count' => 2, 'trend' => []]], 'attendance' => []],
                'calculated_at' => now(),
            ]);
        }
    }

    public function test_generate_classifies_horizons_and_clears_recovered(): void
    {
        $near = $this->makeStudent(['student_code' => 'EW-2', 'name' => 'Near Student', 'class_name' => '8', 'section' => 'A']);
        $imminent = $this->makeStudent(['student_code' => 'EW-3', 'name' => 'Imminent Student', 'class_name' => '8', 'section' => 'A']);
        $stable = $this->makeStudent(['student_code' => 'EW-4', 'name' => 'Stable Student', 'class_name' => '8', 'section' => 'A']);
        $already = $this->makeStudent(['student_code' => 'EW-5', 'name' => 'Already Warning', 'class_name' => '8', 'section' => 'A']);
        $short = $this->makeStudent(['student_code' => 'EW-6', 'name' => 'Short History', 'class_name' => '8', 'section' => 'A']);

        $this->seedRiskSeries($near->id, [5, 15, 25]);        // +10/month → 35 @1m, 55 @3m → near
        $this->seedRiskSeries($imminent->id, [10, 20, 30]);   // → 40 @1m → imminent
        $this->seedRiskSeries($stable->id, [15, 15, 15]);     // flat → none
        $this->seedRiskSeries($already->id, [30, 40, 50]);    // already ≥ 40 → reactive alerts handle it, no early warning
        $this->seedRiskSeries($short->id, [10, 30]);          // < min history → skipped

        $result = app(EarlyWarningService::class)->generate('2026-09');

        $this->assertSame(5, $result['scanned']);
        $this->assertSame(2, $result['created']);

        $nearRow = EarlyWarning::query()->where('student_id', $near->id)->firstOrFail();
        $this->assertSame('near', $nearRow->category);
        $this->assertSame(3, $nearRow->horizon_months);
        $this->assertSame(55.0, $nearRow->projected_risk);
        $this->assertSame('open', $nearRow->status);
        $this->assertSame('attendance', collect($nearRow->drivers)->firstWhere('kind', 'score')['key']);
        $this->assertSame('Mathematics', collect($nearRow->drivers)->firstWhere('kind', 'subject')['key']);

        $this->assertSame('imminent', EarlyWarning::query()->where('student_id', $imminent->id)->value('category'));
        $this->assertNull(EarlyWarning::query()->where('student_id', $stable->id)->first());
        $this->assertNull(EarlyWarning::query()->where('student_id', $already->id)->first());
        $this->assertNull(EarlyWarning::query()->where('student_id', $short->id)->first());

        // Recovery next period: flat series → open warning becomes cleared.
        PerformanceSnapshot::query()->create([
            'student_id' => $near->id, 'snapshot_period' => '2026-10',
            'academic_score' => 75, 'attendance_score' => 65, 'behavior_score' => 70, 'participation_score' => 70,
            'extracurricular_score' => 70, 'overall_score' => 75, 'risk_score' => 5, 'alert_level' => 'none',
            'trend_direction' => 'up', 'snapshot_data' => ['subjects' => [], 'attendance' => []], 'calculated_at' => now(),
        ]);
        $second = app(EarlyWarningService::class)->generate('2026-10');
        $this->assertSame('cleared', $nearRow->fresh()->status);
        $this->assertGreaterThanOrEqual(1, $second['cleared']);
    }

    public function test_notifications_go_to_class_teacher_subject_teacher_scoped_vp_and_not_other_vp(): void
    {
        $classUser = $this->createUser(['name' => 'Class Teacher', 'email' => 'ct@ew.test', 'role' => 'teacher', 'password' => Hash::make('x')]);
        $mathUser = $this->createUser(['name' => 'Math Teacher', 'email' => 'mt@ew.test', 'role' => 'teacher', 'password' => Hash::make('x')]);
        $englishUser = $this->createUser(['name' => 'English Teacher', 'email' => 'et@ew.test', 'role' => 'teacher', 'password' => Hash::make('x')]);
        $vpInUser = $this->createUser(['name' => 'VP Bangla School', 'email' => 'vp1@ew.test', 'role' => 'teacher', 'password' => Hash::make('x')]);
        $vpOutUser = $this->createUser(['name' => 'VP English College', 'email' => 'vp2@ew.test', 'role' => 'teacher', 'password' => Hash::make('x')]);
        $vpAllUser = $this->createUser(['name' => 'VP Unscoped', 'email' => 'vp3@ew.test', 'role' => 'teacher', 'password' => Hash::make('x')]);
        $principal = $this->createUser(['name' => 'Principal', 'email' => 'p@ew.test', 'role' => 'principal', 'password' => Hash::make('x')]);

        $student = $this->makeStudent(['student_code' => 'EW-7', 'name' => 'Notify Student', 'class_name' => '8', 'section' => 'A']);
        $this->assignTeacher($classUser, '8', 'A', null, true);
        $this->assignTeacher($mathUser, '8', 'A', 'Mathematics');
        $this->assignTeacher($englishUser, '8', 'A', 'English');

        $vpDesignation = Designation::create(['school_id' => $this->school()->id, 'title' => 'VP (School)', 'rank' => 1]);
        $section = $this->section('8', 'A'); // Bangla / School by fixture default
        $classLevel = $section->classLevel;
        foreach ([$vpInUser, $vpOutUser, $vpAllUser] as $u) {
            $this->makeTeacher($u)->update(['designation_id' => $vpDesignation->id]);
        }
        TeacherLevelScope::create(['school_id' => $this->school()->id, 'teacher_id' => $this->makeTeacher($vpInUser)->id, 'version_id' => $classLevel->version_id, 'level_id' => $classLevel->level_id]);
        $otherVersion = \SmsCore\Models\Version::firstOrCreate(['school_id' => $this->school()->id, 'name' => 'English'], ['sort_order' => 9]);
        $otherLevel = \SmsCore\Models\Level::firstOrCreate(['school_id' => $this->school()->id, 'name' => 'College'], ['sort_order' => 9]);
        TeacherLevelScope::create(['school_id' => $this->school()->id, 'teacher_id' => $this->makeTeacher($vpOutUser)->id, 'version_id' => $otherVersion->id, 'level_id' => $otherLevel->id]);

        $this->seedRiskSeries($student->id, [10, 20, 30]); // imminent, Mathematics driver

        $result = app(EarlyWarningService::class)->generate('2026-09');
        $this->assertSame(1, $result['created']);

        $logs = PpsNotificationLog::query()->where('student_id', $student->id)->where('type', 'early_warning_imminent')->get();
        $byUser = $logs->keyBy('recipient_user_id');

        $this->assertSame('class_teacher', $byUser[$classUser->id]->recipient_role);
        $this->assertSame('subject_teacher', $byUser[$mathUser->id]->recipient_role);
        $this->assertArrayNotHasKey($englishUser->id, $byUser->all(), 'English is not a driver');
        $this->assertSame('vice_principal', $byUser[$vpInUser->id]->recipient_role);
        $this->assertSame('vice_principal', $byUser[$vpAllUser->id]->recipient_role);
        $this->assertArrayNotHasKey($vpOutUser->id, $byUser->all(), 'VP scoped to another version/level');
        $this->assertSame('principal', $byUser[$principal->id]->recipient_role);
        $this->assertStringContainsString('Mathematics', $byUser[$mathUser->id]->body);
        $this->assertSame(1, (int) $byUser[$classUser->id]->meta['horizon_months']);

        // Second run in the same period does not duplicate.
        app(EarlyWarningService::class)->generate('2026-09');
        $this->assertSame($logs->count(), PpsNotificationLog::query()->where('student_id', $student->id)->count());
    }

    public function test_endpoints_list_scope_acknowledge_and_run(): void
    {
        $principal = $this->createUser(['name' => 'Principal', 'email' => 'p2@ew.test', 'role' => 'principal', 'password' => Hash::make('x')]);
        $teacherA = $this->createUser(['name' => 'Teacher A', 'email' => 'ta@ew.test', 'role' => 'teacher', 'password' => Hash::make('x')]);
        $teacherB = $this->createUser(['name' => 'Teacher B', 'email' => 'tb@ew.test', 'role' => 'teacher', 'password' => Hash::make('x')]);
        $s1 = $this->makeStudent(['student_code' => 'EW-8', 'name' => 'Sec A Student', 'class_name' => '8', 'section' => 'A']);
        $s2 = $this->makeStudent(['student_code' => 'EW-9', 'name' => 'Sec B Student', 'class_name' => '8', 'section' => 'B']);
        $this->assignTeacher($teacherA, '8', 'A', 'Mathematics');
        $this->assignTeacher($teacherB, '8', 'B', 'Mathematics');
        $this->seedRiskSeries($s1->id, [10, 20, 30]);
        $this->seedRiskSeries($s2->id, [5, 15, 25]);

        // Run through the notifications endpoint (principal only).
        $this->signInPps($teacherA)->postJson('/api/v1/pps/notifications/run/early-warnings', ['period' => '2026-09'])->assertForbidden();
        $this->signInPps($principal)->postJson('/api/v1/pps/notifications/run/early-warnings', ['period' => '2026-09'])
            ->assertOk()->assertJsonPath('created', 2);

        $this->signInPps($principal)->getJson('/api/v1/pps/early-warnings')
            ->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.category', 'imminent')
            ->assertJsonPath('data.0.student.name', 'Sec A Student')
            ->assertJsonPath('data.0.recipients.0.role', 'subject_teacher');

        $this->signInPps($principal)->getJson('/api/v1/pps/early-warnings?category=near')->assertOk()->assertJsonCount(1, 'data');

        // Teacher sees only their own section.
        $this->signInPps($teacherA)->getJson('/api/v1/pps/early-warnings')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.student.name', 'Sec A Student');

        $id = EarlyWarning::query()->where('student_id', $s1->id)->value('id');
        // Teachers may acknowledge only their own students: B is not assigned to section A.
        $this->signInPps($teacherB)->patchJson("/api/v1/pps/early-warnings/{$id}/acknowledge", ['note' => 'Extra class arranged'])->assertForbidden();
        $this->signInPps($principal)->patchJson("/api/v1/pps/early-warnings/{$id}/acknowledge", ['note' => 'Extra class arranged'])
            ->assertOk()->assertJsonPath('warning.status', 'acknowledged');
        $this->assertSame('Extra class arranged', EarlyWarning::find($id)->acknowledgement_note);

        // Student 360 exposes the open warning.
        $this->signInPps($principal)->getJson("/api/v1/pps/students/{$s1->id}/360")
            ->assertOk()->assertJsonPath('early_warning.category', 'imminent')->assertJsonPath('early_warning.status', 'acknowledged');

        // Artisan command works too.
        $this->artisan('pps:early-warnings', ['period' => '2026-09'])->assertSuccessful();
    }
}

<?php

namespace Tests\Feature\Pps;

use App\Models\Pps\EarlyWarning;
use App\Models\Pps\PerformanceSnapshot;
use App\Models\Pps\SchoolPpsConfig;
use App\Services\Pps\EarlyWarningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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
}

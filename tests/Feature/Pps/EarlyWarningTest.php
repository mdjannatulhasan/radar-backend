<?php

namespace Tests\Feature\Pps;

use App\Models\Pps\EarlyWarning;
use App\Models\Pps\SchoolPpsConfig;
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
}

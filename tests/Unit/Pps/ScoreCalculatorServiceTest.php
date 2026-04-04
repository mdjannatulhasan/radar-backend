<?php

namespace Tests\Unit\Pps;

use App\Models\Pps\Assessment;
use App\Models\Pps\AttendanceRecord;
use App\Models\Pps\BehaviorCard;
use App\Models\Pps\ClassroomRating;
use App\Models\Pps\Extracurricular;
use App\Models\Pps\SchoolPpsConfig;
use App\Models\Student;
use App\Services\Pps\ScoreCalculatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScoreCalculatorServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_calculates_snapshot_scores_from_domain_records(): void
    {
        SchoolPpsConfig::current();

        $student = Student::query()->create([
            'student_code' => 'PPS-010',
            'name' => 'Nabila Sultana',
            'class_name' => '8',
            'section' => 'B',
            'roll_number' => 10,
        ]);

        foreach ([
            ['Mathematics', '2026-02-12', 80],
            ['Mathematics', '2026-03-12', 70],
            ['Mathematics', '2026-04-12', 60],
        ] as [$subject, $date, $percentage]) {
            Assessment::query()->create([
                'student_id' => $student->id,
                'subject' => $subject,
                'assessment_type' => 'class_test',
                'term' => '2026-term-1',
                'marks_obtained' => $percentage,
                'total_marks' => 100,
                'percentage' => $percentage,
                'exam_date' => $date,
            ]);
        }

        foreach (range(1, 8) as $day) {
            AttendanceRecord::query()->create([
                'student_id' => $student->id,
                'date' => sprintf('2026-04-%02d', $day),
                'status' => 'present',
            ]);
        }

        AttendanceRecord::query()->create([
            'student_id' => $student->id,
            'date' => '2026-04-09',
            'status' => 'late',
        ]);

        AttendanceRecord::query()->create([
            'student_id' => $student->id,
            'date' => '2026-04-10',
            'status' => 'absent',
        ]);

        BehaviorCard::query()->create([
            'student_id' => $student->id,
            'card_type' => 'green',
            'reason' => 'Helped a classmate',
            'issued_at' => '2026-04-05 10:00:00',
        ]);

        BehaviorCard::query()->create([
            'student_id' => $student->id,
            'card_type' => 'yellow',
            'reason' => 'Distracted in class',
            'issued_at' => '2026-04-07 10:00:00',
        ]);

        ClassroomRating::query()->create([
            'student_id' => $student->id,
            'subject' => 'Mathematics',
            'rating_period' => '2026-04-14',
            'participation' => 4,
            'attentiveness' => 4,
            'group_work' => 3,
            'creativity' => 5,
            'free_comment' => 'Recovering confidence.',
        ]);

        Extracurricular::query()->create([
            'student_id' => $student->id,
            'activity_name' => 'Science Club',
            'category' => 'club',
            'achievement' => 'Monthly showcase',
            'achievement_level' => 3,
            'event_date' => '2026-04-18',
        ]);

        $snapshot = app(ScoreCalculatorService::class)->calculateForStudent($student->id, '2026-04');

        $this->assertSame(70.0, $snapshot->academic_score);
        $this->assertSame(90.0, $snapshot->attendance_score);
        $this->assertSame(80.0, $snapshot->behavior_score);
        $this->assertSame(80.0, $snapshot->participation_score);
        $this->assertSame(61.0, $snapshot->extracurricular_score);
        $this->assertSame(76.1, $snapshot->overall_score);
        $this->assertSame('none', $snapshot->alert_level);
        $this->assertEquals(60.0, $snapshot->snapshot_data['subjects']['Mathematics']['avg']);
    }
}

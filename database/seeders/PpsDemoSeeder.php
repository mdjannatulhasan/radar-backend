<?php

namespace Database\Seeders;

use App\Models\Pps\Assessment;
use App\Models\Pps\AttendanceRecord;
use App\Models\Pps\BehaviorCard;
use App\Models\Pps\ClassroomRating;
use App\Models\Pps\CounselingSession;
use App\Models\Pps\Extracurricular;
use App\Models\Pps\PpsAlert;
use App\Models\Pps\PerformanceSnapshot;
use App\Models\Pps\SchoolPpsConfig;
use App\Models\Pps\TeacherAssignment;
use App\Models\Student;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class PpsDemoSeeder extends Seeder
{
    private const DEMO_PASSWORD = 'PpsDemo2026!';

    public function run(): void
    {
        SchoolPpsConfig::current();

        $admin = User::query()->firstOrCreate(
            ['email' => 'admin@pps.local'],
            [
                'name' => 'System Admin',
                'role' => 'admin',
                'password' => Hash::make(self::DEMO_PASSWORD),
            ]
        );

        $principal = User::query()->firstOrCreate(
            ['email' => 'principal@pps.local'],
            [
                'name' => 'Principal User',
                'role' => 'principal',
                'password' => Hash::make(self::DEMO_PASSWORD),
            ]
        );

        $counselor = User::query()->firstOrCreate(
            ['email' => 'counselor@pps.local'],
            [
                'name' => 'Counselor User',
                'role' => 'counselor',
                'password' => Hash::make(self::DEMO_PASSWORD),
            ]
        );

        $teachers = collect([
            ['name' => 'Mariam Rahman', 'email' => 'teacher.math@pps.local'],
            ['name' => 'Sabbir Hasan', 'email' => 'teacher.english@pps.local'],
            ['name' => 'Tahmina Akter', 'email' => 'teacher.science@pps.local'],
        ])->map(function (array $teacher) {
            return User::query()->firstOrCreate(
                ['email' => $teacher['email']],
                [
                    'name' => $teacher['name'],
                    'role' => 'teacher',
                    'password' => Hash::make(self::DEMO_PASSWORD),
                ]
            );
        })->values();

        $this->seedTeacherAssignments($teachers);

        $featuredStudents = collect([
            [
                'student_code' => 'PPS-DEMO-001',
                'name' => 'Rafi Islam',
                'class_name' => '8',
                'section' => 'A',
                'roll_number' => 21,
                'guardian_name' => 'Farzana Islam',
                'guardian_phone' => '+8801711000001',
                'guardian_email' => 'guardian.rafi@pps.local',
                'seed_type' => 'urgent',
                'family_status' => 'single parent',
                'economic_status' => 'scholarship supported',
                'scholarship_status' => 'partial scholarship',
                'health_notes' => 'Seasonal asthma noted.',
                'special_needs' => ['dyslexia_support'],
                'private_tuition_subjects' => [
                    ['subject' => 'Mathematics', 'hours_per_week' => 3, 'tutor_name' => 'Mahin Sir'],
                ],
            ],
            [
                'student_code' => 'PPS-DEMO-002',
                'name' => 'Nabila Sultana',
                'class_name' => '7',
                'section' => 'B',
                'roll_number' => 7,
                'guardian_name' => 'Rezaul Karim',
                'guardian_phone' => '+8801711000002',
                'guardian_email' => 'guardian.nabila@pps.local',
                'seed_type' => 'good',
                'family_status' => 'stable',
                'economic_status' => 'standard',
                'scholarship_status' => null,
                'health_notes' => null,
                'special_needs' => [],
                'private_tuition_subjects' => [],
            ],
            [
                'student_code' => 'PPS-DEMO-003',
                'name' => 'Sadia Akter',
                'class_name' => '6',
                'section' => 'A',
                'roll_number' => 12,
                'guardian_name' => 'Mizanur Rahman',
                'guardian_phone' => '+8801711000003',
                'guardian_email' => 'guardian.sadia@pps.local',
                'seed_type' => 'watch',
                'family_status' => 'guardian works abroad',
                'economic_status' => 'standard',
                'scholarship_status' => null,
                'health_notes' => null,
                'special_needs' => [],
                'private_tuition_subjects' => [
                    ['subject' => 'English', 'hours_per_week' => 2, 'tutor_name' => 'Sharmin Madam'],
                ],
            ],
        ]);

        foreach ($featuredStudents as $profile) {
            $student = Student::query()->create([
                'student_code' => $profile['student_code'],
                'name' => $profile['name'],
                'class_name' => $profile['class_name'],
                'section' => $profile['section'],
                'roll_number' => $profile['roll_number'],
                'admission_date' => now()->subYears(2)->startOfYear(),
                'guardian_name' => $profile['guardian_name'],
                'guardian_phone' => $profile['guardian_phone'],
                'guardian_email' => $profile['guardian_email'],
                'private_tuition_subjects' => $profile['private_tuition_subjects'],
                'private_tuition_notes' => $profile['private_tuition_subjects'] !== [] ? 'Featured demo student with documented tuition support.' : null,
                'family_status' => $profile['family_status'],
                'economic_status' => $profile['economic_status'],
                'scholarship_status' => $profile['scholarship_status'],
                'health_notes' => $profile['health_notes'],
                'special_needs' => $profile['special_needs'],
                'confidential_context' => $profile['seed_type'] === 'urgent' ? 'Family stress noted by counselor.' : null,
            ]);

            User::query()->firstOrCreate(
                ['email' => $profile['guardian_email']],
                [
                    'name' => $profile['guardian_name'],
                    'role' => 'guardian',
                    'password' => Hash::make(self::DEMO_PASSWORD),
                ]
            );

            $this->seedStudentDataset($student, $profile['seed_type'], $teachers, $principal, $counselor);
        }

        $periods = collect(range(5, 0))->map(
            fn (int $monthsBack) => now()->subMonths($monthsBack)->format('Y-m')
        );

        $classes = ['6', '7', '8', '9', '10'];
        $sections = ['A', 'B'];
        $studentIndex = 1;

        foreach ($classes as $className) {
            foreach ($sections as $section) {
                foreach (range(1, 3) as $roll) {
                    $student = Student::query()->create([
                        'student_code' => sprintf('PPS-%03d', $studentIndex),
                        'name' => fake()->name(),
                        'class_name' => $className,
                        'section' => $section,
                        'roll_number' => $roll,
                        'admission_date' => now()->subYears(rand(1, 4))->startOfYear(),
                        'guardian_name' => fake()->name(),
                        'guardian_phone' => fake()->phoneNumber(),
                        'guardian_email' => fake()->safeEmail(),
                        'private_tuition_subjects' => $studentIndex % 4 === 0 ? [
                            ['subject' => 'Mathematics', 'hours_per_week' => 3, 'tutor_name' => 'Home Tutor'],
                        ] : [],
                        'private_tuition_notes' => $studentIndex % 4 === 0 ? 'Receives weekly tuition support in mathematics.' : null,
                        'family_status' => $studentIndex % 6 === 0 ? 'single parent' : 'stable',
                        'economic_status' => $studentIndex % 7 === 0 ? 'scholarship supported' : 'standard',
                        'scholarship_status' => $studentIndex % 7 === 0 ? 'partial scholarship' : null,
                        'health_notes' => $studentIndex % 9 === 0 ? 'Seasonal asthma noted.' : null,
                        'allergies' => $studentIndex % 10 === 0 ? 'Dust' : null,
                        'medications' => $studentIndex % 9 === 0 ? 'Inhaler when needed' : null,
                        'residence_change_note' => $studentIndex % 8 === 0 ? 'Moved to a new neighborhood this term.' : null,
                        'special_needs' => $studentIndex % 12 === 0 ? ['dyslexia_support'] : [],
                        'confidential_context' => $studentIndex % 11 === 0 ? 'Home stress reported and counselor notified.' : null,
                    ]);

                    User::query()->firstOrCreate(
                        ['email' => $student->guardian_email],
                        [
                            'name' => $student->guardian_name ?? 'Guardian User',
                            'role' => 'guardian',
                            'password' => Hash::make(self::DEMO_PASSWORD),
                        ]
                    );

                    $seedType = match (true) {
                        $studentIndex % 11 === 0 => 'urgent',
                        $studentIndex % 5 === 0 => 'warning',
                        $studentIndex % 3 === 0 => 'watch',
                        default => 'good',
                    };
                    $this->seedStudentDataset($student, $seedType, $teachers, $principal, $counselor, $studentIndex, $periods);
                    $studentIndex++;
                }
            }
        }
    }

    private function seedStudentDataset(
        Student $student,
        string $seedType,
        $teachers,
        User $principal,
        User $counselor,
        ?int $studentIndex = null,
        $periods = null,
    ): void {
        $periodSeries = $periods ?? collect(range(5, 0))->map(
            fn (int $monthsBack) => now()->subMonths($monthsBack)->format('Y-m')
        );

        foreach ($periodSeries as $position => $period) {
            $snapshot = $this->snapshotFor($student, $seedType, $period, $position);
            PerformanceSnapshot::query()->create($snapshot);
        }

        $currentSnapshot = PerformanceSnapshot::query()
            ->where('student_id', $student->id)
            ->where('snapshot_period', now()->format('Y-m'))
            ->first();

        if ($currentSnapshot && $currentSnapshot->alert_level !== 'none') {
            $alert = PpsAlert::query()->create([
                'student_id' => $student->id,
                'snapshot_period' => $currentSnapshot->snapshot_period,
                'alert_level' => $currentSnapshot->alert_level,
                'trigger_reasons' => $this->triggerReasonsFor($currentSnapshot->alert_level),
                'notified_to' => $this->notifiedToFor($currentSnapshot->alert_level),
                'created_at' => Carbon::now()->subHours($studentIndex ?? 1),
                'updated_at' => Carbon::now()->subHours($studentIndex ?? 1),
            ]);

            if (in_array($currentSnapshot->alert_level, ['urgent', 'warning'], true)) {
                CounselingSession::query()->create([
                    'student_id' => $student->id,
                    'counselor_id' => $counselor->id,
                    'referred_by' => $principal->id,
                    'alert_id' => $alert->id,
                    'session_date' => now()->subDays(min($studentIndex ?? 6, 12))->toDateString(),
                    'session_type' => 'initial',
                    'session_notes' => 'Initial counseling review logged for demo data.',
                    'action_plan' => 'Weekly teacher follow-up and guardian check-in.',
                    'next_session_date' => now()->addWeek()->toDateString(),
                    'progress_status' => $currentSnapshot->alert_level === 'urgent' ? 'stable' : 'improving',
                ]);

                if (($studentIndex ?? 2) % 2 === 0) {
                    CounselingSession::query()->create([
                        'student_id' => $student->id,
                        'counselor_id' => $counselor->id,
                        'session_date' => now()->subDays(min($studentIndex ?? 6, 10))->toDateString(),
                        'session_type' => 'psychometric',
                        'assessment_tool' => 'PPS wellbeing checklist',
                        'session_notes' => 'Structured psychometric screening recorded.',
                        'progress_status' => 'stable',
                        'psychometric_scores' => [
                            'self_confidence' => max(35, 78 - ($studentIndex ?? 6)),
                            'anxiety_level' => min(78, 30 + ($studentIndex ?? 6)),
                            'social_skills' => max(40, 74 - ($studentIndex ?? 6)),
                            'emotional_regulation' => max(38, 76 - ($studentIndex ?? 6)),
                            'notes' => 'Scores indicate a need for structured monitoring.',
                        ],
                        'special_needs_profile' => $student->special_needs ?? [],
                    ]);
                }
            }
        }

        $this->seedDomainRecords($student, $seedType, $teachers);
    }

    private function snapshotFor(Student $student, string $seedType, string $period, int $position): array
    {
        $drift = $position * 1.8;

        $blueprint = match ($seedType) {
            'urgent' => [
                'academic' => 72 - ($drift * 5.0),
                'attendance' => 91 - ($drift * 7.5),
                'behavior' => 84 - ($drift * 4.8),
                'participation' => 76 - ($drift * 5.2),
                'extra' => 66 - ($drift * 2.5),
            ],
            'warning' => [
                'academic' => 76 - ($drift * 3.6),
                'attendance' => 94 - ($drift * 4.2),
                'behavior' => 86 - ($drift * 3.4),
                'participation' => 74 - ($drift * 3.2),
                'extra' => 63 - ($drift * 1.3),
            ],
            'watch' => [
                'academic' => 79 - ($drift * 2.3),
                'attendance' => 96 - ($drift * 2.4),
                'behavior' => 88 - ($drift * 1.6),
                'participation' => 78 - ($drift * 1.8),
                'extra' => 67 - ($drift * 0.8),
            ],
            default => [
                'academic' => 73 + ($drift * 1.2),
                'attendance' => 92 + ($drift * 0.8),
                'behavior' => 83 + ($drift * 1.1),
                'participation' => 69 + ($drift * 1.3),
                'extra' => 61 + ($drift * 1.0),
            ],
        };

        $scores = collect($blueprint)->map(fn (float $score) => round(max(35.0, min(98.0, $score)), 2));

        $overall = round(
            ($scores['academic'] * 0.40) +
            ($scores['attendance'] * 0.20) +
            ($scores['behavior'] * 0.15) +
            ($scores['participation'] * 0.15) +
            ($scores['extra'] * 0.10),
            2
        );

        $risk = round(
            max(
                0,
                (100 - $scores['academic']) * 0.28 +
                (100 - $scores['attendance']) * 0.34 +
                (100 - $scores['behavior']) * 0.18 +
                (100 - $scores['participation']) * 0.12 +
                ($seedType === 'urgent' ? 12 : ($seedType === 'warning' ? 8 : ($seedType === 'watch' ? 2 : 0)))
            ),
            2
        );

        $alertLevel = match (true) {
            $risk >= 70 => 'urgent',
            $risk >= 40 => 'warning',
            $risk >= 20 => 'watch',
            default => 'none',
        };

        $trend = match (true) {
            $seedType === 'urgent' && $position >= 4 => 'rapid_down',
            in_array($seedType, ['urgent', 'warning'], true) && $position >= 3 => 'down',
            $seedType === 'good' && $position >= 3 => 'up',
            default => 'stable',
        };

        return [
            'student_id' => $student->id,
            'snapshot_period' => $period,
            'academic_score' => $scores['academic'],
            'attendance_score' => $scores['attendance'],
            'behavior_score' => $scores['behavior'],
            'participation_score' => $scores['participation'],
            'extracurricular_score' => $scores['extra'],
            'overall_score' => $overall,
            'risk_score' => min(100.0, $risk),
            'alert_level' => $alertLevel,
            'trend_direction' => $trend,
            'snapshot_data' => [
                'subjects' => [
                    'Mathematics' => ['avg' => round(max(30, $scores['academic'] - 8), 1), 'count' => 3, 'trend' => []],
                    'English' => ['avg' => round(max(35, $scores['academic'] - 2), 1), 'count' => 2, 'trend' => []],
                    'Science' => ['avg' => round(min(99, $scores['academic'] + 3), 1), 'count' => 2, 'trend' => []],
                ],
                'attendance' => [
                    'total' => 22,
                    'absent' => max(0, (int) round((100 - $scores['attendance']) / 6)),
                    'late' => max(0, (int) round((100 - $scores['attendance']) / 12)),
                ],
                'cards' => [
                    'green' => $seedType === 'good' ? 2 : 0,
                    'yellow' => $seedType === 'warning' ? 2 : ($seedType === 'watch' ? 1 : 0),
                    'red' => $seedType === 'urgent' ? 1 : 0,
                ],
            ],
            'calculated_at' => Carbon::createFromFormat('Y-m', $period)->endOfMonth(),
            'created_at' => Carbon::createFromFormat('Y-m', $period)->endOfMonth(),
            'updated_at' => Carbon::createFromFormat('Y-m', $period)->endOfMonth(),
        ];
    }

    private function triggerReasonsFor(string $alertLevel): array
    {
        return match ($alertLevel) {
            'urgent' => [
                ['type' => 'combined_drop', 'detail' => 'Academic, attendance, and behavior signals declined together.', 'value' => 3],
                ['type' => 'critical_attendance', 'detail' => 'Attendance crossed the urgent threshold.', 'value' => 58],
            ],
            'warning' => [
                ['type' => 'academic_drop', 'detail' => 'Academic trend fell sharply this month.', 'value' => 14],
                ['type' => 'low_attendance', 'detail' => 'Attendance is below the warning threshold.', 'value' => 73],
            ],
            default => [
                ['type' => 'watchlist', 'detail' => 'A small but consistent decline was detected.', 'value' => 1],
            ],
        };
    }

    private function notifiedToFor(string $alertLevel): array
    {
        $targets = [
            ['role' => 'class_teacher', 'channel' => 'database'],
        ];

        if (in_array($alertLevel, ['warning', 'urgent'], true)) {
            $targets[] = ['role' => 'principal', 'channel' => 'database'];
            $targets[] = ['role' => 'guardian', 'channel' => 'sms'];
        }

        if ($alertLevel === 'urgent') {
            $targets[] = ['role' => 'counselor', 'channel' => 'database'];
            $targets[] = ['role' => 'guardian', 'channel' => 'email'];
        }

        return $targets;
    }

    private function seedDomainRecords(Student $student, string $seedType, $teachers): void
    {
        $subjects = [
            ['name' => 'Mathematics', 'teacher' => $teachers[0]],
            ['name' => 'English', 'teacher' => $teachers[1]],
            ['name' => 'Science', 'teacher' => $teachers[2]],
        ];

        foreach ([-1, 0] as $monthOffset) {
            $date = now()->copy()->addMonths($monthOffset);

            foreach ($subjects as $subject) {
                $score = $this->seedScoreForSubject($seedType, $subject['name'], $monthOffset);

                Assessment::query()->create([
                    'student_id' => $student->id,
                    'teacher_id' => $subject['teacher']->id,
                    'subject' => $subject['name'],
                    'assessment_type' => 'class_test',
                    'term' => sprintf('%s-term-1', $date->format('Y')),
                    'marks_obtained' => $score,
                    'total_marks' => 100,
                    'percentage' => $score,
                    'exam_date' => $date->copy()->day(match ($subject['name']) {
                        'Mathematics' => 12,
                        'English' => 16,
                        default => 20,
                    })->toDateString(),
                    'remarks' => $seedType === 'urgent' ? 'Needs close monitoring.' : null,
                ]);
            }

            ClassroomRating::query()->create([
                'student_id' => $student->id,
                'teacher_id' => $teachers[array_rand($teachers->all())]->id,
                'subject' => $subjects[array_rand($subjects)]['name'],
                'rating_period' => $date->copy()->startOfMonth()->addDays(7)->toDateString(),
                'period_type' => 'monthly',
                'participation' => $this->seedRatingValue($seedType, 'participation'),
                'attentiveness' => $this->seedRatingValue($seedType, 'attentiveness'),
                'group_work' => $this->seedRatingValue($seedType, 'group_work'),
                'creativity' => $this->seedRatingValue($seedType, 'creativity'),
                'behavioral_flag' => $seedType === 'urgent' && $monthOffset === 0 ? 'withdrawn' : null,
                'free_comment' => $this->seedComment($seedType),
                'created_at' => $date->copy()->startOfMonth()->addDays(7),
            ]);
        }

        foreach (range(1, 22) as $day) {
            $status = 'present';

            if ($seedType === 'urgent' && in_array($day, [4, 9, 15, 18, 21], true)) {
                $status = $day % 2 === 0 ? 'absent' : 'late';
            } elseif ($seedType === 'warning' && in_array($day, [8, 17, 21], true)) {
                $status = $day === 17 ? 'late' : 'absent';
            } elseif ($seedType === 'watch' && $day === 13) {
                $status = 'late';
            }

            AttendanceRecord::query()->create([
                'student_id' => $student->id,
                'date' => now()->copy()->startOfMonth()->addDays($day - 1)->toDateString(),
                'status' => $status,
                'marked_by' => $teachers[0]->id,
            ]);
        }

        if ($seedType !== 'good') {
            BehaviorCard::query()->create([
                'student_id' => $student->id,
                'issued_by' => $teachers[0]->id,
                'card_type' => $seedType === 'urgent' ? 'red' : 'yellow',
                'reason' => $seedType === 'urgent' ? 'Repeated classroom disruption.' : 'Attention dropped noticeably.',
                'issued_at' => now()->copy()->subDays(5),
            ]);
        } else {
            BehaviorCard::query()->create([
                'student_id' => $student->id,
                'issued_by' => $teachers[1]->id,
                'card_type' => 'green',
                'reason' => 'Consistent collaboration in class activities.',
                'issued_at' => now()->copy()->subDays(4),
            ]);
        }

        Extracurricular::query()->create([
            'student_id' => $student->id,
            'activity_name' => $seedType === 'urgent' ? 'Debate Club' : 'Science Club',
            'category' => 'club',
            'role' => 'member',
            'achievement' => $seedType === 'good' ? 'Monthly showcase mention' : ($seedType === 'watch' ? 'Active participation' : null),
            'achievement_level' => $seedType === 'good' ? 3 : ($seedType === 'watch' ? 2 : 1),
            'event_date' => now()->copy()->subDays(7)->toDateString(),
            'notes' => 'Demo extracurricular record.',
        ]);
    }

    private function seedScoreForSubject(string $seedType, string $subject, int $monthOffset): int
    {
        $base = match ($seedType) {
            'urgent' => 48,
            'warning' => 60,
            'watch' => 68,
            default => 78,
        };

        $subjectAdjustment = match ($subject) {
            'Mathematics' => -4,
            'English' => 2,
            default => 5,
        };

        return max(28, min(95, $base + $subjectAdjustment + ($monthOffset * 4)));
    }

    private function seedRatingValue(string $seedType, string $dimension): int
    {
        $base = match ($seedType) {
            'urgent' => 2,
            'warning' => 3,
            'watch' => 3,
            default => 4,
        };

        return min(5, max(1, $base + ($dimension === 'creativity' && $seedType === 'good' ? 1 : 0)));
    }

    private function seedComment(string $seedType): string
    {
        return match ($seedType) {
            'urgent' => 'Shows visible disengagement and needs closer daily follow-up.',
            'warning' => 'Performance is mixed. A tighter feedback loop would help.',
            'watch' => 'Generally stable, though confidence varies across weeks.',
            default => 'Participates well and responds positively to challenge.',
        };
    }

    private function seedTeacherAssignments($teachers): void
    {
        $assignmentMatrix = [
            [
                'teacher' => $teachers[0],
                'assignments' => [
                    ['class_name' => '8', 'section' => 'A', 'subject' => 'Mathematics', 'is_class_teacher' => true],
                    ['class_name' => '9', 'section' => 'A', 'subject' => 'Mathematics', 'is_class_teacher' => false],
                    ['class_name' => '10', 'section' => 'A', 'subject' => 'Mathematics', 'is_class_teacher' => false],
                ],
            ],
            [
                'teacher' => $teachers[1],
                'assignments' => [
                    ['class_name' => '7', 'section' => 'B', 'subject' => 'English', 'is_class_teacher' => true],
                    ['class_name' => '8', 'section' => 'A', 'subject' => 'English', 'is_class_teacher' => false],
                    ['class_name' => '10', 'section' => 'A', 'subject' => 'English', 'is_class_teacher' => false],
                ],
            ],
            [
                'teacher' => $teachers[2],
                'assignments' => [
                    ['class_name' => '6', 'section' => 'A', 'subject' => 'Science', 'is_class_teacher' => true],
                    ['class_name' => '8', 'section' => 'A', 'subject' => 'Science', 'is_class_teacher' => false],
                    ['class_name' => '10', 'section' => 'A', 'subject' => 'Science', 'is_class_teacher' => false],
                ],
            ],
        ];

        foreach ($assignmentMatrix as $row) {
            foreach ($row['assignments'] as $assignment) {
                TeacherAssignment::query()->updateOrCreate(
                    [
                        'teacher_id' => $row['teacher']->id,
                        'class_name' => $assignment['class_name'],
                        'section' => $assignment['section'],
                        'subject' => $assignment['subject'],
                    ],
                    [
                        'is_class_teacher' => $assignment['is_class_teacher'],
                    ]
                );
            }
        }
    }
}

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
    private const GIVEN_NAMES = [
        'Amina', 'Rafi', 'Nusrat', 'Hasib', 'Tanzim', 'Ishrat', 'Tahmid', 'Maliha', 'Farhan', 'Samia',
        'Adnan', 'Raisa', 'Mahin', 'Faria', 'Shafin', 'Tuba', 'Nafis', 'Orin', 'Zayan', 'Muntasir',
    ];
    private const FAMILY_NAMES = [
        'Rahman', 'Islam', 'Hasan', 'Akter', 'Chowdhury', 'Karim', 'Sultana', 'Hossain', 'Ahmed', 'Kabir',
    ];

    public function run(): void
    {
        SchoolPpsConfig::current();

        User::query()->firstOrCreate(['email' => 'admin@pps.local'], [
            'name' => 'System Admin', 'role' => 'admin',
            'password' => Hash::make(self::DEMO_PASSWORD),
        ]);

        $principal = User::query()->firstOrCreate(['email' => 'principal@pps.local'], [
            'name' => 'Principal User', 'role' => 'principal',
            'password' => Hash::make(self::DEMO_PASSWORD),
        ]);

        $counselor = User::query()->firstOrCreate(['email' => 'counselor@pps.local'], [
            'name' => 'Counselor User', 'role' => 'counselor',
            'password' => Hash::make(self::DEMO_PASSWORD),
        ]);

        $teachers = collect([
            ['name' => 'Mariam Rahman',   'email' => 'teacher.math@pps.local'],
            ['name' => 'Sabbir Hasan',    'email' => 'teacher.english@pps.local'],
            ['name' => 'Tahmina Akter',   'email' => 'teacher.science@pps.local'],
            ['name' => 'Jalal Uddin',     'email' => 'teacher.bangla@pps.local'],
            ['name' => 'Nargis Sultana',  'email' => 'teacher.social@pps.local'],
        ])->map(fn (array $t) => User::query()->firstOrCreate(
            ['email' => $t['email']],
            ['name' => $t['name'], 'role' => 'teacher', 'password' => Hash::make(self::DEMO_PASSWORD)],
        ))->values();

        $this->seedTeacherAssignments($teachers);

        // ── 8 featured students covering every meaningful profile ──────────────
        $featured = [
            [
                'student_code' => 'PPS-DEMO-001',
                'name'         => 'Rafi Islam',
                'class_name'   => '8', 'section' => 'A', 'roll_number' => 21,
                'guardian_name' => 'Farzana Islam', 'guardian_phone' => '+8801711000001',
                'guardian_email' => 'guardian.rafi@pps.local',
                'seed_type'    => 'urgent',
                'family_status' => 'single parent', 'economic_status' => 'scholarship supported',
                'scholarship_status' => 'partial scholarship',
                'health_notes' => 'Seasonal asthma noted.',
                'special_needs' => ['dyslexia_support'],
                'private_tuition_subjects' => [
                    ['subject' => 'Mathematics', 'hours_per_week' => 3, 'tutor_name' => 'Mahin Sir'],
                ],
                'confidential_context' => 'Family stress noted by counselor.',
            ],
            [
                'student_code' => 'PPS-DEMO-002',
                'name'         => 'Nabila Sultana',
                'class_name'   => '7', 'section' => 'B', 'roll_number' => 7,
                'guardian_name' => 'Rezaul Karim', 'guardian_phone' => '+8801711000002',
                'guardian_email' => 'guardian.nabila@pps.local',
                'seed_type'    => 'good',
                'family_status' => 'stable', 'economic_status' => 'standard',
                'scholarship_status' => null, 'health_notes' => null,
                'special_needs' => [], 'private_tuition_subjects' => [],
                'confidential_context' => null,
            ],
            [
                'student_code' => 'PPS-DEMO-003',
                'name'         => 'Sadia Akter',
                'class_name'   => '6', 'section' => 'A', 'roll_number' => 12,
                'guardian_name' => 'Mizanur Rahman', 'guardian_phone' => '+8801711000003',
                'guardian_email' => 'guardian.sadia@pps.local',
                'seed_type'    => 'watch',
                'family_status' => 'guardian works abroad', 'economic_status' => 'standard',
                'scholarship_status' => null, 'health_notes' => null,
                'special_needs' => [],
                'private_tuition_subjects' => [
                    ['subject' => 'English', 'hours_per_week' => 2, 'tutor_name' => 'Sharmin Madam'],
                ],
                'confidential_context' => null,
            ],
            [
                'student_code' => 'PPS-DEMO-004',
                'name'         => 'Karim Hossain',
                'class_name'   => '9', 'section' => 'A', 'roll_number' => 14,
                'guardian_name' => 'Habibur Rahman', 'guardian_phone' => '+8801756789012',
                'guardian_email' => 'guardian.karim@pps.local',
                'seed_type'    => 'attendance_crisis',
                'family_status' => 'stable', 'economic_status' => 'standard',
                'scholarship_status' => null, 'health_notes' => 'Repeated absence with no medical certificate.',
                'special_needs' => [], 'private_tuition_subjects' => [],
                'confidential_context' => 'Guardian unreachable for two consecutive weeks.',
            ],
            [
                'student_code' => 'PPS-DEMO-005',
                'name'         => 'Mehedi Ahmed',
                'class_name'   => '10', 'section' => 'A', 'roll_number' => 2,
                'guardian_name' => 'Kamrun Nahar', 'guardian_phone' => '+8801844332211',
                'guardian_email' => 'guardian.mehedi@pps.local',
                'seed_type'    => 'strong',
                'family_status' => 'stable', 'economic_status' => 'standard',
                'scholarship_status' => null, 'health_notes' => null,
                'special_needs' => [], 'private_tuition_subjects' => [],
                'confidential_context' => null,
            ],
            [
                'student_code' => 'PPS-DEMO-006',
                'name'         => 'Lubna Chowdhury',
                'class_name'   => '8', 'section' => 'B', 'roll_number' => 6,
                'guardian_name' => 'Hosne Ara Begum', 'guardian_phone' => '+8801966543210',
                'guardian_email' => 'guardian.lubna@pps.local',
                'seed_type'    => 'recovering',
                'family_status' => 'stable', 'economic_status' => 'scholarship supported',
                'scholarship_status' => 'full scholarship',
                'health_notes' => null, 'special_needs' => [],
                'private_tuition_subjects' => [
                    ['subject' => 'Mathematics', 'hours_per_week' => 2, 'tutor_name' => 'Rahima Madam'],
                    ['subject' => 'Science',     'hours_per_week' => 2, 'tutor_name' => 'Farhan Sir'],
                ],
                'confidential_context' => 'Was referred to counseling three months ago. Showing strong recovery.',
            ],
            [
                'student_code' => 'PPS-DEMO-007',
                'name'         => 'Tasneem Hasan',
                'class_name'   => '6', 'section' => 'B', 'roll_number' => 3,
                'guardian_name' => 'Shahadat Hossain', 'guardian_phone' => '+8801612345678',
                'guardian_email' => 'guardian.tasneem@pps.local',
                'seed_type'    => 'academic_crisis',
                'family_status' => 'stable', 'economic_status' => 'standard',
                'scholarship_status' => null, 'health_notes' => null,
                'special_needs' => ['learning_difficulty'],
                'private_tuition_subjects' => [],
                'confidential_context' => 'Good attendance but failing multiple subjects. Learning assessment recommended.',
            ],
            [
                'student_code' => 'PPS-DEMO-008',
                'name'         => 'Nusrat Karim',
                'class_name'   => '9', 'section' => 'B', 'roll_number' => 9,
                'guardian_name' => 'Mahfuz Alam', 'guardian_phone' => '+8801644332211',
                'guardian_email' => 'guardian.nusrat@pps.local',
                'seed_type'    => 'warning',
                'family_status' => 'single parent', 'economic_status' => 'scholarship supported',
                'scholarship_status' => 'partial scholarship',
                'health_notes' => null, 'special_needs' => [],
                'private_tuition_subjects' => [
                    ['subject' => 'English', 'hours_per_week' => 1, 'tutor_name' => 'Mim Madam'],
                ],
                'confidential_context' => null,
            ],
        ];

        foreach ($featured as $profile) {
            $student = Student::query()->create([
                'student_code'             => $profile['student_code'],
                'name'                     => $profile['name'],
                'class_name'               => $profile['class_name'],
                'section'                  => $profile['section'],
                'roll_number'              => $profile['roll_number'],
                'admission_date'           => now()->subYears(2)->startOfYear(),
                'guardian_name'            => $profile['guardian_name'],
                'guardian_phone'           => $profile['guardian_phone'],
                'guardian_email'           => $profile['guardian_email'],
                'private_tuition_subjects' => $profile['private_tuition_subjects'],
                'private_tuition_notes'    => $profile['private_tuition_subjects'] !== [] ? 'Documented tuition support on file.' : null,
                'family_status'            => $profile['family_status'],
                'economic_status'          => $profile['economic_status'],
                'scholarship_status'       => $profile['scholarship_status'],
                'health_notes'             => $profile['health_notes'],
                'special_needs'            => $profile['special_needs'],
                'confidential_context'     => $profile['confidential_context'],
            ]);

            User::query()->firstOrCreate(
                ['email' => $profile['guardian_email']],
                ['name' => $profile['guardian_name'], 'role' => 'guardian', 'password' => Hash::make(self::DEMO_PASSWORD)],
            );

            $this->seedStudentDataset($student, $profile['seed_type'], $teachers, $principal, $counselor);
        }

        // ── Bulk cohort: 5 per class/section = 50 students, deliberately varied ──
        // Each index maps to a seed type so the distribution is deliberate, not random.
        $bulkSeedTypes = [
            1  => 'good',            2  => 'watch',           3  => 'good',
            4  => 'warning',         5  => 'good',            6  => 'recovering',
            7  => 'watch',           8  => 'good',            9  => 'attendance_crisis',
            10 => 'good',            11 => 'urgent',          12 => 'watch',
            13 => 'strong',          14 => 'good',            15 => 'warning',
            16 => 'watch',           17 => 'good',            18 => 'academic_crisis',
            19 => 'good',            20 => 'watch',           21 => 'good',
            22 => 'urgent',          23 => 'recovering',      24 => 'watch',
            25 => 'good',            26 => 'strong',          27 => 'warning',
            28 => 'good',            29 => 'attendance_crisis', 30 => 'watch',
            31 => 'good',            32 => 'watch',           33 => 'urgent',
            34 => 'good',            35 => 'warning',         36 => 'good',
            37 => 'academic_crisis', 38 => 'watch',           39 => 'good',
            40 => 'strong',          41 => 'recovering',      42 => 'watch',
            43 => 'good',            44 => 'urgent',          45 => 'good',
            46 => 'warning',         47 => 'watch',           48 => 'good',
            49 => 'strong',          50 => 'good',
        ];

        $periods = collect(range(5, 0))->map(
            fn (int $m) => now()->subMonths($m)->format('Y-m')
        );

        $classes  = ['6', '7', '8', '9', '10'];
        $sections = ['A', 'B'];
        $studentIndex = 1;

        foreach ($classes as $className) {
            foreach ($sections as $section) {
                foreach (range(1, 5) as $roll) {
                    $studentName = $this->generatedName($studentIndex);
                    $guardianName = $this->generatedName($studentIndex + 17);

                    $student = Student::query()->create([
                        'student_code'             => sprintf('PPS-%03d', $studentIndex),
                        'name'                     => $studentName,
                        'class_name'               => $className,
                        'section'                  => $section,
                        'roll_number'              => $roll,
                        'admission_date'           => now()->subYears(rand(1, 4))->startOfYear(),
                        'guardian_name'            => $guardianName,
                        'guardian_phone'           => '+880' . (string) rand(1700000000, 1999999999),
                        'guardian_email'           => sprintf('guardian.bulk%03d@pps.local', $studentIndex),
                        'private_tuition_subjects' => $studentIndex % 4 === 0 ? [
                            ['subject' => 'Mathematics', 'hours_per_week' => 3, 'tutor_name' => 'Home Tutor'],
                        ] : [],
                        'private_tuition_notes'    => $studentIndex % 4 === 0 ? 'Weekly home tuition in mathematics.' : null,
                        'family_status'            => match ($studentIndex % 8) {
                            0 => 'single parent',
                            3 => 'guardian works abroad',
                            default => 'stable',
                        },
                        'economic_status'          => $studentIndex % 7 === 0 ? 'scholarship supported' : 'standard',
                        'scholarship_status'       => $studentIndex % 7 === 0 ? 'partial scholarship' : null,
                        'health_notes'             => $studentIndex % 9 === 0 ? 'Seasonal asthma noted.' : null,
                        'allergies'                => $studentIndex % 11 === 0 ? 'Dust' : null,
                        'medications'              => $studentIndex % 9 === 0 ? 'Inhaler when needed' : null,
                        'residence_change_note'    => $studentIndex % 10 === 0 ? 'Moved to a new neighbourhood this term.' : null,
                        'special_needs'            => $studentIndex % 13 === 0 ? ['dyslexia_support'] : [],
                        'confidential_context'     => $studentIndex % 12 === 0 ? 'Home stress reported; counselor notified.' : null,
                    ]);

                    User::query()->firstOrCreate(
                        ['email' => $student->guardian_email],
                        ['name' => $student->guardian_name ?? 'Guardian', 'role' => 'guardian', 'password' => Hash::make(self::DEMO_PASSWORD)],
                    );

                    $seedType = $bulkSeedTypes[$studentIndex] ?? 'good';
                    $this->seedStudentDataset($student, $seedType, $teachers, $principal, $counselor, $studentIndex, $periods);
                    $studentIndex++;
                }
            }
        }
    }

    private function generatedName(int $seed): string
    {
        $given = self::GIVEN_NAMES[$seed % count(self::GIVEN_NAMES)];
        $family = self::FAMILY_NAMES[$seed % count(self::FAMILY_NAMES)];

        return $given.' '.$family;
    }

    // ── Student dataset ────────────────────────────────────────────────────────

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
            fn (int $m) => now()->subMonths($m)->format('Y-m')
        );

        foreach ($periodSeries as $position => $period) {
            PerformanceSnapshot::query()->create(
                $this->snapshotFor($student, $seedType, $period, $position)
            );
        }

        $currentSnapshot = PerformanceSnapshot::query()
            ->where('student_id', $student->id)
            ->where('snapshot_period', now()->format('Y-m'))
            ->first();

        if ($currentSnapshot && $currentSnapshot->alert_level !== 'none') {
            $alert = PpsAlert::query()->create([
                'student_id'      => $student->id,
                'snapshot_period' => $currentSnapshot->snapshot_period,
                'alert_level'     => $currentSnapshot->alert_level,
                'trigger_reasons' => $this->triggerReasonsFor($currentSnapshot->alert_level, $seedType, $student->id),
                'notified_to'     => $this->notifiedToFor($currentSnapshot->alert_level),
                'created_at'      => Carbon::now()->subHours($studentIndex ?? 1),
                'updated_at'      => Carbon::now()->subHours($studentIndex ?? 1),
            ]);

            if (in_array($currentSnapshot->alert_level, ['urgent', 'warning'], true)) {
                CounselingSession::query()->create([
                    'student_id'      => $student->id,
                    'counselor_id'    => $counselor->id,
                    'referred_by'     => $principal->id,
                    'alert_id'        => $alert->id,
                    'session_date'    => now()->subDays(min($studentIndex ?? 6, 12))->toDateString(),
                    'session_type'    => 'initial',
                    'session_notes'   => $this->counselingNote($seedType),
                    'action_plan'     => $this->actionPlan($seedType),
                    'next_session_date' => now()->addDays(rand(5, 14))->toDateString(),
                    'progress_status' => $currentSnapshot->alert_level === 'urgent' ? 'stable' : 'improving',
                ]);

                if (($studentIndex ?? 2) % 2 === 0) {
                    CounselingSession::query()->create([
                        'student_id'           => $student->id,
                        'counselor_id'         => $counselor->id,
                        'session_date'         => now()->subDays(min($studentIndex ?? 6, 10))->toDateString(),
                        'session_type'         => 'psychometric',
                        'assessment_tool'      => 'PPS wellbeing checklist v2',
                        'session_notes'        => 'Structured psychometric screening completed.',
                        'progress_status'      => 'stable',
                        'psychometric_scores'  => [
                            'self_confidence'      => max(30, 78 - ($studentIndex ?? 6)),
                            'anxiety_level'        => min(82, 28 + ($studentIndex ?? 6)),
                            'social_skills'        => max(35, 74 - ($studentIndex ?? 6)),
                            'emotional_regulation' => max(32, 76 - ($studentIndex ?? 6)),
                            'notes'                => 'Scores indicate a need for structured monitoring.',
                        ],
                        'special_needs_profile' => $student->special_needs ?? [],
                    ]);
                }
            }
        }

        $this->seedDomainRecords($student, $seedType, $teachers, $studentIndex);
    }

    // ── Performance snapshot with per-student noise ────────────────────────────

    private function snapshotFor(Student $student, string $seedType, string $period, int $position): array
    {
        $drift = $position * 1.8;
        $id    = $student->id;

        // Deterministic per-student noise (no rand → reproducible on every seed run)
        $aN = (int) (($id * 13 + 7)  % 21) - 10;  // academic     ±10
        $tN = (int) (($id * 11 + 3)  % 17) - 8;   // attendance   ±8
        $bN = (int) (($id * 7  + 11) % 15) - 7;   // behavior     ±7
        $pN = (int) (($id * 17 + 5)  % 13) - 6;   // participation±6
        $eN = (int) (($id * 19 + 9)  % 11) - 5;   // extra        ±5

        $blueprint = match ($seedType) {
            'urgent' => [
                'academic'      => 64.0 - ($drift * 3.2) + $aN,
                'attendance'    => 84.0 - ($drift * 5.8) + $tN,
                'behavior'      => 76.0 - ($drift * 3.6) + $bN,
                'participation' => 68.0 - ($drift * 4.0) + $pN,
                'extra'         => 62.0 - ($drift * 2.0) + $eN,
            ],
            'warning' => [
                'academic'      => 72.0 - ($drift * 2.2) + $aN,
                'attendance'    => 90.0 - ($drift * 3.4) + $tN,
                'behavior'      => 82.0 - ($drift * 2.4) + $bN,
                'participation' => 72.0 - ($drift * 2.8) + $pN,
                'extra'         => 65.0 - ($drift * 1.2) + $eN,
            ],
            'watch' => [
                'academic'      => 76.0 - ($drift * 1.4) + $aN,
                'attendance'    => 93.0 - ($drift * 1.6) + $tN,
                'behavior'      => 84.0 - ($drift * 1.0) + $bN,
                'participation' => 75.0 - ($drift * 1.2) + $pN,
                'extra'         => 66.0 - ($drift * 0.6) + $eN,
            ],
            'strong' => [
                'academic'      => min(98, 88.0 + ($drift * 0.5) + abs($aN * 0.4)),
                'attendance'    => min(98, 97.0 + ($drift * 0.2) + abs($tN * 0.2)),
                'behavior'      => min(98, 93.0 + ($drift * 0.3) + abs($bN * 0.3)),
                'participation' => min(98, 88.0 + ($drift * 0.4) + abs($pN * 0.4)),
                'extra'         => min(98, 84.0 + ($drift * 0.3) + abs($eN * 0.3)),
            ],
            'recovering' => [
                // Starts bad (position 0 = 5 months ago), ends good (position 5 = now)
                'academic'      => 50.0 + ($drift * 3.8) + $aN,
                'attendance'    => 62.0 + ($drift * 3.4) + $tN,
                'behavior'      => 62.0 + ($drift * 2.8) + $bN,
                'participation' => 54.0 + ($drift * 3.2) + $pN,
                'extra'         => 52.0 + ($drift * 1.8) + $eN,
            ],
            'attendance_crisis' => [
                // Good academic, collapsing attendance
                'academic'      => 80.0 + ($drift * 0.4) + $aN,
                'attendance'    => 68.0 - ($drift * 5.4) + $tN,
                'behavior'      => 78.0 - ($drift * 0.8) + $bN,
                'participation' => 68.0 - ($drift * 1.2) + $pN,
                'extra'         => 65.0 - ($drift * 0.5) + $eN,
            ],
            'academic_crisis' => [
                // Good attendance, failing academics
                'academic'      => 52.0 - ($drift * 2.8) + $aN,
                'attendance'    => 93.0 - ($drift * 0.4) + $tN,
                'behavior'      => 74.0 - ($drift * 1.0) + $bN,
                'participation' => 62.0 - ($drift * 1.6) + $pN,
                'extra'         => 64.0 - ($drift * 0.4) + $eN,
            ],
            default => [ // 'good'
                'academic'      => 74.0 + ($drift * 1.2) + $aN,
                'attendance'    => 91.0 + ($drift * 0.7) + $tN,
                'behavior'      => 83.0 + ($drift * 0.9) + $bN,
                'participation' => 69.0 + ($drift * 1.1) + $pN,
                'extra'         => 61.0 + ($drift * 0.8) + $eN,
            ],
        };

        $scores = collect($blueprint)->map(fn (float $s) => round(max(25.0, min(98.0, $s)), 2));

        $overall = round(
            ($scores['academic']      * 0.40) +
            ($scores['attendance']    * 0.20) +
            ($scores['behavior']      * 0.15) +
            ($scores['participation'] * 0.15) +
            ($scores['extra']         * 0.10),
            2
        );

        $bonus = match ($seedType) {
            'urgent'            => 12,
            'warning'           => 8,
            'attendance_crisis' => 8,
            'academic_crisis'   => 8,
            'watch'             => 2,
            default             => 0,
        };

        $risk = round(min(100.0, max(0.0,
            (100 - $scores['academic'])      * 0.28 +
            (100 - $scores['attendance'])    * 0.34 +
            (100 - $scores['behavior'])      * 0.18 +
            (100 - $scores['participation']) * 0.12 +
            $bonus
        )), 2);

        $alertLevel = match (true) {
            $risk >= 70 => 'urgent',
            $risk >= 40 => 'warning',
            $risk >= 20 => 'watch',
            default     => 'none',
        };

        $trend = match (true) {
            $seedType === 'urgent'   && $position >= 4                                     => 'rapid_down',
            in_array($seedType, ['urgent', 'warning', 'attendance_crisis', 'academic_crisis'], true) && $position >= 3 => 'down',
            in_array($seedType, ['recovering', 'good', 'strong'], true) && $position >= 3 => 'up',
            default => 'stable',
        };

        return [
            'student_id'           => $student->id,
            'snapshot_period'      => $period,
            'academic_score'       => $scores['academic'],
            'attendance_score'     => $scores['attendance'],
            'behavior_score'       => $scores['behavior'],
            'participation_score'  => $scores['participation'],
            'extracurricular_score'=> $scores['extra'],
            'overall_score'        => $overall,
            'risk_score'           => $risk,
            'alert_level'          => $alertLevel,
            'trend_direction'      => $trend,
            'snapshot_data'        => [
                'subjects' => [
                    'Mathematics' => ['avg' => round(max(25, $scores['academic'] - 8 + (($id * 3) % 7) - 3), 1), 'count' => 3, 'trend' => []],
                    'English'     => ['avg' => round(max(30, $scores['academic'] + 2 + (($id * 5) % 7) - 3), 1), 'count' => 2, 'trend' => []],
                    'Science'     => ['avg' => round(max(30, $scores['academic'] + 5 + (($id * 7) % 9) - 4), 1), 'count' => 2, 'trend' => []],
                    'Bangla'      => ['avg' => round(max(30, $scores['academic'] + 4 + (($id * 9) % 7) - 3), 1), 'count' => 2, 'trend' => []],
                ],
                'attendance' => [
                    'total'  => 22,
                    'absent' => max(0, (int) round((100 - $scores['attendance']) / 5.5)),
                    'late'   => max(0, (int) round((100 - $scores['attendance']) / 11)),
                ],
                'cards' => [
                    'green'  => in_array($seedType, ['good', 'strong', 'recovering'], true) ? (int) round(1 + abs($eN) / 5) : 0,
                    'yellow' => in_array($seedType, ['warning', 'watch', 'attendance_crisis', 'academic_crisis'], true) ? max(0, (int) round(abs($bN) / 4)) : 0,
                    'red'    => in_array($seedType, ['urgent'], true) ? 1 : 0,
                ],
            ],
            'calculated_at' => Carbon::createFromFormat('Y-m', $period)->endOfMonth(),
            'created_at'    => Carbon::createFromFormat('Y-m', $period)->endOfMonth(),
            'updated_at'    => Carbon::createFromFormat('Y-m', $period)->endOfMonth(),
        ];
    }

    // ── Domain records ─────────────────────────────────────────────────────────

    private function seedDomainRecords(Student $student, string $seedType, $teachers, ?int $idx = null): void
    {
        $id = $student->id;

        $subjects = [
            ['name' => 'Mathematics', 'teacher' => $teachers[0]],
            ['name' => 'English',     'teacher' => $teachers[1]],
            ['name' => 'Science',     'teacher' => $teachers[2]],
            ['name' => 'Bangla',      'teacher' => $teachers[3]],
        ];

        // Three months of assessments (more data = better analytics)
        foreach ([-2, -1, 0] as $monthOffset) {
            $date = now()->copy()->addMonths($monthOffset);

            foreach ($subjects as $subject) {
                $score = $this->seedScoreForSubject($seedType, $subject['name'], $monthOffset, $id);

                Assessment::query()->create([
                    'student_id'     => $student->id,
                    'teacher_id'     => $subject['teacher']->id,
                    'subject'        => $subject['name'],
                    'assessment_type'=> $monthOffset === 0 ? 'class_test' : ($monthOffset === -1 ? 'midterm' : 'assignment'),
                    'term'           => sprintf('%s-term-%d', $date->format('Y'), $monthOffset === -2 ? 1 : 2),
                    'marks_obtained' => $score,
                    'total_marks'    => 100,
                    'percentage'     => $score,
                    'exam_date'      => $date->copy()->day(match ($subject['name']) {
                        'Mathematics' => 10 + (($id * 3) % 5),
                        'English'     => 14 + (($id * 5) % 5),
                        'Science'     => 18 + (($id * 7) % 5),
                        default       => 8 + (($id * 11) % 5),
                    })->toDateString(),
                    'remarks' => $this->assessmentRemark($seedType, $subject['name']),
                ]);
            }

            ClassroomRating::query()->create([
                'student_id'     => $student->id,
                'teacher_id'     => $teachers[((($id + $monthOffset) % count($teachers->all()) + count($teachers->all())) % count($teachers->all()))]->id,
                'subject'        => $subjects[((($id + $monthOffset) % count($subjects) + count($subjects)) % count($subjects))]['name'],
                'rating_period'  => $date->copy()->startOfMonth()->addDays(6)->toDateString(),
                'period_type'    => 'monthly',
                'participation'  => $this->seedRatingValue($seedType, 'participation', $id),
                'attentiveness'  => $this->seedRatingValue($seedType, 'attentiveness', $id),
                'group_work'     => $this->seedRatingValue($seedType, 'group_work', $id),
                'creativity'     => $this->seedRatingValue($seedType, 'creativity', $id),
                'behavioral_flag'=> $this->behaviorFlag($seedType, $monthOffset, $id),
                'free_comment'   => $this->seedComment($seedType, $id),
                'created_at'     => $date->copy()->startOfMonth()->addDays(6),
            ]);
        }

        // Attendance: current month with pattern matching seed type
        foreach (range(1, 22) as $day) {
            $status = $this->attendanceStatus($seedType, $day, $id);

            AttendanceRecord::query()->create([
                'student_id' => $student->id,
                'date'       => now()->copy()->startOfMonth()->addDays($day - 1)->toDateString(),
                'status'     => $status,
                'marked_by'  => $teachers[$id % count($teachers->all())]->id,
                'absence_reason' => $status === 'absent' ? $this->absenceReason($seedType) : null,
            ]);
        }

        // Behavior cards
        $this->seedBehaviorCards($student, $seedType, $teachers, $id);

        // Extracurricular
        $activities = $this->extracurricularActivities($seedType, $id);
        foreach ($activities as $activity) {
            Extracurricular::query()->create([
                'student_id'       => $student->id,
                'activity_name'    => $activity['name'],
                'category'         => $activity['category'],
                'role'             => $activity['role'],
                'achievement'      => $activity['achievement'],
                'achievement_level'=> $activity['level'],
                'event_date'       => now()->copy()->subDays($activity['days_ago'])->toDateString(),
                'notes'            => 'Seeded extracurricular record.',
            ]);
        }
    }

    private function attendanceStatus(string $seedType, int $day, int $id): string
    {
        $absentDays = match ($seedType) {
            'urgent'            => [3, 7, 9, 13, 15, 18, 21],
            'attendance_crisis' => [2, 5, 8, 11, 14, 17, 19, 22],
            'warning'           => [6, 13, 19],
            'watch'             => [$id % 20 + 2],
            'recovering'        => [4, 16],
            default             => [],
        };

        $lateDays = match ($seedType) {
            'urgent'            => [5, 11, 17],
            'attendance_crisis' => [4, 10, 16, 20],
            'warning'           => [9, 17],
            'academic_crisis'   => [],  // comes every day, on time
            default             => [],
        };

        if (in_array($day, $absentDays, true)) {
            return 'absent';
        }

        if (in_array($day, $lateDays, true)) {
            return 'late';
        }

        return 'present';
    }

    private function seedBehaviorCards(Student $student, string $seedType, $teachers, int $id): void
    {
        $cards = match ($seedType) {
            'urgent' => [
                ['type' => 'red',    'reason' => 'Repeated disruption during morning assembly.',      'days_ago' => 6],
                ['type' => 'yellow', 'reason' => 'Incomplete homework for the third consecutive day.', 'days_ago' => 14],
            ],
            'attendance_crisis' => [
                ['type' => 'yellow', 'reason' => 'Unexplained absence without prior notification.',    'days_ago' => 5],
            ],
            'academic_crisis' => [
                ['type' => 'yellow', 'reason' => 'Failed to submit assignment despite reminders.',     'days_ago' => 8],
            ],
            'warning' => [
                ['type' => 'yellow', 'reason' => 'Attention and focus noticeably lower this period.',  'days_ago' => 7],
            ],
            'watch' => [
                ['type' => 'green',  'reason' => 'Volunteered to assist a struggling classmate.',      'days_ago' => 4],
            ],
            'recovering' => [
                ['type' => 'green',  'reason' => 'Significant improvement in class participation.',    'days_ago' => 3],
                ['type' => 'green',  'reason' => 'Completed all assignments on time this month.',      'days_ago' => 18],
            ],
            'strong' => [
                ['type' => 'green',  'reason' => 'Demonstrated exceptional leadership in group work.', 'days_ago' => 5],
                ['type' => 'green',  'reason' => 'Helped three classmates prepare for the midterm.',   'days_ago' => 20],
            ],
            default => [
                ['type' => 'green',  'reason' => 'Consistent collaborative effort across all classes.','days_ago' => 4],
            ],
        };

        foreach ($cards as $card) {
            BehaviorCard::query()->create([
                'student_id' => $student->id,
                'issued_by'  => $teachers[$id % count($teachers->all())]->id,
                'card_type'  => $card['type'],
                'reason'     => $card['reason'],
                'issued_at'  => now()->copy()->subDays($card['days_ago']),
            ]);
        }
    }

    private function extracurricularActivities(string $seedType, int $id): array
    {
        $pool = [
            'urgent' => [
                ['name' => 'Debate Club',       'category' => 'club',    'role' => 'inactive member', 'achievement' => null, 'level' => 1, 'days_ago' => 30],
            ],
            'attendance_crisis' => [
                ['name' => 'Art Club',          'category' => 'club',    'role' => 'member',           'achievement' => null, 'level' => 2, 'days_ago' => 14],
            ],
            'academic_crisis' => [
                ['name' => 'Football',          'category' => 'sports',  'role' => 'player',           'achievement' => 'Participation award', 'level' => 2, 'days_ago' => 10],
            ],
            'warning' => [
                ['name' => 'Science Club',      'category' => 'club',    'role' => 'member',           'achievement' => null, 'level' => 2, 'days_ago' => 12],
            ],
            'watch' => [
                ['name' => 'Drama Club',        'category' => 'club',    'role' => 'participant',      'achievement' => 'Participation award', 'level' => 2, 'days_ago' => 8],
            ],
            'recovering' => [
                ['name' => 'Reading Circle',    'category' => 'club',    'role' => 'member',           'achievement' => 'Monthly mention', 'level' => 3, 'days_ago' => 6],
                ['name' => 'Mathematics Club',  'category' => 'club',    'role' => 'active member',    'achievement' => null, 'level' => 2, 'days_ago' => 20],
            ],
            'strong' => [
                ['name' => 'Science Olympiad',  'category' => 'academic','role' => 'competitor',       'achievement' => 'District runner-up', 'level' => 4, 'days_ago' => 15],
                ['name' => 'Student Council',   'category' => 'leadership','role' => 'class representative','achievement' => null, 'level' => 3, 'days_ago' => 30],
            ],
            'good' => [
                ['name' => 'Science Club',      'category' => 'club',    'role' => 'member',           'achievement' => 'Active participation', 'level' => 3, 'days_ago' => 7],
            ],
        ];

        return $pool[$seedType] ?? $pool['good'];
    }

    // ── Score helpers ──────────────────────────────────────────────────────────

    private function seedScoreForSubject(string $seedType, string $subject, int $monthOffset, int $studentId = 0): int
    {
        $base = match ($seedType) {
            'urgent'            => 40,
            'warning'           => 57,
            'watch'             => 66,
            'strong'            => 90,
            'recovering'        => 56,
            'attendance_crisis' => 76,
            'academic_crisis'   => 42,
            default             => 76,
        };

        $subjectOffset = match ($subject) {
            'Mathematics' => -7 + (($studentId * 3) % 9) - 4,  // typically harder, but varies
            'English'     => 2  + (($studentId * 5) % 7) - 3,
            'Science'     => 5  + (($studentId * 7) % 9) - 4,
            'Bangla'      => 4  + (($studentId * 9) % 7) - 3,
            default       => (($studentId * 11) % 9) - 4,
        };

        $studentNoise = $studentId > 0 ? (int) (($studentId * 11 + 5) % 17) - 8 : 0;
        $trendBump    = $monthOffset * 4;

        return max(25, min(98, $base + $subjectOffset + $trendBump + $studentNoise));
    }

    private function seedRatingValue(string $seedType, string $dimension, int $id = 0): int
    {
        $base = match ($seedType) {
            'urgent'            => 2,
            'attendance_crisis' => 3,
            'academic_crisis'   => 2,
            'warning'           => 3,
            'watch'             => 3,
            'recovering'        => 4,
            'strong'            => 5,
            default             => 4,
        };

        $dimensionBonus = match ($dimension) {
            'creativity' => $seedType === 'strong' ? 0 : ($seedType === 'urgent' ? 0 : 0),
            'group_work' => $seedType === 'recovering' ? 1 : 0,
            default      => 0,
        };

        $idNoise = $id > 0 ? (int) (($id * 7 + $dimensionBonus + 3) % 3) - 1 : 0;

        return min(5, max(1, $base + $dimensionBonus + $idNoise));
    }

    private function behaviorFlag(string $seedType, int $monthOffset, int $id): ?string
    {
        if ($monthOffset !== 0) {
            return null;
        }

        return match ($seedType) {
            'urgent'         => ['withdrawn', 'disruptive', 'disengaged'][$id % 3],
            'academic_crisis'=> 'struggling',
            'warning'        => $id % 3 === 0 ? 'distracted' : null,
            default          => null,
        };
    }

    private function assessmentRemark(string $seedType, string $subject): ?string
    {
        if (in_array($seedType, ['good', 'strong', 'recovering'], true)) {
            return null;
        }

        return match ($seedType) {
            'urgent'         => "Requires individual support plan for {$subject}.",
            'attendance_crisis' => null,
            'academic_crisis'=> "Consistent below-average performance in {$subject}. Review recommended.",
            'warning'        => "Declining trend in {$subject} over two consecutive assessments.",
            default          => null,
        };
    }

    private function absenceReason(string $seedType): string
    {
        return match ($seedType) {
            'urgent'            => 'No reason provided',
            'attendance_crisis' => 'No notification from guardian',
            default             => 'Reported sick',
        };
    }

    // ── Trigger reasons: varied per student ───────────────────────────────────

    private function triggerReasonsFor(string $alertLevel, string $seedType, int $studentId): array
    {
        $variant = $studentId % 3;

        if ($alertLevel === 'urgent') {
            return match ($seedType) {
                'attendance_crisis' => [
                    ['type' => 'critical_attendance', 'detail' => 'Attendance fell below 60% this period.', 'value' => rand(42, 59)],
                    ['type' => 'guardian_unresponsive', 'detail' => 'Guardian contact attempts went unanswered.', 'value' => 3],
                ],
                default => match ($variant) {
                    0 => [
                        ['type' => 'combined_drop', 'detail' => 'Academic, attendance, and behavior declined together.', 'value' => 3],
                        ['type' => 'critical_attendance', 'detail' => 'Attendance crossed the urgent threshold.', 'value' => rand(48, 62)],
                    ],
                    1 => [
                        ['type' => 'academic_collapse', 'detail' => 'Academic score dropped below 40% this period.', 'value' => rand(32, 39)],
                        ['type' => 'behavior_flag', 'detail' => 'Multiple classroom behavior flags recorded.', 'value' => 2],
                    ],
                    default => [
                        ['type' => 'rapid_decline', 'detail' => 'Score dropped more than 18 points in 60 days.', 'value' => rand(18, 24)],
                        ['type' => 'low_participation', 'detail' => 'Participation dropped sharply and is now critically low.', 'value' => rand(28, 38)],
                    ],
                },
            };
        }

        if ($alertLevel === 'warning') {
            return match ($seedType) {
                'academic_crisis' => [
                    ['type' => 'academic_drop', 'detail' => 'Multiple subjects below 50% with consistent decline.', 'value' => rand(38, 50)],
                    ['type' => 'participation_drop', 'detail' => 'Classroom engagement declining across subjects.', 'value' => rand(45, 58)],
                ],
                'attendance_crisis' => [
                    ['type' => 'low_attendance', 'detail' => 'Attendance below the warning threshold this month.', 'value' => rand(63, 74)],
                ],
                default => match ($variant) {
                    0 => [
                        ['type' => 'academic_drop', 'detail' => 'Academic trend fell across two consecutive periods.', 'value' => rand(12, 18)],
                        ['type' => 'low_attendance', 'detail' => 'Attendance is below the warning threshold.', 'value' => rand(64, 74)],
                    ],
                    1 => [
                        ['type' => 'behavior_pattern', 'detail' => 'Yellow cards issued in two of the last three months.', 'value' => 2],
                        ['type' => 'academic_drop', 'detail' => 'Exam scores declining month on month.', 'value' => rand(10, 16)],
                    ],
                    default => [
                        ['type' => 'composite_decline', 'detail' => 'All monitored dimensions moved slightly downward.', 'value' => rand(4, 8)],
                    ],
                },
            };
        }

        return [
            ['type' => 'watchlist', 'detail' => 'Small but consistent decline detected over the last 30 days.', 'value' => 1],
        ];
    }

    private function notifiedToFor(string $alertLevel): array
    {
        $targets = [['role' => 'class_teacher', 'channel' => 'database']];

        if (in_array($alertLevel, ['warning', 'urgent'], true)) {
            $targets[] = ['role' => 'principal', 'channel' => 'database'];
            $targets[] = ['role' => 'guardian',  'channel' => 'sms'];
        }

        if ($alertLevel === 'urgent') {
            $targets[] = ['role' => 'counselor', 'channel' => 'database'];
            $targets[] = ['role' => 'guardian',  'channel' => 'email'];
        }

        return $targets;
    }

    // ── Counseling helpers ─────────────────────────────────────────────────────

    private function counselingNote(string $seedType): string
    {
        return match ($seedType) {
            'urgent'            => 'Initial session completed. Student was withdrawn and non-communicative. Close monitoring required.',
            'attendance_crisis' => 'Student expressed anxiety about falling behind. Guardian awareness is key next step.',
            'academic_crisis'   => 'Assessment difficulty appears to stem from foundational gaps rather than disengagement.',
            'warning'           => 'Student acknowledged a drop in performance. Agreed on a focus plan with teacher.',
            default             => 'Routine check-in completed. No immediate concerns, monitoring continues.',
        };
    }

    private function actionPlan(string $seedType): string
    {
        return match ($seedType) {
            'urgent'            => 'Daily teacher check-in, weekly counselor session, guardian meeting within 7 days.',
            'attendance_crisis' => 'Guardian contact every absence, attendance contract signed, weekly review.',
            'academic_crisis'   => 'Learning support referral, subject-specific catch-up sessions, fortnightly review.',
            'warning'           => 'Bi-weekly teacher follow-up, guardian check-in, peer support pairing.',
            default             => 'Monthly monitoring review.',
        };
    }

    // ── Comments ───────────────────────────────────────────────────────────────

    private function seedComment(string $seedType, int $id = 0): string
    {
        $variant = $id % 4;

        $comments = match ($seedType) {
            'urgent' => [
                'Shows visible disengagement. Daily structured follow-up is strongly advised.',
                'Student is struggling to keep pace. Recommend referral for additional support.',
                'Motivation appears very low. One-to-one sessions recommended before next month.',
                'Consistent pattern of disengagement across subjects. Counselor input needed.',
            ],
            'attendance_crisis' => [
                'Strong academic grasp when present, but absences are disrupting continuity.',
                'Capable student whose frequent absence is eroding progress.',
                'Perform well in class but attendance gaps are becoming a concern.',
                'Regular absence is creating significant gaps despite evident ability.',
            ],
            'academic_crisis' => [
                'Attends diligently but struggles to retain content. Learning support recommended.',
                'Good attitude and effort, but outcomes remain consistently below expectation.',
                'Comes prepared and engaged, yet written assessments show persistent difficulty.',
                'Willingness is not in doubt; underlying learning gaps need professional review.',
            ],
            'warning' => [
                'Performance is mixed this period. A tighter feedback loop would help.',
                'Shows potential but needs more consistent effort across all subjects.',
                'Some positive signs, but the overall trend warrants structured support.',
                'Capable of more. A motivational conversation with guardian is recommended.',
            ],
            'watch' => [
                'Generally stable, though confidence dips on more challenging tasks.',
                'Performing adequately; keep an eye on participation levels.',
                'Moderate progress. No immediate concern but worth monitoring.',
                'Steady, with occasional inconsistency on test days.',
            ],
            'recovering' => [
                'Excellent progress this period. The improvement is sustained and meaningful.',
                'Student has responded very well to the support plan. Clear upward trend.',
                'Remarkable turnaround. Now performing consistently above their earlier baseline.',
                'Confidence has returned. Engagement is strong and results are showing it.',
            ],
            'strong' => [
                'Exceptional work ethic and consistently outstanding results.',
                'A model student who elevates classroom discussion for everyone.',
                'Demonstrates deep understanding and strong peer leadership.',
                'Outstanding across all dimensions. A positive influence in class.',
            ],
            default => [
                'Participates well and responds positively to challenge.',
                'Solid contribution to class activities. On track.',
                'Good engagement and consistent effort throughout the period.',
                'Performing steadily. No concerns at this time.',
            ],
        };

        return $comments[$variant] ?? $comments[0];
    }

    // ── Teacher assignments ────────────────────────────────────────────────────

    private function seedTeacherAssignments($teachers): void
    {
        $matrix = [
            [
                'teacher' => $teachers[0], // Mariam Rahman - Math
                'assignments' => [
                    ['class_name' => '8', 'section' => 'A', 'subject' => 'Mathematics', 'is_class_teacher' => true],
                    ['class_name' => '9', 'section' => 'A', 'subject' => 'Mathematics', 'is_class_teacher' => false],
                    ['class_name' => '10','section' => 'A', 'subject' => 'Mathematics', 'is_class_teacher' => false],
                    ['class_name' => '10','section' => 'B', 'subject' => 'Mathematics', 'is_class_teacher' => false],
                ],
            ],
            [
                'teacher' => $teachers[1], // Sabbir Hasan - English
                'assignments' => [
                    ['class_name' => '7', 'section' => 'B', 'subject' => 'English', 'is_class_teacher' => true],
                    ['class_name' => '8', 'section' => 'A', 'subject' => 'English', 'is_class_teacher' => false],
                    ['class_name' => '9', 'section' => 'B', 'subject' => 'English', 'is_class_teacher' => false],
                ],
            ],
            [
                'teacher' => $teachers[2], // Tahmina Akter - Science
                'assignments' => [
                    ['class_name' => '6', 'section' => 'A', 'subject' => 'Science', 'is_class_teacher' => true],
                    ['class_name' => '8', 'section' => 'A', 'subject' => 'Science', 'is_class_teacher' => false],
                    ['class_name' => '10','section' => 'A', 'subject' => 'Science', 'is_class_teacher' => false],
                ],
            ],
            [
                'teacher' => $teachers[3], // Jalal Uddin - Bangla
                'assignments' => [
                    ['class_name' => '6', 'section' => 'B', 'subject' => 'Bangla', 'is_class_teacher' => true],
                    ['class_name' => '7', 'section' => 'A', 'subject' => 'Bangla', 'is_class_teacher' => false],
                    ['class_name' => '8', 'section' => 'B', 'subject' => 'Bangla', 'is_class_teacher' => false],
                ],
            ],
            [
                'teacher' => $teachers[4], // Nargis Sultana - Social Studies
                'assignments' => [
                    ['class_name' => '9', 'section' => 'B', 'subject' => 'Social Studies', 'is_class_teacher' => true],
                    ['class_name' => '10','section' => 'B', 'subject' => 'Social Studies', 'is_class_teacher' => false],
                    ['class_name' => '7', 'section' => 'A', 'subject' => 'Social Studies', 'is_class_teacher' => false],
                ],
            ],
        ];

        foreach ($matrix as $row) {
            foreach ($row['assignments'] as $assignment) {
                TeacherAssignment::query()->updateOrCreate(
                    [
                        'teacher_id' => $row['teacher']->id,
                        'class_name' => $assignment['class_name'],
                        'section'    => $assignment['section'],
                        'subject'    => $assignment['subject'],
                    ],
                    ['is_class_teacher' => $assignment['is_class_teacher']],
                );
            }
        }
    }
}

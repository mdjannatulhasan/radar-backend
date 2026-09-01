<?php

namespace Database\Seeders;

use App\Models\Pps\AttendanceRecord;
use App\Models\Pps\BehaviorCard;
use App\Models\Pps\ClassroomRating;
use App\Models\Pps\CounselingSession;
use App\Models\Pps\Extracurricular;
use App\Models\Pps\Mark;
use App\Models\Pps\PpsAlert;
use App\Models\Pps\PerformanceSnapshot;
use App\Models\Pps\SchoolPpsConfig;
use App\Models\Pps\TeacherAssignment;
use SmsCore\Models\Student;
use SmsCore\Models\Teacher;
use SmsCore\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class PpsDemoSeeder extends Seeder
{
    public const DEMO_PASSWORD = 'PpsDemo2026!';

    /** The school every seeded row belongs to. sms-core tables all carry it. */
    private int $schoolId;

    /**
     * The teachers rows behind the demo teacher logins. users.id is NOT a
     * teacher id any more: pps_classroom_ratings.teacher_id and
     * pps_teacher_assignments.teacher_id both point at teachers.
     *
     * @var Collection<int, Teacher>
     */
    private Collection $teacherRecords;

    /**
     * Exam component x subject pairs an enrolled student can be scored on,
     * memoised per class_level. Replaces the free-text pps_assessments rows the
     * demo used to write: a mark is now (component, student, subject), and the
     * class it belongs to is reached through pps_exam_class_map.
     *
     * @var array<int, array<int, object>>
     */
    private array $markTargets = [];
    private const GIVEN_NAMES = [
        // Female names
        'Amina', 'Nusrat', 'Ishrat', 'Maliha', 'Samia', 'Raisa', 'Faria', 'Tuba', 'Orin', 'Sabrina',
        'Roksana', 'Mehnaz', 'Sumaiya', 'Nafisa', 'Lamia', 'Tasnim', 'Sanjida', 'Jannatul', 'Rumana', 'Halima',
        // Male names
        'Rafi', 'Hasib', 'Tanzim', 'Tahmid', 'Farhan', 'Adnan', 'Mahin', 'Shafin', 'Nafis', 'Zayan',
        'Muntasir', 'Rifat', 'Saad', 'Nabil', 'Rakib', 'Abrar', 'Mahir', 'Imran', 'Sajid', 'Towhid',
        'Arif', 'Jubayer', 'Miraz', 'Shadman', 'Tahsin', 'Rezwan', 'Fahim', 'Sumon', 'Asif', 'Ridwan',
    ];
    private const FAMILY_NAMES = [
        'Rahman', 'Islam', 'Hasan', 'Akter', 'Chowdhury', 'Karim', 'Sultana', 'Hossain', 'Ahmed', 'Kabir',
        'Molla', 'Begum', 'Miah', 'Biswas', 'Sarkar', 'Sheikh', 'Mondal', 'Talukder', 'Bhuiyan', 'Khandaker',
    ];
    private const GUARDIAN_GIVEN_NAMES = [
        'Abdur', 'Mahbub', 'Shahidul', 'Nurul', 'Mizanur', 'Rezaul', 'Habibur', 'Sirajul', 'Mostafa',
        'Jamal', 'Kamal', 'Alamgir', 'Rafiqul', 'Shamsul', 'Nazrul', 'Anwarul', 'Fazlur', 'Khairul',
        'Farida', 'Rahela', 'Morjina', 'Shahana', 'Hasina', 'Nasima', 'Bilkis', 'Jahanara', 'Kohinur',
        'Rasheda', 'Sultana', 'Monowara', 'Firoza', 'Roksana', 'Tanjina', 'Ferdousi', 'Shefali',
    ];
    private const GUARDIAN_RELATIONS = ['father', 'mother', 'grandfather', 'grandmother', 'uncle', 'aunt', 'elder brother'];
    private const GUARDIAN_OCCUPATIONS = [
        'teacher', 'business', 'government service', 'private service', 'farmer', 'doctor',
        'engineer', 'daily labour', 'works abroad', 'retired', 'NGO worker', 'shop owner',
    ];

    public function run(): void
    {
        SchoolPpsConfig::current();

        // Builds the school, levels, versions, academic year, class_levels,
        // section_names, sections, subjects and mid-term exams this seeder hangs
        // everything off. Idempotent, so calling it here keeps PpsDemoSeeder
        // runnable on its own.
        $this->call(PpsAdministrationSeeder::class);

        $this->schoolId = PpsAdministrationSeeder::school()->id;

        User::query()->firstOrCreate(['email' => 'admin@pps.local'], [
            'school_id' => $this->schoolId,
            'name' => 'System Admin', 'role' => 'admin',
            'password' => Hash::make(self::DEMO_PASSWORD),
        ]);

        $principal = User::query()->firstOrCreate(['email' => 'principal@pps.local'], [
            'school_id' => $this->schoolId,
            'name' => 'Principal User', 'role' => 'principal',
            'password' => Hash::make(self::DEMO_PASSWORD),
        ]);

        $counselor = User::query()->firstOrCreate(['email' => 'counselor@pps.local'], [
            'school_id' => $this->schoolId,
            'name' => 'Counselor User', 'role' => 'counselor',
            'password' => Hash::make(self::DEMO_PASSWORD),
        ]);

        User::query()->firstOrCreate(['email' => 'welfare@pps.local'], [
            'school_id' => $this->schoolId,
            'name' => 'Welfare Officer', 'role' => 'welfare_officer',
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
            [
                'school_id' => $this->schoolId,
                'name' => $t['name'], 'role' => 'teacher',
                'password' => Hash::make(self::DEMO_PASSWORD),
            ],
        ))->values();

        // A teacher is now a person on staff, distinct from the login. Every
        // demo teacher login gets one, because assignments and classroom
        // ratings point at it rather than at users.id.
        $this->teacherRecords = $teachers
            ->map(fn (User $user) => PpsAdministrationSeeder::teacherFor($user))
            ->values();

        $this->seedTeacherAssignments($this->teacherRecords);

        // ── 8 featured students covering every meaningful profile ──────────────
        $featured = [
            [
                'student_code' => 'PPS-DEMO-001',
                'name'         => 'Rafi Islam',
                'class_name'   => '8', 'section' => 'A', 'roll_number' => 21,
                'guardian_name' => 'Farzana Islam', 'guardian_phone' => '+8801711000001',
                'guardian_email' => 'guardian.rafi@pps.local',
                'guardian_relation' => 'mother',
                'guardian_profession' => 'Garment Worker', 'guardian_profession_category' => 'labor',
                'guardian_time_availability' => 'low',
                'second_guardian_name' => null, 'second_guardian_phone' => null,
                'second_guardian_relation' => null, 'second_guardian_profession' => null,
                'second_guardian_profession_category' => null, 'second_guardian_time_availability' => null,
                'willingness_score' => 3, 'ability_score' => 2, 'student_quadrant' => 'willing_unable',
                'economically_vulnerable' => true,
                'seed_type'    => 'urgent',
                'family_status' => 'single parent (mother)', 'economic_status' => 'scholarship supported',
                'scholarship_status' => 'partial scholarship',
                'health_notes' => 'Seasonal asthma noted. Inhaler kept at school office.',
                'special_needs' => ['dyslexia_support'],
                'private_tuition_subjects' => [
                    ['subject' => 'Mathematics', 'hours_per_week' => 3, 'tutor_name' => 'Mahin Sir'],
                ],
                'confidential_context' => 'Mother works double shifts; rarely available for school contact. Family stress noted by counselor. Dyslexia assessment pending.',
            ],
            [
                'student_code' => 'PPS-DEMO-002',
                'name'         => 'Nabila Sultana',
                'class_name'   => '7', 'section' => 'B', 'roll_number' => 7,
                'guardian_name' => 'Rezaul Karim', 'guardian_phone' => '+8801711000002',
                'guardian_email' => 'guardian.nabila@pps.local',
                'guardian_relation' => 'father',
                'guardian_profession' => 'Bank Officer', 'guardian_profession_category' => 'private_sector',
                'guardian_time_availability' => 'medium',
                'second_guardian_name' => 'Nasrin Karim', 'second_guardian_phone' => '+8801711000022',
                'second_guardian_relation' => 'mother',
                'second_guardian_profession' => 'Private College Lecturer',
                'second_guardian_profession_category' => 'education',
                'second_guardian_time_availability' => 'medium',
                'willingness_score' => 4, 'ability_score' => 3, 'student_quadrant' => 'willing_able',
                'economically_vulnerable' => false,
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
                'guardian_relation' => 'father',
                'guardian_profession' => 'Works Abroad (Remittance)', 'guardian_profession_category' => 'other',
                'guardian_time_availability' => 'low',
                'second_guardian_name' => 'Rahela Rahman', 'second_guardian_phone' => '+8801711000033',
                'second_guardian_relation' => 'mother',
                'second_guardian_profession' => 'Homemaker',
                'second_guardian_profession_category' => 'other',
                'second_guardian_time_availability' => 'high',
                'willingness_score' => 2, 'ability_score' => 3, 'student_quadrant' => 'unwilling_able',
                'economically_vulnerable' => false,
                'seed_type'    => 'watch',
                'family_status' => 'guardian works abroad', 'economic_status' => 'standard',
                'scholarship_status' => null, 'health_notes' => null,
                'special_needs' => [],
                'private_tuition_subjects' => [
                    ['subject' => 'English', 'hours_per_week' => 2, 'tutor_name' => 'Sharmin Madam'],
                ],
                'confidential_context' => 'Father works in Malaysia. Mother manages household alone. Guardian phone contact infrequent.',
            ],
            [
                'student_code' => 'PPS-DEMO-004',
                'name'         => 'Karim Hossain',
                'class_name'   => '9', 'section' => 'A', 'roll_number' => 14,
                'guardian_name' => 'Habibur Rahman', 'guardian_phone' => '+8801756789012',
                'guardian_email' => 'guardian.karim@pps.local',
                'guardian_relation' => 'father',
                'guardian_profession' => 'Businessman (Wholesale)', 'guardian_profession_category' => 'business',
                'guardian_time_availability' => 'low',
                'second_guardian_name' => 'Hosne Ara Rahman', 'second_guardian_phone' => '+8801756789099',
                'second_guardian_relation' => 'mother',
                'second_guardian_profession' => 'Homemaker',
                'second_guardian_profession_category' => 'other',
                'second_guardian_time_availability' => 'high',
                'willingness_score' => 2, 'ability_score' => 3, 'student_quadrant' => 'unwilling_able',
                'economically_vulnerable' => false,
                'seed_type'    => 'attendance_crisis',
                'family_status' => 'stable', 'economic_status' => 'standard',
                'scholarship_status' => null, 'health_notes' => 'Repeated absence with no medical certificate.',
                'special_needs' => [], 'private_tuition_subjects' => [],
                'confidential_context' => 'Guardian unreachable for two consecutive weeks. Business trips cited as reason. Student may be left unsupervised.',
            ],
            [
                'student_code' => 'PPS-DEMO-005',
                'name'         => 'Mehedi Ahmed',
                'class_name'   => '10', 'section' => 'A', 'roll_number' => 2,
                'guardian_name' => 'Kamrun Nahar', 'guardian_phone' => '+8801844332211',
                'guardian_email' => 'guardian.mehedi@pps.local',
                'guardian_relation' => 'mother',
                'guardian_profession' => 'Government School Teacher', 'guardian_profession_category' => 'education',
                'guardian_time_availability' => 'high',
                'second_guardian_name' => 'Kamal Uddin Ahmed', 'second_guardian_phone' => '+8801844332200',
                'second_guardian_relation' => 'father',
                'second_guardian_profession' => 'Engineer (Private Firm)',
                'second_guardian_profession_category' => 'private_sector',
                'second_guardian_time_availability' => 'low',
                'willingness_score' => 5, 'ability_score' => 5, 'student_quadrant' => 'willing_able',
                'economically_vulnerable' => false,
                'seed_type'    => 'strong',
                'family_status' => 'stable', 'economic_status' => 'standard',
                'scholarship_status' => null, 'health_notes' => null,
                'special_needs' => [], 'private_tuition_subjects' => [
                    ['subject' => 'Mathematics', 'hours_per_week' => 4, 'tutor_name' => 'Rahim Sir'],
                    ['subject' => 'English', 'hours_per_week' => 3, 'tutor_name' => 'Sharmin Madam'],
                ],
                'confidential_context' => null,
            ],
            [
                'student_code' => 'PPS-DEMO-006',
                'name'         => 'Lubna Chowdhury',
                'class_name'   => '8', 'section' => 'B', 'roll_number' => 6,
                'guardian_name' => 'Hosne Ara Begum', 'guardian_phone' => '+8801966543210',
                'guardian_email' => 'guardian.lubna@pps.local',
                'guardian_relation' => 'mother',
                'guardian_profession' => 'Farmer', 'guardian_profession_category' => 'agriculture',
                'guardian_time_availability' => 'high',
                'second_guardian_name' => 'Jalal Chowdhury', 'second_guardian_phone' => '+8801966543211',
                'second_guardian_relation' => 'father',
                'second_guardian_profession' => 'Farmer',
                'second_guardian_profession_category' => 'agriculture',
                'second_guardian_time_availability' => 'high',
                'willingness_score' => 4, 'ability_score' => 3, 'student_quadrant' => 'willing_able',
                'economically_vulnerable' => true,
                'seed_type'    => 'recovering',
                'family_status' => 'stable', 'economic_status' => 'scholarship supported',
                'scholarship_status' => 'full scholarship',
                'health_notes' => null, 'special_needs' => [],
                'private_tuition_subjects' => [
                    ['subject' => 'Mathematics', 'hours_per_week' => 2, 'tutor_name' => 'Rahima Madam'],
                    ['subject' => 'Science',     'hours_per_week' => 2, 'tutor_name' => 'Farhan Sir'],
                ],
                'confidential_context' => 'Was referred to counseling three months ago due to exam anxiety. Showing strong recovery. Guardian highly cooperative.',
            ],
            [
                'student_code' => 'PPS-DEMO-007',
                'name'         => 'Tasneem Hasan',
                'class_name'   => '6', 'section' => 'B', 'roll_number' => 3,
                'guardian_name' => 'Shahadat Hossain', 'guardian_phone' => '+8801612345678',
                'guardian_email' => 'guardian.tasneem@pps.local',
                'guardian_relation' => 'father',
                'guardian_profession' => 'Army Officer', 'guardian_profession_category' => 'military',
                'guardian_time_availability' => 'low',
                'second_guardian_name' => 'Fatema Hossain', 'second_guardian_phone' => '+8801612345679',
                'second_guardian_relation' => 'mother',
                'second_guardian_profession' => 'Homemaker',
                'second_guardian_profession_category' => 'other',
                'second_guardian_time_availability' => 'high',
                'willingness_score' => 3, 'ability_score' => 2, 'student_quadrant' => 'willing_unable',
                'economically_vulnerable' => false,
                'seed_type'    => 'academic_crisis',
                'family_status' => 'stable', 'economic_status' => 'standard',
                'scholarship_status' => null, 'health_notes' => null,
                'special_needs' => ['learning_difficulty'],
                'private_tuition_subjects' => [],
                'confidential_context' => 'Good attendance but failing multiple subjects. Father stationed away; mother manages alone. Learning assessment strongly recommended.',
            ],
            [
                'student_code' => 'PPS-DEMO-008',
                'name'         => 'Nusrat Karim',
                'class_name'   => '9', 'section' => 'B', 'roll_number' => 9,
                'guardian_name' => 'Mahfuz Alam', 'guardian_phone' => '+8801644332211',
                'guardian_email' => 'guardian.nusrat@pps.local',
                'guardian_relation' => 'father',
                'guardian_profession' => 'Rickshaw/Auto Driver', 'guardian_profession_category' => 'labor',
                'guardian_time_availability' => 'high',
                'second_guardian_name' => null, 'second_guardian_phone' => null,
                'second_guardian_relation' => null, 'second_guardian_profession' => null,
                'second_guardian_profession_category' => null, 'second_guardian_time_availability' => null,
                'willingness_score' => 4, 'ability_score' => 2, 'student_quadrant' => 'willing_unable',
                'economically_vulnerable' => true,
                'seed_type'    => 'warning',
                'family_status' => 'single parent (father)', 'economic_status' => 'scholarship supported',
                'scholarship_status' => 'partial scholarship',
                'health_notes' => null, 'special_needs' => [],
                'private_tuition_subjects' => [
                    ['subject' => 'English', 'hours_per_week' => 1, 'tutor_name' => 'Mim Madam'],
                ],
                'confidential_context' => 'Mother deceased two years ago. Father raises three children alone. Financial pressure is significant.',
            ],
        ];

        foreach ($featured as $profile) {
            // class_name / section are no longer columns: they are the
            // enrollment made straight after this insert.
            $student = Student::query()->create([
                'school_id'                   => $this->schoolId,
                'student_code'                => $profile['student_code'],
                'name'                        => $profile['name'],
                'roll_number'                 => $profile['roll_number'],
                'admission_date'              => now()->subYears(2)->startOfYear(),
                'guardian_name'               => $profile['guardian_name'],
                'guardian_phone'              => $profile['guardian_phone'],
                'guardian_email'              => $profile['guardian_email'],
                'guardian_relation'            => $profile['guardian_relation'],
                'guardian_profession'         => $profile['guardian_profession'],
                'guardian_profession_category'=> $profile['guardian_profession_category'],
                'guardian_time_availability'  => $profile['guardian_time_availability'],
                'second_guardian_name'        => $profile['second_guardian_name'],
                'second_guardian_phone'       => $profile['second_guardian_phone'],
                'second_guardian_relation'    => $profile['second_guardian_relation'],
                'second_guardian_profession'  => $profile['second_guardian_profession'],
                'second_guardian_profession_category' => $profile['second_guardian_profession_category'],
                'second_guardian_time_availability'   => $profile['second_guardian_time_availability'],
                'willingness_score'           => $profile['willingness_score'],
                'ability_score'               => $profile['ability_score'],
                'student_quadrant'            => $profile['student_quadrant'],
                'economically_vulnerable'     => $profile['economically_vulnerable'],
                'private_tuition_subjects'    => $profile['private_tuition_subjects'],
                'private_tuition_notes'       => $profile['private_tuition_subjects'] !== [] ? 'Documented tuition support on file.' : null,
                'family_status'               => $profile['family_status'],
                'economic_status'             => $profile['economic_status'],
                'scholarship_status'          => $profile['scholarship_status'],
                'health_notes'               => $profile['health_notes'],
                'special_needs'               => $profile['special_needs'],
                'confidential_context'        => $profile['confidential_context'],
            ]);

            PpsAdministrationSeeder::enroll(
                $student,
                $profile['class_name'],
                $profile['section'],
                $profile['roll_number'],
            );

            User::query()->firstOrCreate(
                ['email' => $profile['guardian_email']],
                [
                    'school_id' => $this->schoolId,
                    'name' => $profile['guardian_name'], 'role' => 'guardian',
                    'password' => Hash::make(self::DEMO_PASSWORD),
                ],
            );

            $this->seedStudentDataset($student, $profile['seed_type'], $teachers, $principal, $counselor);
        }

        // ── Bulk cohort: 15 per class/section = 150 students ─────────────────────
        // Roll number drives academic profile: roll 1-2 = top performers, roll 14-15 = at-risk.
        // This mirrors the Bangladeshi convention where roll is assigned by exam rank.

        $periods = collect(range(5, 0))->map(
            fn (int $m) => now()->subMonths($m)->format('Y-m')
        );

        $classes  = ['6', '7', '8', '9', '10'];
        $sections = ['A', 'B'];
        $studentIndex = 1;

        foreach ($classes as $className) {
            foreach ($sections as $section) {
                foreach (range(1, 15) as $roll) {
                    $seedType  = $this->seedTypeForRoll($roll, $className, $section);
                    $studentName  = $this->bulkStudentName($studentIndex);
                    $guardianName = $this->bulkGuardianName($studentIndex);

                    $guardianProfile = $this->guardianProfileForRoll($roll, $studentIndex);

                    // Tuition: top students get enrichment; struggling students get remedial support
                    $hasTuition = in_array($roll, [1, 2, 10, 11, 12, 13]) || ($studentIndex % 6 === 0);
                    $tuitionSubjects = $hasTuition ? $this->tuitionSubjectsForRoll($roll, $studentIndex) : [];

                    // Economic vulnerability: scholarship applicants are flagged automatically
                    $isEconomicallyVulnerable = $guardianProfile['economically_vulnerable'];
                    $economicStatus    = $isEconomicallyVulnerable ? 'scholarship supported' : 'standard';
                    $scholarshipStatus = $isEconomicallyVulnerable
                        ? ($roll >= 14 ? 'full scholarship' : 'partial scholarship')
                        : null;

                    // Family context: realistic spread, more difficult situations in higher rolls
                    $familyStatus = $this->familyStatusForRoll($roll, $studentIndex);

                    // Health: scattered, not correlated with performance
                    $healthNotes = $studentIndex % 17 === 0 ? 'Seasonal asthma noted. Inhaler available at school office.' : null;
                    $allergies   = $studentIndex % 19 === 0 ? 'Dust allergy, mild reaction' : ($studentIndex % 23 === 0 ? 'Food allergy — peanuts' : null);
                    $medications = $studentIndex % 17 === 0 ? 'Salbutamol inhaler when needed' : null;

                    $specialNeeds = match (true) {
                        $studentIndex % 21 === 0 => ['dyslexia_support'],
                        $studentIndex % 29 === 0 => ['hearing_impairment_mild'],
                        $studentIndex % 37 === 0 => ['learning_difficulty'],
                        default                  => [],
                    };

                    $confidentialContext = $this->confidentialContextForRoll($roll, $studentIndex, $familyStatus);
                    $residenceNote = $studentIndex % 16 === 0 ? 'Moved to a new neighbourhood this term. Commute time increased.' : null;

                    $abilityScore    = $this->abilityScoreForRoll($roll);
                    $willingnessScore = $guardianProfile['willingness_score'];
                    $quadrant = $this->studentQuadrant($willingnessScore, $abilityScore);

                    $secondGuardian = $this->secondGuardianForBulk($roll, $studentIndex, $familyStatus, $guardianProfile);

                    $student = Student::query()->create([
                        'school_id'                   => $this->schoolId,
                        'student_code'                => sprintf('PPS-%03d', $studentIndex),
                        'name'                        => $studentName,
                        'roll_number'                 => $roll,
                        'admission_date'              => now()->subYears(
                            (int) ($className) - 5 + ($studentIndex % 2)
                        )->startOfYear(),
                        'guardian_name'               => $guardianName,
                        'guardian_phone'              => $this->generatePhone($studentIndex),
                        'guardian_email'              => sprintf('guardian.bulk%03d@pps.local', $studentIndex),
                        'guardian_relation'           => $guardianProfile['relation'],
                        'guardian_profession'         => $guardianProfile['profession'],
                        'guardian_profession_category'=> $guardianProfile['category'],
                        'guardian_time_availability'  => $guardianProfile['time_availability'],
                        'second_guardian_name'        => $secondGuardian['name'],
                        'second_guardian_phone'       => $secondGuardian['phone'],
                        'second_guardian_relation'    => $secondGuardian['relation'],
                        'second_guardian_profession'  => $secondGuardian['profession'],
                        'second_guardian_profession_category' => $secondGuardian['category'],
                        'second_guardian_time_availability'   => $secondGuardian['time_availability'],
                        'willingness_score'           => $willingnessScore,
                        'ability_score'               => $abilityScore,
                        'student_quadrant'            => $quadrant,
                        'economically_vulnerable'     => $isEconomicallyVulnerable,
                        'private_tuition_subjects'    => $tuitionSubjects,
                        'private_tuition_notes'       => $tuitionSubjects !== [] ? $this->tuitionNote($roll, $tuitionSubjects) : null,
                        'family_status'               => $familyStatus,
                        'economic_status'             => $economicStatus,
                        'scholarship_status'          => $scholarshipStatus,
                        'health_notes'                => $healthNotes,
                        'allergies'                   => $allergies,
                        'medications'                 => $medications,
                        'residence_change_note'       => $residenceNote,
                        'special_needs'               => $specialNeeds,
                        'confidential_context'        => $confidentialContext,
                    ]);

                    PpsAdministrationSeeder::enroll($student, $className, $section, $roll);

                    User::query()->firstOrCreate(
                        ['email' => $student->guardian_email],
                        [
                            'school_id' => $this->schoolId,
                            'name'     => $guardianName,
                            'role'     => 'guardian',
                            'password' => Hash::make(self::DEMO_PASSWORD),
                        ],
                    );

                    $this->seedStudentDataset($student, $seedType, $teachers, $principal, $counselor, $studentIndex, $periods);
                    $studentIndex++;
                }
            }
        }
    }

    /**
     * Map roll number to a seed type. Roll 1 = top of class, Roll 15 = most at risk.
     * Introduces slight variation per class and section so not every class is identical.
     */
    private function seedTypeForRoll(int $roll, string $className, string $section): string
    {
        // Class/section offset to vary which students in the middle-ground get which type
        $offset = ((int) $className + ($section === 'B' ? 3 : 0)) % 4;

        return match (true) {
            $roll <= 2  => 'strong',
            $roll <= 5  => 'good',
            $roll <= 7  => ($offset % 2 === 0) ? 'good' : 'watch',
            $roll <= 9  => 'watch',
            $roll <= 11 => ($offset % 3 === 0) ? 'recovering' : 'warning',
            $roll === 12 => ($offset % 2 === 0) ? 'attendance_crisis' : 'warning',
            $roll === 13 => ($offset % 2 === 0) ? 'academic_crisis' : 'warning',
            $roll === 14 => ($offset % 3 === 0) ? 'recovering' : 'urgent',
            default     => 'urgent',
        };
    }

    private function tuitionSubjectsForRoll(int $roll, int $idx): array
    {
        // Top students: parents invest in enrichment
        if ($roll <= 3) {
            return [
                ['subject' => 'Mathematics', 'hours_per_week' => 4, 'tutor_name' => $this->tutorName($idx, 0)],
                ['subject' => 'English', 'hours_per_week' => 3, 'tutor_name' => $this->tutorName($idx, 1)],
            ];
        }
        // Middle students: subject-specific support
        if ($roll <= 9) {
            $subject = ['Mathematics', 'English', 'Science', 'Bangla'][$idx % 4];

            return [['subject' => $subject, 'hours_per_week' => 3, 'tutor_name' => $this->tutorName($idx, 0)]];
        }
        // Struggling students: remedial support, often Mathematics + English
        return [
            ['subject' => 'Mathematics', 'hours_per_week' => 5, 'tutor_name' => $this->tutorName($idx, 0)],
            ['subject' => 'English', 'hours_per_week' => 4, 'tutor_name' => $this->tutorName($idx, 1)],
        ];
    }

    private function tutorName(int $idx, int $slot): string
    {
        $tutors = [
            ['Kamal Sir', 'Rahim Sir', 'Jamal Sir', 'Anwar Sir', 'Selim Sir', 'Hasan Sir', 'Monir Sir'],
            ['Sharmin Madam', 'Rima Madam', 'Sonia Madam', 'Nasrin Madam', 'Parveen Madam', 'Laila Madam'],
        ];

        return $tutors[$slot % 2][($idx + $slot * 3) % count($tutors[$slot % 2])];
    }

    private function tuitionNote(int $roll, array $subjects): string
    {
        $count = count($subjects);
        if ($roll <= 3) {
            return "Enrichment tuition in {$count} subject(s) — family invests in academic excellence.";
        }
        if ($roll <= 9) {
            return "Supplementary tuition arranged for targeted improvement.";
        }

        return "Remedial home tuition in {$count} subject(s). Guardian seeking urgent academic support.";
    }

    private function generatePhone(int $idx): string
    {
        // Deterministic but looks realistic — uses common BD operator prefixes
        $prefixes = ['1711', '1712', '1715', '1716', '1812', '1813', '1914', '1915', '1712', '1611'];
        $prefix = $prefixes[$idx % count($prefixes)];
        $suffix = str_pad((string) (($idx * 7919 + 10000) % 1000000), 6, '0', STR_PAD_LEFT);

        return '+8801' . substr($prefix, 1) . $suffix;
    }

    private function bulkStudentName(int $idx): string
    {
        $g = self::GIVEN_NAMES[$idx % count(self::GIVEN_NAMES)];
        $f = self::FAMILY_NAMES[intdiv($idx, count(self::GIVEN_NAMES)) % count(self::FAMILY_NAMES)];

        return $g . ' ' . $f;
    }

    private function bulkGuardianName(int $idx): string
    {
        $g = self::GUARDIAN_GIVEN_NAMES[$idx % count(self::GUARDIAN_GIVEN_NAMES)];
        $f = self::FAMILY_NAMES[($idx + 5) % count(self::FAMILY_NAMES)];

        return $g . ' ' . $f;
    }

    /**
     * Returns guardian profiling data derived from the student's roll number and index.
     * Profession and time availability are inferred from each other — doctors/lawyers/military
     * have low time availability; teachers/farmers/retired have high availability.
     * Economic vulnerability is inferred from profession, not asked directly.
     */
    private function guardianProfileForRoll(int $roll, int $idx): array
    {
        // Profession pool mapped to category, time_availability, economic_vulnerability, willingness
        $professions = [
            // [profession, category, time_availability, economically_vulnerable, base_willingness]
            ['Doctor (Private Practice)',   'doctor',         'low',    false, 3],
            ['Lawyer',                      'lawyer',         'low',    false, 3],
            ['Army Officer',                'military',       'low',    false, 4],
            ['Police Officer',              'government',     'low',    false, 4],
            ['Government School Teacher',   'education',      'high',   false, 5],
            ['Madrasa Teacher',             'education',      'high',   false, 5],
            ['Private College Lecturer',    'education',      'medium', false, 4],
            ['Bank Officer',                'private_sector', 'medium', false, 4],
            ['Garment Manager',             'private_sector', 'medium', false, 3],
            ['Businessman (Wholesale)',      'business',       'medium', false, 3],
            ['Shopkeeper',                  'business',       'high',   true,  4],
            ['Small Business Owner',        'business',       'medium', true,  3],
            ['Farmer',                      'agriculture',    'high',   true,  4],
            ['Rickshaw/Auto Driver',        'labor',          'high',   true,  4],
            ['Garment Worker',              'labor',          'low',    true,  3],
            ['Day Labour',                  'labor',          'high',   true,  3],
            ['Works Abroad (Remittance)',   'other',          'low',    false, 2],
            ['NGO Field Worker',            'government',     'medium', false, 4],
            ['Retired Government Officer',  'government',     'high',   false, 5],
            ['Nurse',                       'private_sector', 'low',    false, 4],
            ['Engineer (Private Firm)',     'private_sector', 'low',    false, 3],
            ['Tailor / Dressmaker',         'labor',          'medium', true,  4],
            ['Accountant',                  'private_sector', 'medium', false, 4],
            ['Imam / Religious Scholar',    'other',          'high',   true,  5],
        ];

        // Top-roll students (1-3) tend to have more engaged, white-collar guardians
        // High-roll students (13-15) tend to have more labour/small-business guardians
        if ($roll <= 3) {
            $pool = array_filter($professions, fn ($p) => in_array($p[1], ['doctor', 'lawyer', 'education', 'government', 'private_sector'], true));
        } elseif ($roll <= 9) {
            $pool = array_filter($professions, fn ($p) => !in_array($p[1], ['labor'], true));
        } else {
            $pool = $professions; // full pool, all types
        }

        $pool = array_values($pool);
        $entry = $pool[$idx % count($pool)];

        [$profession, $category, $timeAvailability, $economicallyVulnerable, $baseWillingness] = $entry;

        // Alternate primary guardian between father and mother
        $relation = ($idx % 3 === 0) ? 'mother' : 'father';

        // High-roll students with struggling parents: willingness may still be high even if ability is low
        $willingnessNoise = (int) (($idx * 7 + 3) % 3) - 1; // -1, 0, or +1
        $willingness = max(1, min(5, $baseWillingness + $willingnessNoise));

        // For economically vulnerable, only flag if they'd realistically apply for scholarship
        // (not all labour families apply; some don't know the process)
        $actuallyVulnerable = $economicallyVulnerable && ($roll >= 10 || $idx % 5 === 0);

        return [
            'relation'               => $relation,
            'profession'             => $profession,
            'category'               => $category,
            'time_availability'      => $timeAvailability,
            'economically_vulnerable'=> $actuallyVulnerable,
            'willingness_score'      => $willingness,
        ];
    }

    /**
     * Returns second guardian data (the other parent) for bulk students.
     * Returns nulls for single-parent/deceased situations.
     */
    private function secondGuardianForBulk(int $roll, int $idx, string $familyStatus, array $primaryProfile): array
    {
        $noSecond = str_contains($familyStatus, 'deceased')
            || str_contains($familyStatus, 'single parent')
            || str_contains($familyStatus, 'separated')
            || str_contains($familyStatus, 'divorced');

        if ($noSecond) {
            return ['name' => null, 'phone' => null, 'relation' => null, 'profession' => null, 'category' => null, 'time_availability' => null];
        }

        $secondRelation = $primaryProfile['relation'] === 'father' ? 'mother' : 'father';
        $secondName = $this->bulkGuardianName($idx + 50); // offset to avoid same name as primary

        // Second guardian profession: homemakers and low-availability professions for variety
        $secondProfessions = [
            ['Homemaker', 'other', 'high'],
            ['Government School Teacher', 'education', 'high'],
            ['Garment Worker', 'labor', 'low'],
            ['Nurse', 'private_sector', 'low'],
            ['Small Business Owner', 'business', 'medium'],
            ['Tailor / Dressmaker', 'labor', 'medium'],
            ['NGO Field Worker', 'government', 'medium'],
            ['Homemaker', 'other', 'high'],
            ['Private College Lecturer', 'education', 'medium'],
            ['Homemaker', 'other', 'high'],
        ];

        [$profession, $category, $timeAvailability] = $secondProfessions[$idx % count($secondProfessions)];

        return [
            'name'             => $secondName,
            'phone'            => $this->generatePhone($idx + 200),
            'relation'         => $secondRelation,
            'profession'       => $profession,
            'category'         => $category,
            'time_availability'=> $timeAvailability,
        ];
    }

    private function familyStatusForRoll(int $roll, int $idx): string
    {
        return match (true) {
            $roll >= 14 && $idx % 5 === 0  => 'mother deceased',
            $roll >= 14 && $idx % 7 === 0  => 'father deceased',
            $roll >= 13 && $idx % 9 === 0  => 'parents separated',
            $roll >= 12 && $idx % 11 === 0 => 'parents divorced, child with grandmother',
            $idx % 8 === 0                 => 'single parent (mother)',
            $idx % 13 === 0                => 'single parent (father)',
            $idx % 15 === 0                => 'guardian works abroad',
            $idx % 20 === 0                => 'parents remarried',
            default                        => 'stable',
        };
    }

    private function confidentialContextForRoll(int $roll, int $idx, string $familyStatus): ?string
    {
        if (str_contains($familyStatus, 'deceased')) {
            return 'Parental loss noted in family record. Student shows signs of emotional withdrawal. Counselor monitoring.';
        }
        if (str_contains($familyStatus, 'separated') || str_contains($familyStatus, 'divorced')) {
            return 'Parental separation on record. Student performance has declined since last term. Counselor involved.';
        }
        if ($roll >= 14 && $idx % 4 === 0) {
            return 'Guardian reports financial stress at home. Student shows visible anxiety before exams.';
        }
        if ($roll === 15 && $idx % 3 === 0) {
            return 'Domestic situation unstable. Counselor has been notified. Weekly monitoring in place.';
        }
        if ($idx % 18 === 0) {
            return 'Home stress reported; counselor notified.';
        }

        return null;
    }

    private function abilityScoreForRoll(int $roll): int
    {
        return match (true) {
            $roll <= 2  => 5,
            $roll <= 5  => 4,
            $roll <= 9  => 3,
            $roll <= 12 => 2,
            default     => 1,
        };
    }

    private function studentQuadrant(int $willingnessScore, int $abilityScore): string
    {
        $willing = $willingnessScore >= 3;
        $able    = $abilityScore >= 3;

        return match (true) {
            $willing && $able   => 'willing_able',
            !$willing && $able  => 'unwilling_able',
            $willing && !$able  => 'willing_unable',
            default             => 'unwilling_unable',
        };
    }

    /** @deprecated Use bulkStudentName / bulkGuardianName instead */
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

        // Academic records. pps_assessments — a free-text (subject,
        // assessment_type, total_marks) row per student — is gone; a result is
        // now a pps_marks row against a concrete exam component and a
        // subjects.id. Which components apply is not a per-student choice any
        // more: it is whatever pps_exam_class_map scopes to the class the
        // student is enrolled in, so the matrix below is read from the exams
        // PpsAdministrationSeeder and PpsExamSeeder created rather than
        // hard-coded here.
        $enteredBy = $teachers[$id % count($teachers->all())]->id;

        foreach ($this->markTargetsFor($student) as $index => $target) {
            $max      = (float) $target->max_raw_marks;
            $baseScore = $this->seedScoreForSubject($seedType, $target->subject_name, 0, $id);
            // Same deterministic per-record noise the assessment matrix used.
            $noise    = (int) (($id * ($index + 1) * 7) % 11) - 5;
            $obtained = max(0.0, min($max, round($baseScore / 100 * $max + $noise, 2)));

            Mark::query()->updateOrCreate(
                [
                    'component_id' => $target->component_id,
                    'student_id'   => $student->id,
                    'subject_id'   => $target->subject_id,
                ],
                ['marks_obtained' => $obtained, 'entered_by' => $enteredBy],
            );
        }

        // Classroom ratings — keep 3 monthly snapshots
        foreach ([-2, -1, 0] as $monthOffset) {
            $date = now()->copy()->addMonths($monthOffset);
            $teacherCount = $this->teacherRecords->count();

            ClassroomRating::query()->create([
                'student_id'     => $student->id,
                // pps_classroom_ratings.teacher_id points at teachers, not users.
                'teacher_id'     => $this->teacherRecords[((($id + $monthOffset) % $teacherCount + $teacherCount) % $teacherCount)]->id,
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

    /**
     * pps_teacher_assignments is now (teacher_id -> teachers, section_id ->
     * sections, subject_id -> subjects). The class/section/subject strings the
     * matrix used to store are resolved here into those three ids; subjects are
     * named by short_name because subjects has no `name` column.
     *
     * @param  Collection<int, Teacher>  $teacherRecords
     */
    private function seedTeacherAssignments(Collection $teacherRecords): void
    {
        $matrix = [
            [
                'teacher' => $teacherRecords[0], // Mariam Rahman - Math
                'assignments' => [
                    ['class_name' => '8', 'section' => 'A', 'subject' => 'MTH', 'is_class_teacher' => true],
                    ['class_name' => '9', 'section' => 'A', 'subject' => 'MTH', 'is_class_teacher' => false],
                    ['class_name' => '10','section' => 'A', 'subject' => 'MTH', 'is_class_teacher' => false],
                    ['class_name' => '10','section' => 'B', 'subject' => 'MTH', 'is_class_teacher' => false],
                ],
            ],
            [
                'teacher' => $teacherRecords[1], // Sabbir Hasan - English
                'assignments' => [
                    ['class_name' => '7', 'section' => 'B', 'subject' => 'ENG', 'is_class_teacher' => true],
                    ['class_name' => '8', 'section' => 'A', 'subject' => 'ENG', 'is_class_teacher' => false],
                    ['class_name' => '9', 'section' => 'B', 'subject' => 'ENG', 'is_class_teacher' => false],
                ],
            ],
            [
                'teacher' => $teacherRecords[2], // Tahmina Akter - Science
                'assignments' => [
                    ['class_name' => '6', 'section' => 'A', 'subject' => 'SCIENCE', 'is_class_teacher' => true],
                    ['class_name' => '8', 'section' => 'A', 'subject' => 'SCIENCE', 'is_class_teacher' => false],
                    ['class_name' => '10','section' => 'A', 'subject' => 'SCIENCE', 'is_class_teacher' => false],
                ],
            ],
            [
                'teacher' => $teacherRecords[3], // Jalal Uddin - Bangla
                'assignments' => [
                    ['class_name' => '6', 'section' => 'B', 'subject' => 'BAN', 'is_class_teacher' => true],
                    ['class_name' => '7', 'section' => 'A', 'subject' => 'BAN', 'is_class_teacher' => false],
                    ['class_name' => '8', 'section' => 'B', 'subject' => 'BAN', 'is_class_teacher' => false],
                ],
            ],
            [
                'teacher' => $teacherRecords[4], // Nargis Sultana - Social Studies
                'assignments' => [
                    ['class_name' => '9', 'section' => 'B', 'subject' => 'SOC', 'is_class_teacher' => true],
                    ['class_name' => '10','section' => 'B', 'subject' => 'SOC', 'is_class_teacher' => false],
                    ['class_name' => '7', 'section' => 'A', 'subject' => 'SOC', 'is_class_teacher' => false],
                ],
            ],
        ];

        foreach ($matrix as $row) {
            foreach ($row['assignments'] as $assignment) {
                $subject = PpsAdministrationSeeder::findSubject($assignment['subject']);

                TeacherAssignment::query()->updateOrCreate(
                    [
                        'teacher_id' => $row['teacher']->id,
                        'section_id' => PpsAdministrationSeeder::section(
                            $assignment['class_name'],
                            $assignment['section'],
                        )->id,
                        'subject_id' => $subject?->id,
                    ],
                    [
                        'school_id' => $this->schoolId,
                        'is_class_teacher' => $assignment['is_class_teacher'],
                    ],
                );
            }
        }
    }

    // ── Marks ──────────────────────────────────────────────────────────────────

    /**
     * The (component, subject) pairs a student can be scored on: every subject
     * an exam covering their class is mapped to, crossed with that exam's
     * components. Memoised per class_level, because every student in a class
     * shares the same set.
     *
     * A student with no current enrollment has no class and therefore no marks
     * — there is no longer a students.class_name to fall back on.
     *
     * @return array<int, object>
     */
    private function markTargetsFor(Student $student): array
    {
        // Read the chain directly rather than through the currentEnrollment
        // relation: the student model was instantiated before it was enrolled,
        // so a lazily-loaded relation could still be cached as null.
        $classLevelId = DB::table('student_enrollments as se')
            ->join('academic_years as ay', 'ay.id', '=', 'se.academic_year_id')
            ->join('sections as sec', 'sec.id', '=', 'se.section_id')
            ->where('se.student_id', $student->id)
            ->where('ay.is_current', true)
            ->value('sec.class_level_id');

        if ($classLevelId === null) {
            return [];
        }

        $classLevelId = (int) $classLevelId;

        return $this->markTargets[$classLevelId] ??= DB::table('pps_exam_class_map as m')
            ->join('pps_exams as e', 'e.id', '=', 'm.exam_id')
            ->join('pps_exam_components as c', 'c.exam_id', '=', 'e.id')
            ->join('subjects as s', 's.id', '=', 'm.subject_id')
            ->where('m.class_level_id', $classLevelId)
            ->where('e.is_active', true)
            ->orderBy('e.id')
            ->orderBy('c.sort_order')
            ->orderBy('s.id')
            ->select('c.id as component_id', 'c.max_raw_marks', 'm.subject_id', 's.full_name as subject_name')
            ->get()
            ->all();
    }
}

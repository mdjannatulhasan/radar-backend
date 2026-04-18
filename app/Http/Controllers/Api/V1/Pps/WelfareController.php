<?php

namespace App\Http\Controllers\Api\V1\Pps;

use App\Http\Controllers\Controller;
use App\Models\Pps\WelfareIntervention;
use App\Models\Student;
use App\Support\PpsPermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class WelfareController extends Controller
{
    private const ALLOWED_INTERVENTION_TYPES = [
        'scholarship_review',
        'economic_assessment',
        'family_visit',
        'counseling_referral',
        'financial_aid',
        'other',
    ];

    private const ALLOWED_SCHOLARSHIP_STATUSES = [
        'none', 'applied', 'approved', 'rejected', 'suspended',
    ];

    /**
     * GET /v1/pps/welfare/students/{student}/interventions
     * List all welfare interventions for a student.
     * Accessible to: welfare_officer, admin, superadmin, principal
     */
    public function index(Request $request, Student $student): JsonResponse
    {
        $this->requireWelfareView($request);

        $interventions = WelfareIntervention::query()
            ->where('student_id', $student->id)
            ->with('officer:id,name,role')
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => $interventions]);
    }

    /**
     * POST /v1/pps/welfare/students/{student}/interventions
     * Log a new welfare intervention and optionally update student welfare fields.
     * Accessible to: welfare_officer, admin, superadmin
     */
    public function store(Request $request, Student $student): JsonResponse
    {
        $this->requireWelfareManage($request);

        $data = $request->validate([
            'intervention_type'        => ['required', 'in:'.implode(',', self::ALLOWED_INTERVENTION_TYPES)],
            'notes'                    => ['nullable', 'string', 'max:3000'],
            'scholarship_status_set'   => ['nullable', 'in:'.implode(',', self::ALLOWED_SCHOLARSHIP_STATUSES)],
            'economic_status_set'      => ['nullable', 'string', 'max:80'],
            'economically_vulnerable_set' => ['nullable', 'boolean'],
        ]);

        $intervention = DB::transaction(function () use ($data, $student, $request): WelfareIntervention {
            // Log the intervention record
            $record = WelfareIntervention::query()->create([
                'student_id'                 => $student->id,
                'officer_id'                 => $request->user()?->id,
                'intervention_type'          => $data['intervention_type'],
                'notes'                      => $data['notes'] ?? null,
                'scholarship_status_set'     => $data['scholarship_status_set'] ?? null,
                'economic_status_set'        => $data['economic_status_set'] ?? null,
                'economically_vulnerable_set' => $data['economically_vulnerable_set'] ?? null,
            ]);

            // Apply the welfare field changes to the student record
            $studentUpdates = [];
            if (isset($data['scholarship_status_set'])) {
                $studentUpdates['scholarship_status'] = $data['scholarship_status_set'];
            }
            if (isset($data['economic_status_set'])) {
                $studentUpdates['economic_status'] = $data['economic_status_set'];
            }
            if (isset($data['economically_vulnerable_set'])) {
                $studentUpdates['economically_vulnerable'] = $data['economically_vulnerable_set'];
            }

            if (! empty($studentUpdates)) {
                $student->update($studentUpdates);
            }

            return $record;
        });

        return response()->json(
            $intervention->load('officer:id,name,role'),
            Response::HTTP_CREATED,
        );
    }

    /**
     * GET /v1/pps/welfare/students?vulnerable=1&scholarship_status=applied&...
     * Filtered student list for welfare officer.
     */
    public function students(Request $request): JsonResponse
    {
        $this->requireWelfareView($request);

        $query = Student::query()->select([
            'id', 'name', 'student_code', 'class_name', 'section', 'roll_number',
            'guardian_name', 'guardian_phone', 'guardian_email',
            'scholarship_status', 'economic_status', 'economically_vulnerable',
            'family_status', 'stream_id',
        ]);

        if ($request->boolean('vulnerable')) {
            $query->where('economically_vulnerable', true);
        }

        if ($request->filled('scholarship_status')) {
            $query->where('scholarship_status', $request->string('scholarship_status'));
        }

        if ($request->filled('class_name')) {
            $query->where('class_name', $request->string('class_name'));
        }

        if ($request->filled('search')) {
            $term = $request->string('search');
            $query->where(function ($q) use ($term): void {
                $q->where('name', 'like', "%{$term}%")
                  ->orWhere('student_code', 'like', "%{$term}%");
            });
        }

        $students = $query->orderBy('class_name')->orderBy('section')->orderBy('roll_number')->get();

        return response()->json(['data' => $students]);
    }

    /**
     * GET /v1/pps/welfare/students/export
     * CSV export of economically vulnerable students.
     */
    public function export(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $this->requireWelfareView($request);

        $students = Student::query()
            ->where('economically_vulnerable', true)
            ->select([
                'name', 'student_code', 'class_name', 'section', 'roll_number',
                'guardian_name', 'guardian_phone', 'guardian_email',
                'scholarship_status', 'economic_status', 'family_status',
            ])
            ->orderBy('class_name')
            ->orderBy('section')
            ->get();

        $filename = 'welfare-vulnerable-students-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($students): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'Name', 'Student Code', 'Class', 'Section', 'Roll',
                'Guardian', 'Phone', 'Email',
                'Scholarship Status', 'Economic Status', 'Family Status',
            ]);
            foreach ($students as $s) {
                fputcsv($out, [
                    $s->name, $s->student_code, $s->class_name, $s->section, $s->roll_number,
                    $s->guardian_name, $s->guardian_phone, $s->guardian_email,
                    $s->scholarship_status ?? '', $s->economic_status ?? '', $s->family_status ?? '',
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function requireWelfareView(Request $request): void
    {
        if (! $request->user()?->hasPermission(PpsPermissions::WELFARE_VIEW)) {
            abort(Response::HTTP_FORBIDDEN, 'Welfare view permission required.');
        }
    }

    private function requireWelfareManage(Request $request): void
    {
        if (! $request->user()?->hasPermission(PpsPermissions::WELFARE_MANAGE)) {
            abort(Response::HTTP_FORBIDDEN, 'Welfare manage permission required.');
        }
    }
}

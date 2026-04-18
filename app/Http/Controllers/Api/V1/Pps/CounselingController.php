<?php

namespace App\Http\Controllers\Api\V1\Pps;

use App\Http\Controllers\Controller;
use App\Models\Pps\CounselingSession;
use App\Models\Pps\PpsAlert;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CounselingController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        if (! $request->user()?->hasAnyRole(['counselor', 'principal', 'admin'])) {
            abort(Response::HTTP_FORBIDDEN, 'This PPS route requires counseling permissions.');
        }

        $data = $request->validate([
            'student_id' => ['required', 'exists:students,id'],
            'alert_id' => ['nullable', 'exists:pps_alerts,id'],
            'session_date' => ['required', 'date'],
            'session_type' => ['required', 'in:initial,follow_up,closing'],
            'session_notes' => ['nullable', 'string', 'max:3000'],
            'action_plan' => ['nullable', 'string', 'max:1000'],
            'next_session_date' => ['nullable', 'date', 'after:session_date'],
            'progress_status' => ['nullable', 'in:improving,stable,deteriorating,resolved'],
        ]);

        $session = CounselingSession::query()->create([
            ...$data,
            'counselor_id' => $request->user()?->id,
            'referred_by' => $request->user()?->id,
        ]);

        if (! empty($data['alert_id'])) {
            PpsAlert::query()->find($data['alert_id'])?->update([
                'resolution_action' => 'counseled',
            ]);
        }

        return response()->json($session->fresh(), 201);
    }

    public function update(Request $request, CounselingSession $session): JsonResponse
    {
        $user = $request->user();
        if (! $user?->hasAnyRole(['counselor', 'principal', 'admin'])) {
            abort(Response::HTTP_FORBIDDEN, 'This PPS route requires counseling permissions.');
        }

        // Counselors may only update sessions they created
        if ($user->hasAnyRole(['counselor']) && $session->counselor_id !== $user->id) {
            abort(Response::HTTP_FORBIDDEN, 'You can only update your own counseling sessions.');
        }

        $data = $request->validate([
            'session_date'       => ['sometimes', 'date'],
            'session_type'       => ['sometimes', 'in:initial,follow_up,closing,psychometric'],
            'session_notes'      => ['nullable', 'string', 'max:3000'],
            'action_plan'        => ['nullable', 'string', 'max:1000'],
            'next_session_date'  => ['nullable', 'date'],
            'progress_status'    => ['nullable', 'in:improving,stable,deteriorating,resolved'],
        ]);

        $session->update($data);

        return response()->json($session->fresh()->load('counselor:id,name'));
    }

    public function studentSessions(Request $request, Student $student): JsonResponse
    {
        $this->authorize('viewCounseling', $student);

        $sessions = CounselingSession::query()
            ->where('student_id', $student->id)
            ->with('counselor:id,name')
            ->orderByDesc('session_date')
            ->get();

        return response()->json($sessions);
    }

    public function storePsychometric(Request $request): JsonResponse
    {
        if (! $request->user()?->hasAnyRole(['counselor', 'admin'])) {
            abort(Response::HTTP_FORBIDDEN, 'This PPS route requires psychometric permissions.');
        }

        $data = $request->validate([
            'student_id' => ['required', 'exists:students,id'],
            'assessment_date' => ['required', 'date'],
            'assessment_tool' => ['required', 'string', 'max:100'],
            'self_confidence' => ['nullable', 'integer', 'min:0', 'max:100'],
            'anxiety_level' => ['nullable', 'integer', 'min:0', 'max:100'],
            'social_skills' => ['nullable', 'integer', 'min:0', 'max:100'],
            'emotional_regulation' => ['nullable', 'integer', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'special_needs' => ['nullable', 'array'],
        ]);

        $session = CounselingSession::query()->create([
            'student_id' => $data['student_id'],
            'counselor_id' => $request->user()?->id,
            'session_date' => $data['assessment_date'],
            'session_type' => 'psychometric',
            'assessment_tool' => $data['assessment_tool'],
            'psychometric_scores' => [
                'self_confidence' => $data['self_confidence'] ?? null,
                'anxiety_level' => $data['anxiety_level'] ?? null,
                'social_skills' => $data['social_skills'] ?? null,
                'emotional_regulation' => $data['emotional_regulation'] ?? null,
                'notes' => $data['notes'] ?? null,
            ],
            'special_needs_profile' => $data['special_needs'] ?? [],
            'session_notes' => $data['notes'] ?? null,
            'progress_status' => 'stable',
        ]);

        return response()->json($session, 201);
    }
}

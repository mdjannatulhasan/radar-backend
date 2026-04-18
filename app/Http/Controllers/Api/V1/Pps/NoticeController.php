<?php

namespace App\Http\Controllers\Api\V1\Pps;

use App\Http\Controllers\Controller;
use App\Models\Pps\PpsNotice;
use App\Models\Student;
use App\Support\PpsPermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class NoticeController extends Controller
{
    private const AUDIENCE_OPTIONS = [
        'public', 'all', 'staff',
        'teachers', 'counselors', 'welfare_officers', 'guardians', 'students',
    ];

    /**
     * GET /v1/pps/notices
     * Returns notices visible to the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $notices = PpsNotice::query()
            ->active()
            ->visibleTo($user)
            ->with('postedBy:id,name,role')
            ->orderByDesc('is_pinned')
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($notices);
    }

    /**
     * POST /v1/pps/notices
     * Create a new notice. Allowed roles: admin, superadmin, principal.
     */
    public function store(Request $request): JsonResponse
    {
        $this->requireNoticesManage($request);

        $data = $request->validate([
            'title'                 => ['required', 'string', 'max:255'],
            'body'                  => ['required', 'string', 'max:10000'],
            'audience'              => ['required', 'array', 'min:1'],
            'audience.*'            => ['required', 'string', 'in:'.implode(',', self::AUDIENCE_OPTIONS)],
            'target_student_id'     => ['nullable', 'exists:students,id'],
            'target_user_id'        => ['nullable', 'exists:users,id'],
            'is_expiry_enabled'     => ['boolean'],
            'expires_at'            => ['nullable', 'required_if:is_expiry_enabled,true', 'date', 'after:now'],
            'is_pinned'             => ['boolean'],
        ]);

        $notice = PpsNotice::query()->create([
            ...$data,
            'posted_by'         => $request->user()?->id,
            'is_expiry_enabled' => $data['is_expiry_enabled'] ?? false,
            'expires_at'        => ($data['is_expiry_enabled'] ?? false) ? $data['expires_at'] : null,
            'is_pinned'         => $data['is_pinned'] ?? false,
        ]);

        return response()->json(
            $notice->load('postedBy:id,name,role'),
            Response::HTTP_CREATED,
        );
    }

    /**
     * PATCH /v1/pps/notices/{notice}
     * Update an existing notice.
     */
    public function update(Request $request, PpsNotice $notice): JsonResponse
    {
        $this->requireNoticesManage($request);

        $data = $request->validate([
            'title'             => ['sometimes', 'string', 'max:255'],
            'body'              => ['sometimes', 'string', 'max:10000'],
            'audience'          => ['sometimes', 'array', 'min:1'],
            'audience.*'        => ['string', 'in:'.implode(',', self::AUDIENCE_OPTIONS)],
            'target_student_id' => ['nullable', 'exists:students,id'],
            'target_user_id'    => ['nullable', 'exists:users,id'],
            'is_expiry_enabled' => ['sometimes', 'boolean'],
            'expires_at'        => ['nullable', 'date', 'after:now'],
            'is_pinned'         => ['sometimes', 'boolean'],
        ]);

        if (isset($data['is_expiry_enabled']) && ! $data['is_expiry_enabled']) {
            $data['expires_at'] = null;
        }

        $notice->update($data);

        return response()->json($notice->fresh()->load('postedBy:id,name,role'));
    }

    /**
     * DELETE /v1/pps/notices/{notice}
     */
    public function destroy(Request $request, PpsNotice $notice): JsonResponse
    {
        $this->requireNoticesManage($request);

        $notice->delete();

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function requireNoticesManage(Request $request): void
    {
        if (! $request->user()?->hasPermission(PpsPermissions::NOTICES_MANAGE)) {
            abort(Response::HTTP_FORBIDDEN, 'Notice management permission required.');
        }
    }
}

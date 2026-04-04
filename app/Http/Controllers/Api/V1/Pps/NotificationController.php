<?php

namespace App\Http\Controllers\Api\V1\Pps;

use App\Http\Controllers\Controller;
use App\Services\Pps\NotificationDigestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class NotificationController extends Controller
{
    public function __construct(
        private readonly NotificationDigestService $notifications,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $logs = $this->notifications
            ->queryLogsForViewer($request->user(), $request->only(['type', 'snapshot_period']))
            ->limit(200)
            ->get();

        return response()->json(['data' => $logs]);
    }

    public function run(Request $request, string $type): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->hasAnyRole(['principal', 'admin'])) {
            abort(Response::HTTP_FORBIDDEN, 'Only a principal or admin can trigger PPS notification runs.');
        }

        $period = $request->string('period')->toString() ?: now()->format('Y-m');

        $created = match ($type) {
            'alerts' => $this->notifications->generateAlertNotifications($period),
            'monthly-parent-reports' => $this->notifications->generateMonthlyParentReports($period),
            'weekly-principal-summary' => $this->notifications->generateWeeklyPrincipalSummary($period),
            default => null,
        };

        if ($created === null) {
            return response()->json(['message' => 'Unsupported notification run type.'], 422);
        }

        return response()->json([
            'message' => 'Notification run completed.',
            'type' => $type,
            'period' => $period,
            'created' => $created,
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Pps;

use App\Http\Controllers\Controller;
use App\Models\Pps\SchoolPpsConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SchoolPpsConfigController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(SchoolPpsConfig::current());
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'weight_academic' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'weight_attendance' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'weight_behavior' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'weight_participation' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'weight_extracurricular' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'threshold_risk_watch' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'threshold_risk_warning' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'threshold_risk_urgent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'threshold_attendance_watch' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'threshold_attendance_warning' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'threshold_attendance_urgent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'threshold_grade_drop_warning' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'threshold_grade_drop_urgent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'threshold_yellow_cards_warning' => ['nullable', 'integer', 'min:1', 'max:20'],
            'notify_parent_on_warning' => ['nullable', 'boolean'],
            'notify_parent_on_watch' => ['nullable', 'boolean'],
            'send_monthly_parent_report' => ['nullable', 'boolean'],
            'send_weekly_principal_summary' => ['nullable', 'boolean'],
            'notify_guardian_email_on_urgent' => ['nullable', 'boolean'],
        ]);

        $config = SchoolPpsConfig::current();
        $config->update($data);

        return response()->json($config->fresh());
    }
}

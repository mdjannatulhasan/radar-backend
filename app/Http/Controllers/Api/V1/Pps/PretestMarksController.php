<?php

namespace App\Http\Controllers\Api\V1\Pps;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Legacy pre-test marks entry — RETIRED, and deliberately fails closed.
 *
 * Every table this controller read or wrote is gone:
 *
 *   pps_exam_definitions  dropped — exams live in pps_exams + pps_exam_class_map
 *   pps_exam_scopes       dropped — scope lives in pps_exam_class_map
 *   pps_pretest_marks     dropped by 2026_06_08_190000_drop_old_marks_tables
 *
 * and the App\Models\Pps\ExamDefinition / ExamScope / PretestMark classes were
 * deleted with them. On top of that the student lookup it performed —
 * students.class_name / students.section — is no longer expressible as columns.
 *
 * There is nothing to repoint it at: the pre-test flow was never migrated onto
 * the unified pps_exams schema, so silently re-aiming it at pps_marks would
 * invent behaviour rather than preserve it. The routes still exist
 * (routes/api.php is owned elsewhere), so both verbs answer 410 Gone instead of
 * fataling on a missing class, and the write path stores nothing.
 *
 * REMOVE the two /marks/pretest routes and this file together when the
 * front-end's getPretestMarksGrid / savePretestMarks calls are retired.
 */
class PretestMarksController extends Controller
{
    private const GONE_MESSAGE = 'Pre-test marks entry has been retired: its exam and marks tables were removed in the shared-schema cutover. Use the unified marks entry at /v1/pps/marks.';

    /**
     * GET /v1/pps/marks/pretest?exam_id=&subject_id=
     */
    public function index(Request $request): JsonResponse
    {
        return $this->gone();
    }

    /**
     * POST /v1/pps/marks/pretest
     */
    public function bulkStore(Request $request): JsonResponse
    {
        return $this->gone();
    }

    private function gone(): JsonResponse
    {
        return response()->json(
            ['message' => self::GONE_MESSAGE],
            Response::HTTP_GONE,
        );
    }
}

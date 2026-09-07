<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Pps;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use SmsCore\Models\ClassLevel;
use SmsCore\Models\Level;
use SmsCore\Models\Version;

class ClassStructureController extends Controller
{
    /**
     * GET /classes/structure
     *
     * The class/section vocabulary for every filter UI in RADAR, now carrying
     * the level and version dimensions. Optional level_id / version_id / group
     * query params narrow the class list.
     *
     * Gated on students.view — any role that can see students can use this.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'level_id' => ['nullable', 'integer', 'exists:levels,id'],
            'version_id' => ['nullable', 'integer', 'exists:versions,id'],
            'group' => ['nullable', 'string', 'in:science,humanities,business_studies'],
        ]);

        $classes = ClassLevel::query()
            ->where('is_active', true)
            ->forLevel($validated['level_id'] ?? null)
            ->forVersion($validated['version_id'] ?? null)
            ->forGroup($validated['group'] ?? null)
            ->with([
                'level:id,name',
                'version:id,name',
                'sections' => fn ($q) => $q->where('is_active', true)->with('sectionName:id,name,sort_order'),
            ])
            ->orderBy('numeric_order')
            ->orderBy('name')
            ->get()
            ->map(fn (ClassLevel $cl): array => [
                'id' => $cl->id,
                'name' => $cl->name,
                'group' => $cl->group,
                'numeric_order' => $cl->numeric_order,
                'level_id' => $cl->level_id,
                'level_name' => $cl->level?->name,
                'version_id' => $cl->version_id,
                'version_name' => $cl->version?->name,
                'full_label' => $cl->full_label,
                'sections' => $cl->sections
                    ->sortBy(fn ($s) => [$s->sectionName?->sort_order ?? 0, $s->sectionName?->name ?? ''])
                    ->map(fn ($s): array => [
                        'id' => $s->id,
                        'name' => $s->sectionName?->name,
                    ])
                    ->values(),
            ])
            ->values();

        return response()->json([
            'levels' => Level::orderBy('sort_order')->get(['id', 'name']),
            'versions' => Version::orderBy('sort_order')->get(['id', 'name']),
            'groups' => [
                ['id' => 'science', 'name' => 'Science'],
                ['id' => 'humanities', 'name' => 'Humanities'],
                ['id' => 'business_studies', 'name' => 'Business Studies'],
            ],
            'classes' => $classes,
        ]);
    }
}

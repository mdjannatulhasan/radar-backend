<?php

namespace App\Http\Controllers\Api\V1\Pps;

use App\Http\Controllers\Controller;
use App\Models\Pps\ClassConfig;
use Illuminate\Http\JsonResponse;

class ClassStructureController extends Controller
{
    /**
     * GET /classes/structure
     *
     * Returns class → department → section mapping for the filter UI.
     * Gated on students.view so any role that can see students can use this.
     * Response shape matches ClassStructureItem[] in the frontend.
     */
    public function index(): JsonResponse
    {
        $configs = ClassConfig::query()
            ->where('is_active', true)
            ->with([
                'schoolClass:id,name,numeric_order',
                'department:id,name,code',
                'section:id,name',
            ])
            ->get()
            ->filter(fn($c) => $c->schoolClass !== null && $c->section !== null)
            ->groupBy(fn($c) => $c->schoolClass->name);

        $structure = $configs
            ->map(function ($items, $className) {
                $departments = $items
                    ->filter(fn($c) => $c->department !== null)
                    ->map(fn($c) => $c->department)
                    ->unique('id')
                    ->values()
                    ->map(fn($d) => [
                        'id'   => $d->id,
                        'name' => $d->name,
                        'code' => $d->code,
                    ]);

                $sections = $items
                    ->map(fn($c) => [
                        'name'          => $c->section->name,
                        'department_id' => $c->department_id,
                    ])
                    ->unique('name')
                    ->values();

                return [
                    'class_name'  => $className,
                    'departments' => $departments,
                    'sections'    => $sections,
                ];
            })
            ->sortBy(fn($item) => (int) $item['class_name'])
            ->values();

        return response()->json($structure);
    }
}

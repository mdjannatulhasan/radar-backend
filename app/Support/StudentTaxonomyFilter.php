<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use SmsCore\Models\Section;
use SmsCore\Models\Student;

/**
 * Applies level / version / group / class / section filters to any query on
 * SmsCore\Models\Student.
 *
 * There is exactly one route from a student to its taxonomy — through the
 * current enrollment's section to its class_level. Every filter in RADAR goes
 * through here so that route is written once rather than in each of the ~15
 * endpoints that need it.
 */
final class StudentTaxonomyFilter
{
    /** @return array{level_id:?int,version_id:?int,group:?string,class_level_id:?int,section_id:?int} */
    public static function validate(Request $request): array
    {
        $v = $request->validate([
            'level_id' => ['nullable', 'integer', 'exists:levels,id'],
            'version_id' => ['nullable', 'integer', 'exists:versions,id'],
            'group' => ['nullable', 'string', 'in:science,humanities,business_studies'],
            'class_level_id' => ['nullable', 'integer', 'exists:class_levels,id'],
            'section_id' => ['nullable', 'integer', 'exists:sections,id'],
        ]);

        return [
            'level_id' => isset($v['level_id']) ? (int) $v['level_id'] : null,
            'version_id' => isset($v['version_id']) ? (int) $v['version_id'] : null,
            'group' => $v['group'] ?? null,
            'class_level_id' => isset($v['class_level_id']) ? (int) $v['class_level_id'] : null,
            'section_id' => isset($v['section_id']) ? (int) $v['section_id'] : null,
        ];
    }

    /**
     * @param  Builder<Student>  $students
     * @param  array{level_id:?int,version_id:?int,group:?string,class_level_id:?int,section_id:?int}  $filters
     * @return Builder<Student>
     */
    public static function apply(Builder $students, array $filters): Builder
    {
        $hasAny = array_filter($filters, fn ($v) => $v !== null) !== [];

        if (! $hasAny) {
            return $students;
        }

        return $students->whereHas('currentEnrollment', function (Builder $e) use ($filters): void {
            if ($filters['section_id'] !== null) {
                $e->where('section_id', $filters['section_id']);
            }

            $classFilters = array_filter([
                'level_id' => $filters['level_id'],
                'version_id' => $filters['version_id'],
                'group' => $filters['group'],
                'id' => $filters['class_level_id'],
            ], fn ($v) => $v !== null);

            if ($classFilters === []) {
                return;
            }

            $e->whereHas('section.classLevel', function (Builder $cl) use ($classFilters): void {
                foreach ($classFilters as $column => $value) {
                    $cl->where($column, $value);
                }
            });
        });
    }

    /**
     * Eager-load path needed to render class/section/version on a student row.
     *
     * @return array<int, string>
     */
    public static function eagerLoad(): array
    {
        return [
            'currentEnrollment.section.sectionName:id,name',
            'currentEnrollment.section.classLevel:id,name,group,level_id,version_id',
            'currentEnrollment.section.classLevel.level:id,name',
            'currentEnrollment.section.classLevel.version:id,name',
        ];
    }

    /**
     * The taxonomy fields every student-shaped API response carries.
     *
     * @return array<string, mixed>
     */
    public static function present(Student $student): array
    {
        $section = $student->currentEnrollment?->section;
        $classLevel = $section?->classLevel;

        return [
            'section_id' => $section?->id,
            'section_name' => $section?->sectionName?->name,
            'class_level_id' => $classLevel?->id,
            'class_name' => $classLevel?->name,
            'group' => $classLevel?->group,
            'level_id' => $classLevel?->level_id,
            'level_name' => $classLevel?->level?->name,
            'version_id' => $classLevel?->version_id,
            'version_name' => $classLevel?->version?->name,
            'roll_number' => $student->currentEnrollment?->roll_number,
        ];
    }

    /**
     * The same eager-load path reached through a relation, for queries whose
     * root is not Student — e.g. PerformanceSnapshot::with(eagerLoadVia('student')).
     *
     * @return array<int, string>
     */
    public static function eagerLoadVia(string $relation): array
    {
        return array_map(
            fn (string $path): string => $relation.'.'.$path,
            self::eagerLoad(),
        );
    }

    /**
     * Order a Student query the way the old students.class_name /
     * students.section columns used to: by class, then section. Both now live
     * two joins away on the CURRENT enrollment, so each sort key is a
     * correlated subquery rather than a column.
     *
     * @param  Builder<Student>  $students
     * @return Builder<Student>
     */
    public static function orderByClassAndSection(Builder $students, string $direction = 'asc'): Builder
    {
        $key = fn (string $column) => DB::table('student_enrollments as se')
            ->join('academic_years as ay', 'ay.id', '=', 'se.academic_year_id')
            ->join('sections as sec', 'sec.id', '=', 'se.section_id')
            ->join('class_levels as cl', 'cl.id', '=', 'sec.class_level_id')
            ->join('section_names as sn', 'sn.id', '=', 'sec.section_name_id')
            ->whereColumn('se.student_id', 'students.id')
            ->where('ay.is_current', true)
            ->limit(1)
            ->select($column);

        return $students
            ->orderBy($key('cl.numeric_order'), $direction)
            ->orderBy($key('cl.name'), $direction)
            ->orderBy($key('sn.name'), $direction);
    }

    /**
     * Resolve the legacy "class name + section name" pair that several RADAR
     * routes still carry in their URL (/classes/{class}/{section}/analytics) to
     * concrete section ids. A class name is ambiguous now — "Class 9" exists in
     * both versions — so this deliberately returns every match rather than
     * picking one.
     *
     * @return array<int, int>
     */
    public static function sectionIdsForNames(?string $className, ?string $sectionName): array
    {
        if ($className === null && $sectionName === null) {
            return [];
        }

        return Section::query()
            ->when(
                $className !== null,
                fn (Builder $q) => $q->whereHas('classLevel', fn (Builder $cl) => $cl->where('name', $className))
            )
            ->when(
                $sectionName !== null,
                fn (Builder $q) => $q->whereHas('sectionName', fn (Builder $sn) => $sn->where('name', $sectionName))
            )
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Narrow a Student query to a set of section ids. An empty set means "no
     * such class" and must match nobody rather than everybody.
     *
     * @param  Builder<Student>  $students
     * @param  array<int, int>  $sectionIds
     */
    public static function applySectionIds(Builder $students, array $sectionIds): void
    {
        if ($sectionIds === []) {
            $students->whereRaw('1 = 0');

            return;
        }

        $students->whereHas(
            'currentEnrollment',
            fn (Builder $e) => $e->whereIn('section_id', $sectionIds)
        );
    }
}

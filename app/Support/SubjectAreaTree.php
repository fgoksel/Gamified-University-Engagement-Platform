<?php

namespace App\Support;

use App\Enums\TaxonomyCategory;
use App\Models\SubjectArea;
use App\Models\User;

/**
 * Data for the Subject Area panel in the teacher's sidebar (UC-1.2):
 * active subject areas grouped by faculty. Areas without a faculty are
 * university-wide and come last.
 *
 * "My areas" in the panel = the teacher's own faculty + university-wide areas.
 */
class SubjectAreaTree
{
    /**
     * @return array{
     *     myFacultyId: int|null,
     *     groups: list<array{
     *         id: int|null,
     *         name: string,
     *         code: string|null,
     *         areas: list<array{id: int, title: string, code: string, category: string, categoryLabel: string}>
     *     }>
     * }
     */
    public static function for(User $user): array
    {
        $areas = SubjectArea::query()
            ->where('is_active', true)
            ->with('faculty:id,name,code')
            ->orderBy('title')
            ->get(['id', 'title', 'code', 'category', 'faculty_id']);

        $groups = $areas
            ->groupBy(fn (SubjectArea $area) => $area->faculty_id ?? 0)
            ->map(function ($items) {
                $faculty = $items->first()->faculty;

                return [
                    'id' => $faculty?->id,
                    'name' => $faculty?->name ?? 'University-wide',
                    'code' => $faculty?->code,
                    'areas' => $items->map(fn (SubjectArea $area) => [
                        'id' => $area->id,
                        'title' => $area->title,
                        'code' => $area->code,
                        'category' => $area->category,
                        'categoryLabel' => TaxonomyCategory::from($area->category)->label(),
                    ])->values()->all(),
                ];
            })
            // Faculties A–Z, university-wide last
            ->sort(fn (array $a, array $b) => [$a['id'] === null, $a['name']] <=> [$b['id'] === null, $b['name']])
            ->values()
            ->all();

        return [
            'myFacultyId' => $user->faculty_id,
            'groups' => $groups,
        ];
    }
}

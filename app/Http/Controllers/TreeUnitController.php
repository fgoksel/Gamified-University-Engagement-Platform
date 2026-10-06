<?php

namespace App\Http\Controllers;

use App\Enums\TreeRole;
use App\Enums\TreeUnitKind;
use App\Enums\TutorPermission;
use App\Models\TreeUnit;
use App\Models\UnitMembership;
use App\Models\User;
use App\Services\TreeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "My courses" for deans, teachers and co-teachers (build step 3): the
 * units they are on, and one unit with its subtopics and people.
 */
class TreeUnitController extends Controller
{
    /**
     * The units where the user holds a staff role.
     */
    public function index(Request $request): Response
    {
        $memberships = $request->user()->memberships()
            ->active()
            ->whereIn('role', [TreeRole::Dean, TreeRole::Teacher, TreeRole::CoTeacher])
            ->with('unit.course')
            ->get()
            ->sortBy(fn (UnitMembership $membership) => [-$membership->role->level(), $membership->unit->title]);

        return Inertia::render('Tree/Index', [
            'units' => $memberships->map(fn (UnitMembership $membership) => [
                ...$this->unitSummary($membership->unit),
                'role' => $membership->role->label(),
                'unitsBelow' => TreeUnit::within($membership->unit)->count() - 1,
                'people' => UnitMembership::active()
                    ->whereIn('unit_id', TreeUnit::within($membership->unit)->select('id'))
                    ->where('role', '!=', TreeRole::Dean)
                    ->count(),
            ])->values(),
        ]);
    }

    /**
     * One unit: where it sits, its child units and the people on it the
     * user may see (rule 1), with what the user may do here.
     */
    public function show(Request $request, TreeUnit $unit): Response
    {
        Gate::authorize('view', $unit);

        $user = $request->user();
        $memberships = $unit->memberships()->with('user')->get()
            ->filter(fn (UnitMembership $membership) => $user->can('view', $membership))
            ->sortBy(fn (UnitMembership $membership) => [-$membership->role->level(), $membership->user->name]);

        return Inertia::render('Tree/Show', [
            'unit' => [
                ...$this->unitSummary($unit->load('course')),
                'myRole' => $unit->coveringMemberships($user)->get()
                    ->sortByDesc(fn (UnitMembership $membership) => $membership->role->level())
                    ->first()?->role->label(),
            ],
            'breadcrumbs' => TreeUnit::whereIn('id', array_slice($unit->pathIds(), 0, -1))->get()
                ->sortBy(fn (TreeUnit $ancestor) => strlen($ancestor->path))
                ->filter(fn (TreeUnit $ancestor) => $user->can('view', $ancestor))
                ->map(fn (TreeUnit $ancestor) => ['id' => $ancestor->id, 'title' => $ancestor->title])
                ->values(),
            'children' => $unit->children()->with('course')->orderBy('title')->get()
                ->map(fn (TreeUnit $child) => $this->unitSummary($child)),
            'members' => $memberships->filter->isActive()->map(fn (UnitMembership $membership) => $this->memberRow($membership, $user))->values(),
            'history' => $memberships->reject->isActive()->map(fn (UnitMembership $membership) => $this->memberRow($membership, $user))->values(),
            'addableRoles' => collect(TreeRole::cases())
                ->filter(fn (TreeRole $role) => in_array($unit->kind, $role->sitsOn(), true)
                    && $user->can('add', [UnitMembership::class, $unit, $role]))
                ->map(fn (TreeRole $role) => ['value' => $role->value, 'label' => $role->label()])
                ->values(),
            'canAddSubtopic' => $user->can('addSubtopic', $unit),
            'tutorPermissions' => collect(TutorPermission::cases())->map(fn (TutorPermission $permission) => [
                'value' => $permission->value,
                'label' => ucfirst(str_replace('_', ' ', $permission->value)),
            ]),
        ]);
    }

    /**
     * Add a subtopic below this unit.
     */
    public function storeSubtopic(Request $request, TreeUnit $unit, TreeService $tree): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
        ]);

        $subtopic = $tree->addSubtopic($request->user(), $unit, $data['title']);

        return back()->with('success', "Subtopic {$subtopic->title} has been added.");
    }

    /**
     * @return array<string, mixed>
     */
    private function unitSummary(TreeUnit $unit): array
    {
        return [
            'id' => $unit->id,
            'title' => $unit->title,
            'kind' => $unit->kind->value,
            'kindLabel' => match ($unit->kind) {
                TreeUnitKind::Root => 'Tree',
                TreeUnitKind::Course => 'Course',
                TreeUnitKind::Subtopic => 'Subtopic',
            },
            'courseCode' => $unit->course?->code,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function memberRow(UnitMembership $membership, User $viewer): array
    {
        return [
            'id' => $membership->id,
            'name' => $membership->user->name,
            'email' => $membership->user->email,
            'role' => $membership->role->value,
            'roleLabel' => $membership->role->label(),
            'permissions' => $membership->permissions ?? [],
            'manual' => $membership->manual,
            'isMe' => $membership->user_id === $viewer->id,
            'startedAt' => $membership->started_at->toDateString(),
            'endedAt' => $membership->ended_at?->toDateString(),
            'endedReason' => $membership->ended_reason,
            'canEnd' => $membership->isActive() && $viewer->can('end', $membership),
            'canSetPermissions' => $membership->isActive() && $viewer->can('setPermissions', $membership),
        ];
    }
}

<?php

namespace App\Http\Controllers;

use App\Enums\TreeRole;
use App\Enums\TutorPermission;
use App\Models\TreeUnit;
use App\Models\UnitMembership;
use App\Models\User;
use App\Services\TreeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Adding people to a unit, setting tutor rights and ending roles from the
 * "My courses" screen (build step 3). TreeService checks every rule.
 */
class UnitMembershipController extends Controller
{
    /**
     * People who could be added to $unit as the given role: accounts of the
     * right type, matching the search, with no active role in the course.
     */
    public function candidates(Request $request, TreeUnit $unit): JsonResponse
    {
        $data = $request->validate([
            'role' => ['required', Rule::enum(TreeRole::class)],
            'q' => ['required', 'string', 'min:2', 'max:100'],
        ]);

        $role = TreeRole::from($data['role']);
        Gate::authorize('add', [UnitMembership::class, $unit, $role]);

        $courseUnit = $unit->courseUnit() ?? $unit;
        $search = '%'.$data['q'].'%';

        $users = User::role($role->accountType())
            ->where('status', '!=', 'inactive')
            ->where(fn ($query) => $query
                ->where('name', 'like', $search)
                ->orWhere('email', 'like', $search)
                ->orWhere('neptun_code', 'like', $search))
            ->whereDoesntHave('memberships', fn ($query) => $query
                ->active()
                ->whereIn('unit_id', TreeUnit::within($courseUnit)->select('id'))
                // A student of the course may still become its tutor (rule 5).
                ->when($role === TreeRole::Tutor, fn ($query) => $query->where('role', '!=', TreeRole::Student)))
            ->orderBy('name')
            ->limit(10)
            ->get();

        return response()->json($users->map(fn (User $user) => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'neptunCode' => $user->neptun_code,
        ]));
    }

    public function store(Request $request, TreeUnit $unit, TreeService $tree): RedirectResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'role' => ['required', Rule::enum(TreeRole::class)],
            'permissions' => ['array'],
            'permissions.*' => [Rule::enum(TutorPermission::class)],
        ]);

        $user = User::findOrFail($data['user_id']);
        $role = TreeRole::from($data['role']);

        $tree->addMember($request->user(), $unit, $user, $role, $data['permissions'] ?? []);

        return back()->with('success', "{$user->name} has been added as {$role->label()}.");
    }

    public function updatePermissions(Request $request, UnitMembership $membership, TreeService $tree): RedirectResponse
    {
        $data = $request->validate([
            'permissions' => ['present', 'array'],
            'permissions.*' => [Rule::enum(TutorPermission::class)],
        ]);

        $tree->setTutorPermissions($request->user(), $membership, $data['permissions']);

        return back()->with('success', "Rights of {$membership->user->name} have been saved.");
    }

    public function end(Request $request, UnitMembership $membership, TreeService $tree): RedirectResponse
    {
        $data = $request->validate([
            'ended_reason' => ['required', 'string', 'max:255'],
        ], [
            'ended_reason.required' => 'Give a reason for ending this role.',
        ]);

        $tree->endMembership($request->user(), $membership, $data['ended_reason']);

        return back()->with('success', "The role of {$membership->user->name} has ended.");
    }
}

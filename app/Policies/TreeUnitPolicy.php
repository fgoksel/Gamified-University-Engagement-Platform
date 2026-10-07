<?php

namespace App\Policies;

use App\Enums\TreeRole;
use App\Enums\TreeUnitKind;
use App\Enums\TutorPermission;
use App\Enums\UserRole;
use App\Models\TreeUnit;
use App\Models\UnitMembership;
use App\Models\User;

/**
 * Who may see and change the units of the role tree.
 *
 * The admin is outside the tree: they create each faculty's tree with its
 * dean and can see every tree. Courses come from the faculty. Inside a tree a person's rights come
 * from the roles they hold on a unit or on any unit above it.
 */
class TreeUnitPolicy
{
    /**
     * Rule 1: a person sees the units they are on and everything below them.
     */
    public function view(User $user, TreeUnit $unit): bool
    {
        return $user->hasRole(UserRole::Admin)
            || $unit->coveringMemberships($user)->exists();
    }

    /**
     * Only the admin creates a tree (a root with its dean).
     */
    public function create(User $user): bool
    {
        return $user->hasRole(UserRole::Admin);
    }

    /**
     * Teachers and co-teachers add subtopics on their courses and below.
     */
    public function addSubtopic(User $user, TreeUnit $parent): bool
    {
        return $parent->kind !== TreeUnitKind::Root
            && $parent->coveringMemberships($user)
                ->whereIn('role', [TreeRole::Teacher, TreeRole::CoTeacher])
                ->exists();
    }

    /**
     * Rule 8: only the admin may hard-delete a unit. TreeService also checks
     * that nothing is attached to it.
     */
    public function delete(User $user, TreeUnit $unit): bool
    {
        return $user->hasRole(UserRole::Admin);
    }

    /**
     * For the events step: teachers and co-teachers create events on their
     * courses and below; a tutor only where they were given create_events.
     */
    public function createEvent(User $user, TreeUnit $unit): bool
    {
        if ($unit->kind === TreeUnitKind::Root) {
            return false;
        }

        return $unit->coveringMemberships($user)->get()->contains(
            fn (UnitMembership $membership) => in_array($membership->role, [TreeRole::Teacher, TreeRole::CoTeacher], true)
                || $membership->hasPermission(TutorPermission::CreateEvents)
        );
    }
}

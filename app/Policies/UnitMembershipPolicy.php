<?php

namespace App\Policies;

use App\Enums\TreeRole;
use App\Enums\TreeUnitKind;
use App\Enums\UserRole;
use App\Models\TreeUnit;
use App\Models\UnitMembership;
use App\Models\User;

/**
 * Who may see, add and end the roles people hold in the tree.
 *
 * Data rules (one role per course, account type, where a role may sit)
 * are checked by TreeService, not here.
 */
class UnitMembershipPolicy
{
    /**
     * Rule 1: everyone sees their own record. Staff see everyone on the units
     * below theirs, and on their own unit only people with a lower level.
     * A student sees nobody else.
     */
    public function view(User $viewer, UnitMembership $membership): bool
    {
        if ($viewer->hasRole(UserRole::Admin) || $membership->user_id === $viewer->id) {
            return true;
        }

        return $membership->unit->coveringMemberships($viewer)->get()->contains(
            fn (UnitMembership $own) => $own->role !== TreeRole::Student
                && ($own->unit_id !== $membership->unit_id || $membership->role->level() < $own->role->level())
        );
    }

    /**
     * Rules 2 and 3: the admin appoints deans; everyone else adds only the
     * roles their own role allows, on their own unit or below it.
     */
    public function add(User $actor, TreeUnit $unit, TreeRole $role): bool
    {
        if ($role === TreeRole::Dean) {
            return $unit->kind === TreeUnitKind::Root && $actor->hasRole(UserRole::Admin);
        }

        return $this->canGive($actor, $unit, $role);
    }

    /**
     * Rule 2: nobody ends their own role. The admin may end any role;
     * otherwise only someone who could have added that role there.
     */
    public function end(User $actor, UnitMembership $membership): bool
    {
        if ($membership->user_id === $actor->id) {
            return false;
        }

        return $actor->hasRole(UserRole::Admin)
            || $this->canGive($actor, $membership->unit, $membership->role);
    }

    /**
     * Rule 7: a tutor's rights are set by a teacher or co-teacher above
     * them, never by the tutor.
     */
    public function setPermissions(User $actor, UnitMembership $membership): bool
    {
        return $membership->role === TreeRole::Tutor
            && $membership->user_id !== $actor->id
            && $this->canGive($actor, $membership->unit, TreeRole::Tutor);
    }

    /**
     * Whether one of $actor's active roles on $unit or above may give $role.
     */
    private function canGive(User $actor, TreeUnit $unit, TreeRole $role): bool
    {
        return $unit->coveringMemberships($actor)->get()->contains(
            fn (UnitMembership $own) => in_array($role, $own->role->canAdd(), true)
        );
    }
}

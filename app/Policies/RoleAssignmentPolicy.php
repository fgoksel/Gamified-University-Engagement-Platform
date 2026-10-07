<?php

namespace App\Policies;

use App\Enums\Capability;
use App\Models\RoleAssignment;
use App\Models\RoleDefinition;
use App\Models\Topic;
use App\Models\User;
use App\Services\TopicAccess;
use Illuminate\Auth\Access\Response;

/**
 * Who may give, end and hand over roles.
 *
 * Doing something and passing the power on are different: giving a role needs
 * the "give roles" capability at the topic AND every power in the role within
 * what the giver may pass on there. Nobody gives themselves a role, ends or
 * replaces their own, or ends a role that supplied their own authority.
 */
class RoleAssignmentPolicy
{
    private const GRANT_DEPTH = 50;

    public function __construct(private TopicAccess $access) {}

    public function viewAny(User $user, Topic $topic): bool
    {
        return $this->access->can($user, Capability::AccessView, $topic);
    }

    public function grant(User $user, Topic $topic, RoleDefinition $role, User $recipient): Response
    {
        if (! $this->access->isActive($user)) {
            return Response::deny('Your account is not active.');
        }

        if ($recipient->id === $user->id) {
            return Response::deny('You cannot give a role to yourself.');
        }

        return $this->giveRole($user, $topic, $role);
    }

    /**
     * The part of granting that does not depend on the recipient: used to
     * decide which roles to offer in the interface.
     */
    public function giveRole(User $user, Topic $topic, RoleDefinition $role): Response
    {
        if (! $this->access->isActive($user)) {
            return Response::deny('Your account is not active.');
        }

        if (! $this->access->can($user, Capability::AccessAssign, $topic)) {
            return Response::deny('You cannot give roles here.');
        }

        if (! $role->isGlobal() && ! $topic->isWithin($role->topic)) {
            return Response::deny('This role belongs to a different branch and cannot be used here.');
        }

        $exceeding = array_values(array_diff($role->capabilityList(), $this->access->delegable($user, $topic)));

        if ($exceeding !== []) {
            $names = implode(', ', array_map(fn (string $value) => Capability::from($value)->label(), $exceeding));

            return Response::deny("You cannot pass on these powers here: {$names}.");
        }

        return Response::allow();
    }

    public function end(User $user, RoleAssignment $assignment): Response
    {
        if (! $this->access->isActive($user)) {
            return Response::deny('Your account is not active.');
        }

        if ($assignment->user_id === $user->id) {
            return Response::deny('You cannot end or replace your own role. Another authorised person must do it.');
        }

        $topic = $assignment->topic;

        if (! $this->access->can($user, Capability::AccessEnd, $topic)) {
            return Response::deny('You cannot end roles here.');
        }

        if ($this->suppliesAuthorityOf($user, $assignment)) {
            return Response::deny('This role gave you your own authority, so it cannot be changed by you.');
        }

        $exceeding = array_values(array_diff($assignment->role->capabilityList(), $this->access->delegable($user, $topic)));

        if ($exceeding !== []) {
            $names = implode(', ', array_map(fn (string $value) => Capability::from($value)->label(), $exceeding));

            return Response::deny("This role has powers you cannot pass on, so you cannot end it: {$names}.");
        }

        return Response::allow();
    }

    /**
     * A handover: ending the holder's role and giving the same role at the same
     * topic to someone else. Both halves must be allowed.
     */
    public function replace(User $user, RoleAssignment $assignment, User $replacement): Response
    {
        $end = $this->end($user, $assignment);

        if ($end->denied()) {
            return $end;
        }

        return $this->grant($user, $assignment->topic, $assignment->role, $replacement);
    }

    /**
     * Whether $assignment is one of the assignments that gave $user their
     * authority at its topic, directly or further up the chain of grants.
     */
    public function suppliesAuthorityOf(User $user, RoleAssignment $assignment): bool
    {
        $queue = $this->access->coveringAssignments($user, $assignment->topic)->pluck('id')->all();
        $seen = [];

        for ($depth = 0; $queue !== [] && $depth < self::GRANT_DEPTH; $depth++) {
            if (in_array($assignment->id, $queue, true)) {
                return true;
            }

            $seen = [...$seen, ...$queue];
            $queue = array_values(array_diff(
                RoleAssignment::query()->whereIn('id', $queue)->whereNotNull('granted_by_assignment_id')->pluck('granted_by_assignment_id')->all(),
                $seen,
            ));
        }

        return false;
    }
}

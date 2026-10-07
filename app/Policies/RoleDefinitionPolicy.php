<?php

namespace App\Policies;

use App\Enums\Capability;
use App\Models\RoleDefinition;
use App\Models\Topic;
use App\Models\User;
use App\Services\TopicAccess;
use Illuminate\Auth\Access\Response;

/**
 * Who may define and change reusable roles.
 *
 * Global roles are for System Admins. A scoped role can be defined only by
 * someone with the "define roles" capability at that topic, and never with
 * more than they may pass on there (the delegation ceiling). A role that
 * supplies your own access cannot be changed by you.
 */
class RoleDefinitionPolicy
{
    public function __construct(private TopicAccess $access) {}

    /**
     * @param  list<string>  $capabilities
     * @param  list<string>  $delegable
     */
    public function create(User $user, ?Topic $scope, array $capabilities, array $delegable): Response
    {
        if (! $this->access->isActive($user)) {
            return Response::deny('Your account is not active.');
        }

        if ($scope === null) {
            return $this->access->isSystemAdmin($user)
                ? Response::allow()
                : Response::deny('Only a System Admin can define roles for the whole system.');
        }

        if (! $this->access->can($user, Capability::DefineRoles, $scope)) {
            return Response::deny('You cannot define roles here.');
        }

        return $this->withinCeiling($user, $scope, [...$capabilities, ...$delegable]);
    }

    /**
     * Change a role's name, description, powers or archived state.
     *
     * @param  list<string>  $capabilities  powers after the change
     * @param  list<string>  $delegable  pass-on powers after the change
     */
    public function update(User $user, RoleDefinition $role, array $capabilities, array $delegable, bool $changesPower): Response
    {
        if (! $this->access->isActive($user)) {
            return Response::deny('Your account is not active.');
        }

        if ($role->isGlobal()) {
            return $this->access->isSystemAdmin($user)
                ? $this->notOwnRole($user, $role, $changesPower)
                : Response::deny('Only a System Admin can change a role that applies everywhere.');
        }

        $scope = $role->topic;

        if (! $this->access->can($user, Capability::DefineRoles, $scope)) {
            return Response::deny('You cannot change roles here.');
        }

        $own = $this->notOwnRole($user, $role, $changesPower);
        if ($own->denied()) {
            return $own;
        }

        // Both what the role is now and what it would become must be within the ceiling,
        // so nobody changes a role that is stronger than they may pass on.
        return $this->withinCeiling($user, $scope, [
            ...$role->capabilityList(),
            ...$role->delegableList(),
            ...$capabilities,
            ...$delegable,
        ]);
    }

    /**
     * No one changes the powers of a role they hold themselves, directly or by
     * archiving it: that would be a way around self-protection.
     */
    private function notOwnRole(User $user, RoleDefinition $role, bool $changesPower): Response
    {
        if ($changesPower && $this->access->assignmentsOf($user)->contains('role_definition_id', $role->id)) {
            return Response::deny('You hold this role yourself, so another person must change its powers.');
        }

        return Response::allow();
    }

    /**
     * @param  list<string>  $capabilities
     */
    private function withinCeiling(User $user, Topic $scope, array $capabilities): Response
    {
        $ceiling = $this->access->delegable($user, $scope);
        $exceeding = array_values(array_diff(Capability::normalise($capabilities), $ceiling));

        if ($exceeding !== []) {
            $names = implode(', ', array_map(fn (string $value) => Capability::from($value)->label(), $exceeding));

            return Response::deny("You cannot pass on these powers here: {$names}.");
        }

        return Response::allow();
    }
}

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
            return Response::deny(__('policies.role.your_account_is_not_active'));
        }

        if ($scope === null) {
            return $this->access->isSystemAdmin($user)
                ? Response::allow()
                : Response::deny(__('policies.role.only_a_system_admin_can'));
        }

        if (! $this->access->can($user, Capability::DefineRoles, $scope)) {
            return Response::deny(__('policies.role.you_cannot_define_roles_here'));
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
            return Response::deny(__('policies.role.your_account_is_not_active'));
        }

        if ($role->isGlobal()) {
            return $this->access->isSystemAdmin($user)
                ? $this->notOwnRole($user, $role, $changesPower)
                : Response::deny(__('policies.role.only_a_system_admin_can_2'));
        }

        $scope = $role->topic;

        if (! $this->access->can($user, Capability::DefineRoles, $scope)) {
            return Response::deny(__('policies.role.you_cannot_change_roles_here'));
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
            return Response::deny(__('policies.role.you_hold_this_role_yourself'));
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

            return Response::deny(__('policies.cannot_pass_on_here', ['powers' => $names]));
        }

        return Response::allow();
    }
}

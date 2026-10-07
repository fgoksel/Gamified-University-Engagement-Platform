<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * Changes to who is a System Admin (the global "admin" account role) and to
 * account status.
 *
 * The invariant: at least one active System Admin always exists. Every
 * change runs in a transaction that first locks all active admin rows, then
 * re-reads the acting admin and counts who would remain. Two admins removing
 * each other at the same moment therefore cannot both succeed: the second
 * waits, sees that its actor is no longer an admin and is refused.
 *
 * Nobody removes or deactivates themselves. Topic roles never reach this
 * class: a role called "Admin" in a topic is only a topic role.
 */
class SystemAdminService
{
    public function __construct(private TopicAccess $access) {}

    /**
     * Make an active account a System Admin. Allowed for the only remaining
     * admin too, which is how a second admin is appointed.
     *
     * @throws AuthorizationException|ValidationException
     */
    public function appoint(User $actor, User $target): void
    {
        DB::transaction(function () use ($actor, $target) {
            $admins = $this->lockAdmins();
            $this->assertActingAdmin($actor, $admins);
            $target = User::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();

            if ($target->status !== 'active') {
                throw ValidationException::withMessages(['user' => 'Only an active account can become a System Admin.']);
            }

            if ($admins->contains($target->id) || $target->hasRole(UserRole::Admin)) {
                throw ValidationException::withMessages(['user' => "{$target->name} is already a System Admin."]);
            }

            $target->assignRole(UserRole::Admin);
            TopicAudit::record($actor, 'admin.appointed', null, 'user', $target->id, null, ['user' => $target->name]);
        });
    }

    /**
     * Take the System Admin role away from someone else.
     *
     * @throws AuthorizationException|ValidationException
     */
    public function remove(User $actor, User $target, string $reason): void
    {
        $this->requireReason($reason);

        DB::transaction(function () use ($actor, $target, $reason) {
            $admins = $this->lockAdmins();
            $this->assertActingAdmin($actor, $admins);
            $this->assertCanLoseAdmin($actor, $target, $admins);

            $target->removeRole(UserRole::Admin);
            TopicAudit::record($actor, 'admin.removed', null, 'user', $target->id, ['user' => $target->name], ['reason' => trim($reason)]);
        });
    }

    /**
     * Hand the System Admin role from one person to another in one step.
     *
     * @throws AuthorizationException|ValidationException
     */
    public function replace(User $actor, User $outgoing, User $incoming, string $reason): void
    {
        $this->requireReason($reason);

        DB::transaction(function () use ($actor, $outgoing, $incoming, $reason) {
            $admins = $this->lockAdmins();
            $this->assertActingAdmin($actor, $admins);
            $this->assertCanLoseAdmin($actor, $outgoing, $admins);

            $incoming = User::query()->whereKey($incoming->id)->lockForUpdate()->firstOrFail();

            if ($incoming->id === $outgoing->id || $incoming->status !== 'active' || $admins->contains($incoming->id)) {
                throw ValidationException::withMessages(['user' => 'Choose an active account that is not already a System Admin.']);
            }

            $incoming->assignRole(UserRole::Admin);
            $outgoing->removeRole(UserRole::Admin);

            TopicAudit::record($actor, 'admin.replaced', null, 'user', $outgoing->id, ['user' => $outgoing->name], ['replacement' => $incoming->name, 'reason' => trim($reason)]);
        });
    }

    /**
     * Deactivate an account: cannot log in, sessions end, invitation links stop.
     * Role assignments are kept untouched; an inactive account simply has no access.
     *
     * @throws AuthorizationException|ValidationException
     */
    public function deactivate(User $actor, User $target): void
    {
        DB::transaction(function () use ($actor, $target) {
            $admins = $this->lockAdmins();
            $this->assertActingAdmin($actor, $admins);

            if ($actor->id === $target->id) {
                throw new AuthorizationException('You cannot deactivate your own account.');
            }

            $target = User::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();

            if ($admins->contains($target->id) && $admins->diff([$target->id])->isEmpty()) {
                throw new AuthorizationException('Cannot deactivate the last remaining Administrator.');
            }

            $target->update([
                'status' => 'inactive',
                'activation_token' => null,
                'activation_token_expires_at' => null,
            ]);

            if (Schema::hasTable('sessions')) {
                DB::table('sessions')->where('user_id', $target->id)->delete();
            }

            TopicAudit::record($actor, 'account.deactivated', null, 'user', $target->id, null, ['user' => $target->name]);
            $this->access->flush();
        });
    }

    /**
     * Active role assignments a person holds, to warn before deactivating them.
     */
    public function activeAssignmentCount(User $user): int
    {
        return RoleAssignment::query()->active()->where('user_id', $user->id)->count();
    }

    /**
     * Lock the admin role rows and every active admin account, and return the
     * ids of the active admins.
     *
     * This must be the first statement of the transaction, and every decision
     * below uses this result. A locking read always sees the latest committed
     * data. An ordinary read, or a subquery inside a locking read, after waiting
     * for another transaction would still use the snapshot from before the wait
     * and could miss that transaction's change. So the role rows are locked
     * directly (not through a subquery) and so are the user rows.
     *
     * @return Collection<int, int>
     */
    private function lockAdmins(): Collection
    {
        $roleId = Role::query()->where('name', UserRole::Admin->value)->value('id');

        $holders = DB::table(config('permission.table_names.model_has_roles'))
            ->where('role_id', $roleId)
            ->where('model_type', (new User)->getMorphClass())
            ->orderBy('model_id')
            ->lockForUpdate()
            ->pluck('model_id');

        return User::query()
            ->whereIn('id', $holders)
            ->where('status', 'active')
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id')
            ->map(fn ($id) => (int) $id);
    }

    /**
     * The acting admin must still be an active admin right now, not just when
     * the request began.
     *
     * @param  Collection<int, int>  $admins
     *
     * @throws AuthorizationException
     */
    private function assertActingAdmin(User $actor, Collection $admins): void
    {
        if (! $admins->contains($actor->id)) {
            throw new AuthorizationException('Only an active System Admin can do this.');
        }
    }

    /**
     * @param  Collection<int, int>  $admins
     *
     * @throws AuthorizationException|ValidationException
     */
    private function assertCanLoseAdmin(User $actor, User $target, Collection $admins): void
    {
        if ($actor->id === $target->id) {
            throw new AuthorizationException('You cannot remove or replace yourself. Another System Admin must do it.');
        }

        if (! $admins->contains($target->id) && ! $target->hasRole(UserRole::Admin)) {
            throw ValidationException::withMessages(['user' => "{$target->name} is not a System Admin."]);
        }

        // The actor is a different active admin, so one remains. Counting anyway keeps
        // the invariant explicit and guards future callers.
        if ($admins->diff([$target->id])->isEmpty()) {
            throw new AuthorizationException('The last active System Admin cannot be removed.');
        }
    }

    /**
     * @throws ValidationException
     */
    private function requireReason(string $reason): void
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Give a reason for the change.']);
        }
    }
}

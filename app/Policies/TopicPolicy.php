<?php

namespace App\Policies;

use App\Enums\Capability;
use App\Models\Topic;
use App\Models\User;
use App\Services\TopicAccess;
use Illuminate\Auth\Access\Response;

/**
 * Who may see and change topics. All answers come from TopicAccess, so the
 * explorer, the JSON endpoints and the services share one rule.
 */
class TopicPolicy
{
    public function __construct(private TopicAccess $access) {}

    public function view(User $user, Topic $topic): bool
    {
        return $this->access->canView($user, $topic);
    }

    /**
     * Add a child under $parent, or a new root when $parent is null (System Admin only).
     */
    public function create(User $user, ?Topic $parent = null): Response
    {
        if (! $this->access->isActive($user)) {
            return Response::deny(__('policies.area.your_account_is_not_active'));
        }

        if ($parent === null) {
            return $this->access->isSystemAdmin($user)
                ? Response::allow()
                : Response::deny(__('policies.area.only_a_system_admin_can'));
        }

        return $this->access->can($user, Capability::Create, $parent)
            ? Response::allow()
            : Response::deny(__('policies.area.you_cannot_add_areas_here'));
    }

    /**
     * Edit any topic in the branch, or the topics you added yourself while
     * you still hold a role that allows adding topics there.
     */
    public function update(User $user, Topic $topic): Response
    {
        if ($this->access->can($user, Capability::Edit, $topic)) {
            return Response::allow();
        }

        if ($topic->created_by_id === $user->id && $this->access->can($user, Capability::Create, $topic)) {
            return Response::allow();
        }

        return Response::deny(__('policies.area.you_cannot_edit_this_area'));
    }

    /**
     * Moving needs the power at the topic and at the destination. A move to the
     * top level ($destination null) is for System Admins.
     */
    public function move(User $user, Topic $topic, ?Topic $destination): Response
    {
        if (! $this->access->can($user, Capability::Organise, $topic)) {
            return Response::deny(__('policies.area.you_cannot_move_this_area'));
        }

        if ($destination === null) {
            return $this->access->isSystemAdmin($user)
                ? Response::allow()
                : Response::deny(__('policies.area.only_a_system_admin_can_2'));
        }

        return $this->access->can($user, Capability::Organise, $destination)
            ? Response::allow()
            : Response::deny(__('policies.area.you_cannot_move_areas'));
    }

    /**
     * Open the Access panel of a topic (read only).
     */
    public function viewAccess(User $user, Topic $topic): bool
    {
        return $this->access->can($user, Capability::AccessView, $topic);
    }

    /**
     * Hide a topic and everything below it (nothing is deleted).
     */
    public function archive(User $user, Topic $topic): Response
    {
        return $this->access->can($user, Capability::Archive, $topic)
            ? Response::allow()
            : Response::deny(__('policies.area.you_cannot_archive_areas_here'));
    }

    /**
     * Bring an archived topic back. Needs the same power as archiving it.
     */
    public function restore(User $user, Topic $topic): Response
    {
        return $this->access->can($user, Capability::Archive, $topic)
            ? Response::allow()
            : Response::deny(__('policies.area.you_cannot_restore_areas_here'));
    }

    /**
     * Permanent deletion is for System Admins only, and the service further
     * refuses anything that has ever been used.
     */
    public function delete(User $user, Topic $topic): Response
    {
        return $this->access->isSystemAdmin($user)
            ? Response::allow()
            : Response::deny(__('policies.area.only_a_system_admin_can_3'));
    }
}

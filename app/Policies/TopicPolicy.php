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
            return Response::deny('Your account is not active.');
        }

        if ($parent === null) {
            return $this->access->isSystemAdmin($user)
                ? Response::allow()
                : Response::deny('Only a System Admin can start a new top-level topic.');
        }

        return $this->access->can($user, Capability::Create, $parent)
            ? Response::allow()
            : Response::deny('You cannot add topics here.');
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

        return Response::deny('You cannot edit this topic.');
    }

    /**
     * Moving needs the power at the topic and at the destination. A move to the
     * top level ($destination null) is for System Admins.
     */
    public function move(User $user, Topic $topic, ?Topic $destination): Response
    {
        if (! $this->access->can($user, Capability::Organise, $topic)) {
            return Response::deny('You cannot move this topic.');
        }

        if ($destination === null) {
            return $this->access->isSystemAdmin($user)
                ? Response::allow()
                : Response::deny('Only a System Admin can make a topic top-level.');
        }

        return $this->access->can($user, Capability::Organise, $destination)
            ? Response::allow()
            : Response::deny('You cannot move topics into that place.');
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
            : Response::deny('You cannot archive topics here.');
    }

    /**
     * Bring an archived topic back. Needs the same power as archiving it.
     */
    public function restore(User $user, Topic $topic): Response
    {
        return $this->access->can($user, Capability::Archive, $topic)
            ? Response::allow()
            : Response::deny('You cannot restore topics here.');
    }

    /**
     * Permanent deletion is for System Admins only, and the service further
     * refuses anything that has ever been used.
     */
    public function delete(User $user, Topic $topic): Response
    {
        return $this->access->isSystemAdmin($user)
            ? Response::allow()
            : Response::deny('Only a System Admin can permanently delete a topic.');
    }
}

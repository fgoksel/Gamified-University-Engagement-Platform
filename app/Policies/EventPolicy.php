<?php

namespace App\Policies;

use App\Models\Event;
use App\Models\User;
use App\Services\EventPlacement;

/**
 * Who may manage an event (Task #37). Authority comes from the owning topic,
 * never from who created the event (Task #64): anyone who may create events
 * at the owning topic may manage the events owned there.
 */
class EventPolicy
{
    public function __construct(private EventPlacement $placement) {}

    public function manage(User $user, Event $event): bool
    {
        return $user->can('events.manage_own')
            && $this->placement->mayOwn($user, $event->owningTopic);
    }
}

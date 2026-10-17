<?php

namespace App\Policies;

use App\Enums\Capability;
use App\Models\Event;
use App\Models\User;
use App\Services\TopicAccess;

/**
 * Who may see, apply to and run an event (Task #65).
 *
 * Authority comes from the capabilities held at the event's owning topic
 * (management) or audience topic (seeing and applying), exactly as for topics.
 * The creator, a role name or an account type never grants anything by itself,
 * and an archived topic freezes everything in it.
 */
class EventPolicy
{
    public function __construct(private TopicAccess $access) {}

    /**
     * Start an event somewhere: the person may create events at one or more topics.
     * Where exactly is checked against the chosen topic (see EventPlacement).
     */
    public function create(User $user): bool
    {
        return $this->access->canAnywhere($user, Capability::EventsCreate);
    }

    /**
     * Anyone who runs the event in some way. Staff see drafts, events on hold and hidden events.
     */
    public function staff(User $user, Event $event): bool
    {
        $topic = $event->owningTopic;

        if (! $this->access->canView($user, $topic)) {
            return false;
        }

        foreach ([Capability::EventsCreate, Capability::EventsManage, Capability::ApplicationsManage, Capability::PointsCredit, Capability::QrCodesManage] as $capability) {
            if ($this->access->can($user, $capability, $topic)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Seeing the event: staff always; everybody else once it is published, revealed,
     * not on hold, and announced to an audience node they may see.
     */
    public function view(User $user, Event $event): bool
    {
        return $this->staff($user, $event) || $this->announcedTo($user, $event);
    }

    public function apply(User $user, Event $event): bool
    {
        if (! $event->isPublished() || $user->neptun_code === null) {
            return false;
        }

        return $this->announcedTo($user, $event)
            && $this->access->can($user, Capability::EventsApply, $event->audienceTopic);
    }

    public function manage(User $user, Event $event): bool
    {
        return $this->holds($user, $event, Capability::EventsManage);
    }

    public function manageApplicants(User $user, Event $event): bool
    {
        return $this->holds($user, $event, Capability::ApplicationsManage);
    }

    public function credit(User $user, Event $event): bool
    {
        return $this->holds($user, $event, Capability::PointsCredit);
    }

    public function manageQr(User $user, Event $event): bool
    {
        return $this->holds($user, $event, Capability::QrCodesManage);
    }

    private function holds(User $user, Event $event, Capability $capability): bool
    {
        $topic = $event->owningTopic;

        return $this->access->canView($user, $topic) && $this->access->can($user, $capability, $topic);
    }

    private function announcedTo(User $user, Event $event): bool
    {
        if ($event->audience_topic_id === null || $event->status === Event::STATUS_DRAFT) {
            return false;
        }

        if ($event->isOnHold() || ! $event->isRevealed()) {
            return false;
        }

        $audience = $event->audienceTopic;

        return $this->access->canView($user, $audience)
            && $this->access->can($user, Capability::EventsView, $audience);
    }
}

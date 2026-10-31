<?php

namespace App\Policies;

use App\Enums\Capability;
use App\Models\Event;
use App\Models\PointTransaction;
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
     * Seeing the event: staff always; everybody else once it is published, not on hold,
     * and announced to an audience node they are assigned to. A surprise event is never
     * announced (Task #86); the students it gave points to may open it afterwards.
     */
    public function view(User $user, Event $event): bool
    {
        return $this->staff($user, $event) || $this->announcedTo($user, $event) || $this->creditedFor($user, $event);
    }

    public function apply(User $user, Event $event): bool
    {
        if (! $event->isPublished() || $user->neptun_code === null) {
            return false;
        }

        return $this->announcedTo($user, $event)
            && $this->access->holdsAt($user, Capability::EventsApply, $event->audienceTopic);
    }

    public function manage(User $user, Event $event): bool
    {
        return $this->holds($user, $event, Capability::EventsManage);
    }

    /**
     * Change an event after it was created (Task #68, UC-1.3 "Editing an Event").
     * Whoever may manage the event at its owning topic may edit it; someone who may only
     * create events there (and so only saves drafts, Task #65) may edit drafts.
     * Which fields and states are open is decided by EventEditor.
     */
    public function update(User $user, Event $event): bool
    {
        if ($this->manage($user, $event)) {
            return true;
        }

        $topic = $event->owningTopic;

        return $event->status === Event::STATUS_DRAFT
            && $this->access->canView($user, $topic)
            && $this->access->can($user, Capability::EventsCreate, $topic);
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

    /**
     * A student whose points came from a surprise event learns of it then (26 Oct, Q8): they
     * may open the event, under the same freezes as any other event. It is still never
     * announced, so it is not in the catalog and they cannot apply or scan.
     */
    private function creditedFor(User $user, Event $event): bool
    {
        if (! $event->is_hidden || $event->status === Event::STATUS_DRAFT || $event->isOnHold()) {
            return false;
        }

        if ($event->owningTopic === null || $this->access->isHidden($event->owningTopic)) {
            return false;
        }

        return PointTransaction::query()
            ->where('event_id', $event->id)
            ->where('student_id', $user->id)
            ->where('type', PointTransaction::CREDIT)
            ->exists();
    }

    private function announcedTo(User $user, Event $event): bool
    {
        if ($event->audience_topic_id === null || $event->status === Event::STATUS_DRAFT) {
            return false;
        }

        // A surprise event stays hidden from students (Task #86).
        if ($event->isOnHold() || $event->is_hidden) {
            return false;
        }

        // An archived owning topic freezes the event, even if it is announced to a
        // topic above that is still open (Task #66). Restoring brings it back.
        if ($event->owningTopic === null || $this->access->isHidden($event->owningTopic)) {
            return false;
        }

        $audience = $event->audienceTopic;

        // The audience is the set of people assigned to that node itself (Task #71, meeting
        // of 26 Oct): a student of a course does not see an event announced to its faculty,
        // and a faculty student does not see a course's event, unless they are assigned there
        // too. Nothing flows up or down the tree on its own.
        return ! $this->access->isHidden($audience)
            && $this->access->holdsAt($user, Capability::EventsView, $audience);
    }
}

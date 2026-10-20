<?php

namespace Tests\Feature\Events;

use App\Enums\Capability;
use App\Enums\UserRole;
use App\Models\Event;
use App\Models\EventApplication;
use App\Models\RoleDefinition;
use App\Models\Topic;
use App\Models\User;
use Tests\Feature\Topics\TopicTestCase;

/**
 * Fixtures for the event, ledger and QR tests: a small topic tree
 * (University > Faculty > Course), one event owned by the course, and helpers
 * for people holding exactly the capabilities a test needs.
 */
abstract class EventTestCase extends TopicTestCase
{
    protected Topic $university;

    protected Topic $faculty;

    protected Topic $course;

    protected Event $event;

    protected function setUp(): void
    {
        parent::setUp();

        $this->university = $this->topic('University');
        $this->faculty = $this->topic('Faculty', $this->university);
        $this->course = $this->topic('Databases', $this->faculty);
        $this->event = $this->makeEvent();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function makeEvent(array $data = []): Event
    {
        return Event::factory()->create([
            'owning_topic_id' => $this->course->id,
            'audience_topic_id' => $this->course->id,
            ...$data,
        ]);
    }

    /**
     * A staff member holding the given capabilities (plus what they need) at a topic.
     *
     * @param  list<Capability>  $capabilities
     */
    protected function staff(array $capabilities, ?Topic $at = null, ?string $name = null): User
    {
        $user = $this->makeUser(UserRole::Teacher, $name);
        $this->assign($user, $this->role('Staff '.uniqid(), [Capability::View, Capability::EventsView, ...$capabilities]), $at ?? $this->course);

        return $user;
    }

    protected function student(?string $name = null, ?string $neptun = null): User
    {
        $student = $this->makeUser(UserRole::Student, $name);
        $student->forceFill(['neptun_code' => $neptun ?? strtoupper(substr(md5(uniqid()), 0, 6))])->save();

        return $student;
    }

    protected function studentRole(): RoleDefinition
    {
        return RoleDefinition::query()->where('name', 'Event student')->first()
            ?? $this->role('Event student', [Capability::View, Capability::EventsView, Capability::EventsApply]);
    }

    protected function accepted(User $student, ?Event $event = null, string $status = EventApplication::ACCEPTED): EventApplication
    {
        return EventApplication::create([
            'event_id' => ($event ?? $this->event)->id,
            'student_id' => $student->id,
            'status' => $status,
            'applied_at' => now(),
        ]);
    }
}

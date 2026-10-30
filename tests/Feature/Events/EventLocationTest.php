<?php

namespace Tests\Feature\Events;

use App\Enums\Capability;
use App\Models\Event;
use App\Models\EventApplication;
use App\Models\TopicTag;
use App\Models\User;
use App\Notifications\EventStatusChangedNotification;
use App\Services\EventEditor;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Task #77: a free-text location, saved and edited on the Event Form and shown on the
 * event page and the event cards. It may stay empty; there is no booking or clash check.
 * Students who applied are told when it changes, like when the time changes.
 */
class EventLocationTest extends EventTestCase
{
    private TopicTag $tag;

    private User $organiser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tag = TopicTag::create(['name' => 'SQL', 'category' => 'academic', 'is_active' => true]);
        $this->organiser = $this->staff([Capability::EventsCreate, Capability::EventsManage]);
    }

    /**
     * @return array<string, mixed>
     */
    private function newEvent(array $overrides = []): array
    {
        return [
            'action' => 'publish',
            'title' => 'SQL night',
            'description' => 'Hands-on SQL practice.',
            'topic_tag_id' => $this->tag->id,
            'owning_topic_id' => $this->course->id,
            'audience_topic_id' => $this->course->id,
            'capacity' => 25,
            'application_type' => 'fcfs',
            'points_value' => 10,
            'event_starts_at' => now()->addDays(10)->format('Y-m-d\TH:i'),
            'event_ends_at' => now()->addDays(10)->addHours(2)->format('Y-m-d\TH:i'),
            'application_deadline' => now()->addDays(8)->format('Y-m-d\TH:i'),
            'completion_deadline' => now()->addDays(17)->format('Y-m-d\TH:i'),
            'is_hidden' => false,
            'reveal_at' => null,
            'qr_enabled' => false,
            ...$overrides,
        ];
    }

    private function upcoming(array $data = []): Event
    {
        return $this->makeEvent([
            'topic_tag_id' => $this->tag->id,
            'event_starts_at' => now()->addDays(5)->startOfHour(),
            'event_ends_at' => now()->addDays(5)->startOfHour()->addHours(2),
            'application_deadline' => now()->addDays(4)->startOfHour(),
            'completion_deadline' => now()->addDays(12)->startOfHour(),
            ...$data,
        ]);
    }

    /**
     * The edit form as the page sends it, with some fields changed.
     *
     * @return array<string, mixed>
     */
    private function editForm(Event $event, array $changes = []): array
    {
        $event->refresh();

        return [
            'version' => app(EventEditor::class)->version($event),
            'title' => $event->title,
            'description' => $event->description,
            'location' => $event->location,
            'topic_tag_id' => $event->topic_tag_id,
            'owning_topic_id' => $event->owning_topic_id,
            'audience_topic_id' => $event->audience_topic_id,
            'capacity' => $event->capacity,
            'application_type' => $event->application_type,
            'points_value' => $event->points_value,
            'event_starts_at' => $event->event_starts_at->format('Y-m-d\TH:i'),
            'event_ends_at' => $event->event_ends_at->format('Y-m-d\TH:i'),
            'application_deadline' => $event->application_deadline->format('Y-m-d\TH:i'),
            'completion_deadline' => $event->completion_deadline->format('Y-m-d\TH:i'),
            'is_hidden' => $event->is_hidden,
            'reveal_at' => $event->reveal_at?->format('Y-m-d\TH:i'),
            'qr_enabled' => $event->qr_enabled,
            ...$changes,
        ];
    }

    private function save(Event $event, array $changes = [])
    {
        return $this->actingAs($this->organiser)->put("/events/{$event->id}", $this->editForm($event, $changes));
    }

    public function test_a_new_event_saves_its_location(): void
    {
        $this->actingAs($this->organiser)
            ->post('/events', $this->newEvent(['location' => '  Room 0-311, North Building  ']))
            ->assertSessionHasNoErrors();

        $this->assertSame('Room 0-311, North Building', Event::where('title', 'SQL night')->sole()->location);
    }

    public function test_the_location_may_be_left_empty(): void
    {
        $this->actingAs($this->organiser)->post('/events', $this->newEvent(['title' => 'Empty', 'location' => '']))->assertSessionHasNoErrors();
        $this->actingAs($this->organiser)->post('/events', $this->newEvent(['title' => 'Spaces', 'location' => '   ']))->assertSessionHasNoErrors();
        $this->actingAs($this->organiser)->post('/events', $this->newEvent(['title' => 'Left out']))->assertSessionHasNoErrors();

        foreach (['Empty', 'Spaces', 'Left out'] as $title) {
            $this->assertNull(Event::where('title', $title)->sole()->location, $title);
        }
    }

    public function test_the_location_is_at_most_255_characters(): void
    {
        $this->actingAs($this->organiser)
            ->post('/events', $this->newEvent(['location' => str_repeat('a', 256)]))
            ->assertSessionHasErrors('location');

        $event = $this->upcoming();
        $this->save($event, ['location' => str_repeat('a', 256)])->assertSessionHasErrors('location');

        $this->save($event, ['location' => str_repeat('á', 255)])->assertSessionHasNoErrors();
        $this->assertSame(str_repeat('á', 255), $event->fresh()->location);
    }

    public function test_the_edit_page_shows_the_location_and_never_locks_it(): void
    {
        $event = $this->upcoming(['location' => 'Lab 2']);
        $this->accepted($this->student(), $event);

        $this->actingAs($this->organiser)->get("/events/{$event->id}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('event.location', 'Lab 2')
                ->has('locks.audience_topic_id')
                ->missing('locks.location'));
    }

    public function test_the_location_can_be_changed_and_cleared_after_students_applied(): void
    {
        $event = $this->upcoming(['location' => 'Lab 2']);
        $this->accepted($this->student(), $event);

        $this->save($event, ['location' => 'Aula Magna'])->assertSessionHasNoErrors();
        $this->assertSame('Aula Magna', $event->fresh()->location);

        $this->save($event, ['location' => ''])->assertSessionHasNoErrors();
        $this->assertNull($event->fresh()->location);
    }

    public function test_a_page_that_does_not_send_the_location_keeps_it(): void
    {
        $event = $this->upcoming(['location' => 'Lab 2']);
        $form = $this->editForm($event, ['title' => 'New title']);
        unset($form['location']);

        $this->actingAs($this->organiser)->put("/events/{$event->id}", $form)->assertSessionHasNoErrors();

        $this->assertSame('New title', $event->fresh()->title);
        $this->assertSame('Lab 2', $event->fresh()->location);
    }

    public function test_students_see_the_location_on_the_card_the_event_page_and_their_applications(): void
    {
        $event = $this->upcoming(['title' => 'With a room', 'location' => 'Room 0-311']);
        $this->upcoming(['title' => 'Without a room', 'location' => null]);

        $student = $this->student();
        $this->assign($student, $this->studentRole(), $this->course);
        $this->accepted($student, $event);

        $this->actingAs($student)->get('/events')
            ->assertOk()
            ->assertInertia(function (Assert $page) {
                $locations = collect($page->toArray()['props']['events']['data'])->pluck('location', 'title');
                $this->assertSame('Room 0-311', $locations['With a room']);
                $this->assertNull($locations['Without a room']);
            });

        $this->actingAs($student)->get("/events/{$event->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('event.location', 'Room 0-311'));

        $this->actingAs($student)->get('/my-events')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Events/Applied')
                ->where('applications.0.event.location', 'Room 0-311'));
    }

    public function test_organisers_see_the_location_on_their_event_cards(): void
    {
        $this->upcoming(['title' => 'With a room', 'location' => 'Room 0-311']);

        $this->actingAs($this->organiser)->get('/my-events')
            ->assertOk()
            ->assertInertia(function (Assert $page) {
                $page->component('Events/Organized');
                $locations = collect($page->toArray()['props']['events'])->pluck('location', 'title');
                $this->assertSame('Room 0-311', $locations['With a room']);
            });
    }

    public function test_applicants_are_told_when_the_location_changes(): void
    {
        Notification::fake();
        $event = $this->upcoming(['location' => 'Lab 2']);
        $told = [$this->student(), $this->student(), $this->student()];
        $this->accepted($told[0], $event, EventApplication::PENDING);
        $this->accepted($told[1], $event);
        $this->accepted($told[2], $event, EventApplication::WAITLISTED);
        $notTold = [$this->student(), $this->student()];
        $this->accepted($notTold[0], $event, EventApplication::WITHDRAWN);
        $this->accepted($notTold[1], $event, EventApplication::REJECTED);

        $this->save($event, ['location' => 'Aula Magna'])->assertSessionHasNoErrors();

        Notification::assertCount(3);
        foreach ($told as $student) {
            Notification::assertSentTo($student, EventStatusChangedNotification::class, fn ($n) => $n->change === EventStatusChangedNotification::RELOCATED
                && $n->eventId === $event->id
                && $n->location === 'Aula Magna');
        }
        Notification::assertNotSentTo($notTold, EventStatusChangedNotification::class);
    }

    public function test_setting_a_location_for_the_first_time_or_removing_it_is_news_too(): void
    {
        Notification::fake();
        $event = $this->upcoming();
        $this->accepted($student = $this->student(), $event);

        $this->save($event, ['location' => 'Room 0-311'])->assertSessionHasNoErrors();
        $this->save($event, ['location' => ''])->assertSessionHasNoErrors();

        $this->assertSame(
            [[EventStatusChangedNotification::RELOCATED, 'Room 0-311'], [EventStatusChangedNotification::RELOCATED, null]],
            Notification::sent($student, EventStatusChangedNotification::class)->map(fn ($n) => [$n->change, $n->location])->values()->all()
        );
    }

    public function test_nothing_is_sent_when_the_location_stays_the_same(): void
    {
        Notification::fake();
        $event = $this->upcoming(['location' => 'Lab 2']);
        $this->accepted($this->student(), $event);

        // Spaces around it are not a change, and neither is a page that leaves the field out.
        $this->save($event, ['title' => 'New title', 'location' => '  Lab 2  '])->assertSessionHasNoErrors();
        $form = $this->editForm($event);
        unset($form['location']);
        $this->actingAs($this->organiser)->put("/events/{$event->id}", $form)->assertSessionHasNoErrors();

        Notification::assertNothingSent();
    }

    public function test_a_new_time_and_a_new_location_are_one_message(): void
    {
        Notification::fake();
        $event = $this->upcoming(['location' => 'Lab 2']);
        $this->accepted($student = $this->student(), $event);

        $this->save($event, [
            'location' => 'Aula Magna',
            'event_starts_at' => now()->addDays(6)->startOfHour()->format('Y-m-d\TH:i'),
            'event_ends_at' => now()->addDays(6)->startOfHour()->addHours(2)->format('Y-m-d\TH:i'),
        ])->assertSessionHasNoErrors();

        Notification::assertCount(1);
        Notification::assertSentTo($student, EventStatusChangedNotification::class, fn ($n) => $n->change === EventStatusChangedNotification::RESCHEDULED_AND_RELOCATED
            && $n->location === 'Aula Magna');
    }

    public function test_a_draft_tells_nobody_but_a_held_event_tells_its_applicants(): void
    {
        Notification::fake();
        $draft = $this->upcoming(['status' => Event::STATUS_DRAFT, 'published_at' => null]);
        $this->save($draft, ['location' => 'Room 1'])->assertSessionHasNoErrors();
        Notification::assertNothingSent();

        $held = $this->upcoming(['status' => Event::STATUS_ON_HOLD, 'location' => 'Room 1']);
        $this->accepted($student = $this->student(), $held);
        $this->save($held, ['location' => 'Room 2'])->assertSessionHasNoErrors();

        Notification::assertSentTo($student, EventStatusChangedNotification::class, fn ($n) => $n->change === EventStatusChangedNotification::RELOCATED);
    }

    public function test_students_read_the_new_location_on_the_notifications_page(): void
    {
        $event = $this->upcoming(['title' => 'SQL Workshop', 'location' => 'Lab 2']);
        $this->accepted($student = $this->student(), $event);

        $this->save($event, ['location' => 'Aula Magna'])->assertSessionHasNoErrors();
        // A minute later, so "newest first" is unambiguous.
        $this->travel(1)->minutes();
        $this->save($event, [
            'location' => '',
            'event_starts_at' => now()->addDays(6)->startOfHour()->format('Y-m-d\TH:i'),
            'event_ends_at' => now()->addDays(6)->startOfHour()->addHours(2)->format('Y-m-d\TH:i'),
        ])->assertSessionHasNoErrors();

        $this->actingAs($student)->get('/notifications')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('notifications.data.0.title', 'The time and location of SQL Workshop changed')
                ->where('notifications.data.0.body', 'The location was removed. You keep your place. Check the new date; if it no longer suits you, you can withdraw.')
                ->where('notifications.data.1.title', 'The location of SQL Workshop changed')
                ->where('notifications.data.1.body', 'It now takes place at Aula Magna. You keep your place; if it no longer suits you, you can withdraw.')
                ->where('notifications.data.1.link', '/my-events'));
    }

    public function test_each_email_has_its_own_subject_and_says_where_the_event_is_now(): void
    {
        $student = $this->student();
        $mail = fn (string $change, ?string $location) => (new EventStatusChangedNotification($change, 1, 'SQL Workshop', location: $location))->toMail($student);

        $subjects = collect([
            EventStatusChangedNotification::CANCELLED,
            EventStatusChangedNotification::ON_HOLD,
            EventStatusChangedNotification::RESTORED,
            EventStatusChangedNotification::RESCHEDULED,
            EventStatusChangedNotification::RELOCATED,
            EventStatusChangedNotification::RESCHEDULED_AND_RELOCATED,
        ])->map(fn (string $change) => $mail($change, 'Aula Magna')->subject);
        $this->assertCount(6, $subjects->unique());

        $moved = $mail(EventStatusChangedNotification::RELOCATED, 'Aula Magna');
        $this->assertStringContainsString('SQL Workshop', implode(' ', $moved->introLines));
        $this->assertContains('New location: Aula Magna', $moved->introLines);

        $removed = $mail(EventStatusChangedNotification::RESCHEDULED_AND_RELOCATED, null);
        $this->assertContains('The location was removed.', $removed->introLines);
    }
}

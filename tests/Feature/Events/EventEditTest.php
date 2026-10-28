<?php

namespace Tests\Feature\Events;

use App\Enums\Capability;
use App\Models\Event;
use App\Models\EventApplication;
use App\Models\Semester;
use App\Models\TopicTag;
use App\Models\User;
use App\Notifications\EventStatusChangedNotification;
use App\Services\EventEditor;
use App\Services\PointLedger;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Task #68: an organiser changes an event after creating it (UC-1.3, "Editing an Event").
 */
class EventEditTest extends EventTestCase
{
    private TopicTag $tag;

    private User $organiser;

    protected function setUp(): void
    {
        parent::setUp();

        Semester::query()->exists() || Semester::create(['name' => 'Autumn', 'starts_at' => now()->subMonth()->toDateString(), 'ends_at' => now()->addMonths(3)->toDateString(), 'status' => 'active']);
        $this->tag = TopicTag::create(['name' => 'SQL', 'category' => 'academic', 'is_active' => true]);
        $this->organiser = $this->staff([Capability::EventsCreate, Capability::EventsManage, Capability::PointsCredit]);
    }

    /** An event that has not started yet, so everything is still open. */
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
     * The form the page sends: the event as it is, with some fields changed.
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function form(Event $event, array $changes = []): array
    {
        $event->refresh();

        return [
            'version' => app(EventEditor::class)->version($event),
            'title' => $event->title,
            'description' => $event->description,
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

    private function save(User $user, Event $event, array $changes = [])
    {
        return $this->actingAs($user)->put("/events/{$event->id}", $this->form($event, $changes));
    }

    public function test_the_edit_page_shows_the_event_and_what_is_locked(): void
    {
        $event = $this->upcoming(['title' => 'SQL Workshop']);
        $this->accepted($this->student(), $event);

        $this->actingAs($this->organiser)->get("/events/{$event->id}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Events/Edit')
                ->where('event.title', 'SQL Workshop')
                ->where('event.event_starts_at', $event->event_starts_at->format('Y-m-d\TH:i'))
                ->where('editable', true)
                ->has('locks.owning_topic_id')
                ->has('locks.application_type')
                ->missing('locks.title')
                ->where('minimumCapacity', 1)
                ->where('tabs.applicants', false)
                ->where('tabs.credit', true));
    }

    public function test_a_draft_can_be_changed_completely(): void
    {
        $event = $this->upcoming(['status' => 'draft', 'published_at' => null]);
        $other = $this->tag->replicate()->fill(['name' => 'Chess', 'category' => 'sports']);
        $other->save();

        $this->save($this->organiser, $event, [
            'title' => 'New title',
            'description' => 'New text',
            'topic_tag_id' => $other->id,
            'capacity' => 12,
            'application_type' => 'teacher_select',
            'points_value' => 25,
            'event_starts_at' => now()->addDays(8)->startOfHour()->format('Y-m-d\TH:i'),
            'event_ends_at' => now()->addDays(8)->startOfHour()->addHours(3)->format('Y-m-d\TH:i'),
            'application_deadline' => now()->addDays(7)->startOfHour()->format('Y-m-d\TH:i'),
            'completion_deadline' => now()->addDays(15)->startOfHour()->format('Y-m-d\TH:i'),
        ])->assertRedirect("/events/{$event->id}/edit")->assertSessionHas('success');

        $event->refresh();
        $this->assertSame('New title', $event->title);
        $this->assertSame('sports', $event->category);
        $this->assertSame(12, $event->capacity);
        $this->assertSame('teacher_select', $event->application_type);
        $this->assertSame(25, $event->points_value);
        $this->assertSame('draft', $event->status);
    }

    public function test_a_published_event_without_applicants_keeps_only_the_topic_locked(): void
    {
        $event = $this->upcoming();

        $this->save($this->organiser, $event, [
            'title' => 'Better title',
            'capacity' => 5,
            'application_type' => 'teacher_select',
            'points_value' => 30,
            'audience_topic_id' => $this->faculty->id,
        ])->assertSessionHasNoErrors();

        $event->refresh();
        $this->assertSame('Better title', $event->title);
        $this->assertSame(30, $event->points_value);
        $this->assertSame($this->faculty->id, $event->audience_topic_id);
        $this->assertSame('published', $event->status);

        $this->save($this->organiser, $event, ['owning_topic_id' => $this->faculty->id])
            ->assertSessionHasErrors(['owning_topic_id']);
        $this->assertSame($this->course->id, $event->refresh()->owning_topic_id);
    }

    public function test_applicants_lock_the_audience_and_the_way_places_are_given(): void
    {
        $event = $this->upcoming();
        $this->accepted($this->student(), $event, 'pending');

        $this->save($this->organiser, $event, ['application_type' => 'teacher_select'])->assertSessionHasErrors('application_type');
        $this->save($this->organiser, $event, ['audience_topic_id' => $this->faculty->id])->assertSessionHasErrors('audience_topic_id');
        $this->save($this->organiser, $event, ['title' => 'Still fine'])->assertSessionHasNoErrors();

        $this->assertSame('fcfs', $event->refresh()->application_type);
        $this->assertSame($this->course->id, $event->audience_topic_id);
        $this->assertSame('Still fine', $event->title);
    }

    public function test_a_withdrawn_application_does_not_lock_anything(): void
    {
        $event = $this->upcoming();
        $this->accepted($this->student(), $event, 'withdrawn');

        $this->save($this->organiser, $event, ['application_type' => 'teacher_select'])->assertSessionHasNoErrors();
        $this->assertSame('teacher_select', $event->refresh()->application_type);
    }

    public function test_places_cannot_drop_below_the_accepted_students(): void
    {
        $event = $this->upcoming(['capacity' => 10]);
        foreach (range(1, 3) as $i) {
            $this->accepted($this->student(), $event);
        }

        $this->save($this->organiser, $event, ['capacity' => 2])->assertSessionHasErrors(['capacity' => '3 students are already accepted, so there cannot be fewer places.']);
        $this->save($this->organiser, $event, ['capacity' => 3])->assertSessionHasNoErrors();
        $this->assertSame(3, $event->refresh()->capacity);
    }

    public function test_credited_events_keep_their_points_and_category(): void
    {
        $event = $this->upcoming();
        $student = $this->student();
        $this->accepted($student, $event);
        $event->update(['event_starts_at' => now()->subHour(), 'event_ends_at' => now()->addHour(), 'application_deadline' => now()->subDay()]);
        app(PointLedger::class)->credit($event, $student, $this->organiser, PointLedger::MANUAL);
        $other = TopicTag::create(['name' => 'Chess', 'category' => 'sports', 'is_active' => true]);

        $this->save($this->organiser, $event, ['points_value' => 99])->assertSessionHasErrors('points_value');
        $this->save($this->organiser, $event, ['topic_tag_id' => $other->id])->assertSessionHasErrors('topic_tag_id');
        $this->save($this->organiser, $event, ['capacity' => 40])->assertSessionHasNoErrors();

        $this->assertSame(10, $event->refresh()->points_value);
        $this->assertSame(40, $event->capacity);
    }

    public function test_a_running_event_keeps_its_start_but_its_end_can_be_extended(): void
    {
        $event = $this->makeEvent([
            'topic_tag_id' => $this->tag->id,
            'event_starts_at' => now()->subHour()->startOfMinute(),
            'event_ends_at' => now()->addHour()->startOfMinute(),
            'application_deadline' => now()->subDay()->startOfMinute(),
            'completion_deadline' => now()->addDays(3)->startOfMinute(),
        ]);

        $this->save($this->organiser, $event, ['event_starts_at' => now()->addDay()->format('Y-m-d\TH:i')])->assertSessionHasErrors('event_starts_at');
        $this->save($this->organiser, $event, ['application_deadline' => now()->addMinutes(5)->format('Y-m-d\TH:i')])->assertSessionHasErrors('application_deadline');

        $this->save($this->organiser, $event, [
            'event_ends_at' => now()->addHours(4)->startOfMinute()->format('Y-m-d\TH:i'),
            'completion_deadline' => now()->addDays(9)->startOfMinute()->format('Y-m-d\TH:i'),
        ])->assertSessionHasNoErrors();

        $this->assertTrue($event->refresh()->event_ends_at->gt(now()->addHours(3)));
    }

    public function test_an_event_whose_applications_closed_can_still_be_edited_before_it_starts(): void
    {
        $event = $this->upcoming(['application_deadline' => now()->subHour()->startOfMinute()]);

        $this->save($this->organiser, $event, ['description' => 'Room changed'])->assertSessionHasNoErrors();
        $this->assertSame('Room changed', $event->refresh()->description);

        // Only a changed date has to be in the future.
        $this->save($this->organiser, $event, ['application_deadline' => now()->subMinutes(30)->format('Y-m-d\TH:i')])
            ->assertSessionHasErrors('application_deadline');
    }

    public function test_the_dates_must_make_sense(): void
    {
        $event = $this->upcoming();
        $fmt = fn ($date) => $date->format('Y-m-d\TH:i');

        $this->save($this->organiser, $event, ['event_starts_at' => $fmt(now()->subDay())])->assertSessionHasErrors('event_starts_at');
        $this->save($this->organiser, $event, ['event_ends_at' => $fmt($event->event_starts_at->copy()->subHour())])->assertSessionHasErrors('event_ends_at');
        $this->save($this->organiser, $event, ['application_deadline' => $fmt($event->event_starts_at->copy()->addHour())])->assertSessionHasErrors('application_deadline');
        $this->save($this->organiser, $event, ['completion_deadline' => $fmt($event->event_ends_at->copy()->subHour())])->assertSessionHasErrors('completion_deadline');
    }

    public function test_the_surprise_setting_is_locked_and_the_reveal_time_follows_the_rules(): void
    {
        $event = $this->upcoming(['is_hidden' => true, 'reveal_at' => now()->addDays(2)->startOfHour()]);

        $this->save($this->organiser, $event, ['is_hidden' => false])->assertSessionHasErrors('is_hidden');
        $this->save($this->organiser, $event, ['reveal_at' => now()->addDays(9)->format('Y-m-d\TH:i')])->assertSessionHasErrors('reveal_at');
        $this->save($this->organiser, $event, ['reveal_at' => now()->addDays(3)->startOfHour()->format('Y-m-d\TH:i')])->assertSessionHasNoErrors();

        $this->assertTrue($event->refresh()->reveal_at->gt(now()->addDays(2)->addHours(12)));
    }

    public function test_a_held_event_can_be_edited_but_completed_and_cancelled_ones_cannot(): void
    {
        $held = $this->upcoming(['status' => 'on_hold']);
        $this->save($this->organiser, $held, ['title' => 'Held and fixed'])->assertSessionHasNoErrors();
        $this->assertSame('Held and fixed', $held->refresh()->title);

        foreach (['completed', 'cancelled'] as $status) {
            $event = $this->upcoming(['status' => $status]);

            $this->save($this->organiser, $event, ['title' => 'Nope'])->assertSessionHasErrors('event');
            $this->assertNotSame('Nope', $event->refresh()->title);
            $this->actingAs($this->organiser)->get("/events/{$event->id}/edit")
                ->assertInertia(fn (Assert $page) => $page->where('editable', false));
        }
    }

    public function test_only_people_who_run_the_event_can_edit_it(): void
    {
        $event = $this->upcoming();

        // Creating is not managing: a published event is out of reach for a creator.
        $creator = $this->staff([Capability::EventsCreate]);
        // (A refused visit goes back to the leaderboard with a message, as everywhere in the app.)
        $this->actingAs($creator)->get("/events/{$event->id}/edit")->assertRedirect('/')->assertSessionHas('error');
        $this->save($creator, $event, ['title' => 'Hijacked'])->assertRedirect('/')->assertSessionHas('error');

        // An organiser of another branch does not even learn that the event exists.
        $elsewhere = $this->staff([Capability::EventsCreate, Capability::EventsManage], $this->topic('Sports', $this->university));
        $this->actingAs($elsewhere)->get("/events/{$event->id}/edit")->assertNotFound();
        $this->save($elsewhere, $event, ['title' => 'Hijacked'])->assertNotFound();

        // A student cannot reach it, even with the apply permission at the audience.
        $student = $this->student();
        $this->assign($student, $this->studentRole(), $this->course);
        $this->actingAs($student)->get("/events/{$event->id}/edit")->assertNotFound();
        $this->save($student, $event, ['title' => 'Hijacked'])->assertNotFound();

        $this->assertNotSame('Hijacked', $event->refresh()->title);

        // A guest is sent to log in.
        auth()->logout();
        $this->get("/events/{$event->id}/edit")->assertRedirect('/login');
    }

    public function test_someone_who_only_creates_may_edit_drafts(): void
    {
        $creator = $this->staff([Capability::EventsCreate]);
        $draft = $this->upcoming(['status' => 'draft', 'published_at' => null]);

        $this->save($creator, $draft, ['title' => 'My draft'])->assertSessionHasNoErrors();
        $this->assertSame('My draft', $draft->refresh()->title);
    }

    public function test_a_draft_cannot_be_moved_to_a_topic_the_person_may_not_use(): void
    {
        $draft = $this->upcoming(['status' => 'draft', 'published_at' => null]);
        $foreign = $this->topic('Elsewhere', $this->university);

        $this->save($this->organiser, $draft, ['owning_topic_id' => $foreign->id, 'audience_topic_id' => $foreign->id])
            ->assertSessionHasErrors('owning_topic_id');
        $this->assertSame($this->course->id, $draft->refresh()->owning_topic_id);
    }

    public function test_the_audience_must_stay_at_or_above_the_owning_topic(): void
    {
        $event = $this->upcoming();
        $below = $this->topic('Practice group', $this->course);

        $this->save($this->organiser, $event, ['audience_topic_id' => $below->id])->assertSessionHasErrors('audience_topic_id');
        $this->save($this->organiser, $event, ['audience_topic_id' => null])->assertSessionHasNoErrors();
        $this->assertNull($event->refresh()->audience_topic_id);
    }

    public function test_an_archived_topic_freezes_editing(): void
    {
        $event = $this->upcoming();
        $this->course->forceFill(['archived_at' => now()])->save();

        $this->actingAs($this->organiser)->get("/events/{$event->id}/edit")->assertNotFound();
        $this->save($this->organiser, $event, ['title' => 'Frozen'])->assertNotFound();
    }

    public function test_a_stale_form_is_refused_instead_of_overwriting(): void
    {
        $event = $this->upcoming();
        $stale = $this->form($event);

        $this->travel(3)->seconds();
        $this->save($this->organiser, $event, ['title' => 'First change'])->assertSessionHasNoErrors();

        $this->actingAs($this->organiser)->put("/events/{$event->id}", [...$stale, 'title' => 'Second change'])
            ->assertSessionHasErrors('event');

        $this->assertSame('First change', $event->refresh()->title);
    }

    public function test_applicants_are_told_when_the_time_changes_and_only_then(): void
    {
        Notification::fake();
        $event = $this->upcoming();
        $waiting = $this->student();
        $this->accepted($waiting, $event, 'pending');
        $this->accepted($this->student(), $event, 'withdrawn');

        $this->save($this->organiser, $event, ['title' => 'Just the title'])->assertSessionHasNoErrors();
        Notification::assertNothingSent();

        $this->save($this->organiser, $event, [
            'event_starts_at' => now()->addDays(6)->startOfHour()->format('Y-m-d\TH:i'),
            'event_ends_at' => now()->addDays(6)->startOfHour()->addHours(2)->format('Y-m-d\TH:i'),
            'application_deadline' => now()->addDays(5)->startOfHour()->format('Y-m-d\TH:i'),
            'completion_deadline' => now()->addDays(14)->startOfHour()->format('Y-m-d\TH:i'),
        ])->assertSessionHasNoErrors();

        Notification::assertCount(1);
        Notification::assertSentTo($waiting, EventStatusChangedNotification::class,
            fn ($n) => $n->change === EventStatusChangedNotification::RESCHEDULED && $n->eventId === $event->id);
    }

    public function test_a_draft_change_of_time_tells_nobody(): void
    {
        Notification::fake();
        $draft = $this->upcoming(['status' => 'draft', 'published_at' => null]);

        $this->save($this->organiser, $draft, [
            'event_starts_at' => now()->addDays(6)->startOfHour()->format('Y-m-d\TH:i'),
            'event_ends_at' => now()->addDays(6)->startOfHour()->addHours(2)->format('Y-m-d\TH:i'),
            'application_deadline' => now()->addDays(5)->startOfHour()->format('Y-m-d\TH:i'),
            'completion_deadline' => now()->addDays(14)->startOfHour()->format('Y-m-d\TH:i'),
        ])->assertSessionHasNoErrors();

        Notification::assertNothingSent();
    }

    public function test_the_request_is_validated(): void
    {
        $event = $this->upcoming();

        foreach (['title' => '', 'description' => '', 'capacity' => 0, 'points_value' => 0, 'application_type' => 'lottery', 'event_starts_at' => 'tomorrow-ish'] as $field => $bad) {
            $this->save($this->organiser, $event, [$field => $bad])->assertSessionHasErrors($field);
        }

        $this->assertSame($event->title, $event->refresh()->title);
    }

    public function test_the_organized_list_offers_edit_where_it_is_allowed(): void
    {
        $this->upcoming();
        $this->upcoming(['status' => 'cancelled']);

        $this->actingAs($this->organiser)->get('/my-events?filter=upcoming')
            ->assertInertia(fn (Assert $page) => $page->where('events.0.can_edit', true));

        $this->actingAs($this->organiser)->get('/my-events?filter=cancelled')
            ->assertInertia(fn (Assert $page) => $page->where('events.0.can_edit', false));
    }

    public function test_saving_never_touches_applications_or_the_ledger(): void
    {
        $event = $this->upcoming();
        $application = $this->accepted($this->student(), $event);

        $this->save($this->organiser, $event, ['title' => 'Renamed', 'capacity' => 50])->assertSessionHasNoErrors();

        $this->assertSame(EventApplication::ACCEPTED, $application->refresh()->status);
        $this->assertSame(0, $event->pointTransactions()->count());
    }
}

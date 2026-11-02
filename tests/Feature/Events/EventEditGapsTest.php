<?php

namespace Tests\Feature\Events;

use App\Enums\Capability;
use App\Models\Event;
use App\Models\EventEdit;
use App\Models\Semester;
use App\Models\TopicTag;
use App\Models\User;
use App\Services\EventEditor;
use App\Services\PointLedger;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Task #76, the parts missing from the first Edit page:
 *  - a completed event can still have its points end date moved, so a student who missed
 *    the scan can be given more time (26 Oct, Q4: "the organiser edits the event");
 *  - every saved edit is logged: who, when, which fields from what to what.
 */
class EventEditGapsTest extends EventTestCase
{
    private TopicTag $tag;

    private User $organiser;

    protected function setUp(): void
    {
        parent::setUp();

        Semester::query()->exists() || Semester::create(['name' => 'Autumn', 'starts_at' => now()->subMonth()->toDateString(), 'ends_at' => now()->addMonths(3)->toDateString(), 'status' => 'active']);
        $this->tag = TopicTag::create(['name' => 'SQL', 'category' => 'academic', 'is_active' => true]);
        $this->organiser = $this->staff([Capability::EventsCreate, Capability::EventsManage, Capability::PointsCredit], name: 'Olga Organiser');
    }

    private function upcoming(array $data = []): Event
    {
        return $this->makeEvent([
            'title' => 'SQL Workshop',
            'topic_tag_id' => $this->tag->id,
            'event_starts_at' => now()->addDays(5)->startOfHour(),
            'event_ends_at' => now()->addDays(5)->startOfHour()->addHours(2),
            'application_deadline' => now()->addDays(4)->startOfHour(),
            'completion_deadline' => now()->addDays(12)->startOfHour(),
            ...$data,
        ]);
    }

    /** Held last week; its points end date passed yesterday. */
    private function completed(): Event
    {
        return $this->makeEvent([
            'title' => 'Last week',
            'status' => Event::STATUS_COMPLETED,
            'topic_tag_id' => $this->tag->id,
            'event_starts_at' => now()->subDays(7)->startOfHour(),
            'event_ends_at' => now()->subDays(7)->startOfHour()->addHours(2),
            'application_deadline' => now()->subDays(8)->startOfHour(),
            'completion_deadline' => now()->subDay()->startOfHour(),
        ]);
    }

    /**
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
            'qr_enabled' => $event->qr_enabled,
            ...$changes,
        ];
    }

    private function save(User $user, Event $event, array $changes = [])
    {
        return $this->actingAs($user)->put("/events/{$event->id}", $this->form($event, $changes));
    }

    public function test_a_completed_event_opens_with_everything_but_the_points_end_date_locked(): void
    {
        $event = $this->completed();

        $this->actingAs($this->organiser)->get("/events/{$event->id}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('editable', true)
                ->has('locks.title')
                ->has('locks.event_ends_at')
                ->has('locks.points_value')
                ->missing('locks.completion_deadline'));
    }

    public function test_the_points_end_date_of_a_completed_event_can_be_extended(): void
    {
        $event = $this->completed();
        $later = now()->addDays(3)->startOfMinute();

        $this->save($this->organiser, $event, ['completion_deadline' => $later->format('Y-m-d\TH:i')])
            ->assertSessionHasNoErrors();

        $event->refresh();
        $this->assertTrue($event->completion_deadline->equalTo($later));
        $this->assertSame(Event::STATUS_COMPLETED, $event->status);
    }

    public function test_after_the_extension_the_student_who_missed_the_scan_can_get_the_points(): void
    {
        $event = $this->completed();
        $student = $this->student();
        $this->accepted($student, $event);
        $ledger = app(PointLedger::class);

        $this->assertSame('deadline_passed', $ledger->refusalFor($event)?->reason);

        $this->save($this->organiser, $event, ['completion_deadline' => now()->addDay()->format('Y-m-d\TH:i')])
            ->assertSessionHasNoErrors();

        $this->assertNull($ledger->refusalFor($event->refresh()));
        $ledger->credit($event, $student, $this->organiser, PointLedger::PASSPORT);
        $this->assertSame(1, $event->pointTransactions()->count());
    }

    public function test_nothing_else_of_a_completed_event_can_change(): void
    {
        $event = $this->completed();

        $this->save($this->organiser, $event, [
            'title' => 'Renamed',
            'points_value' => 99,
            'event_ends_at' => now()->format('Y-m-d\TH:i'),
            'completion_deadline' => now()->addDays(3)->format('Y-m-d\TH:i'),
        ])->assertSessionHasErrors(['title', 'points_value', 'event_ends_at']);

        $event->refresh();
        $this->assertSame('Last week', $event->title);
        $this->assertTrue($event->completion_deadline->isPast());
        $this->assertSame(0, EventEdit::count());
    }

    public function test_the_points_end_date_of_a_completed_event_cannot_go_before_its_end(): void
    {
        $event = $this->completed();

        $this->save($this->organiser, $event, [
            'completion_deadline' => $event->event_ends_at->copy()->subHour()->format('Y-m-d\TH:i'),
        ])->assertSessionHasErrors('completion_deadline');
    }

    public function test_only_people_who_manage_the_event_can_extend_a_completed_one(): void
    {
        $event = $this->completed();
        $creator = $this->staff([Capability::EventsCreate], name: 'Only Creates');

        $this->save($creator, $event, ['completion_deadline' => now()->addDay()->format('Y-m-d\TH:i')])
            ->assertRedirect('/')->assertSessionHas('error');
        $this->save($this->student(), $event, ['completion_deadline' => now()->addDay()->format('Y-m-d\TH:i')])->assertNotFound();

        $this->assertTrue($event->refresh()->completion_deadline->isPast());
    }

    public function test_a_completed_event_shows_edit_on_the_past_tab(): void
    {
        $this->completed();

        $this->actingAs($this->organiser)->get('/my-events?filter=past')
            ->assertInertia(fn (Assert $page) => $page
                ->has('events', 1)
                ->where('events.0.title', 'Last week')
                ->where('events.0.status', Event::STATUS_COMPLETED)
                ->where('events.0.can_edit', true));
    }

    /** Over but never marked completed: a real event after it ends (the Past tab's rule). */
    private function ended(): Event
    {
        return $this->makeEvent([
            'title' => 'Ended, still published',
            'status' => Event::STATUS_PUBLISHED,
            'topic_tag_id' => $this->tag->id,
            'event_starts_at' => now()->subDays(3)->startOfHour(),
            'event_ends_at' => now()->subDays(3)->startOfHour()->addHours(2),
            'application_deadline' => now()->subDays(4)->startOfHour(),
            'completion_deadline' => now()->subDay()->startOfHour(),
        ]);
    }

    public function test_a_published_event_that_has_ended_counts_as_over(): void
    {
        $event = $this->ended();

        $this->actingAs($this->organiser)->get("/events/{$event->id}/edit")
            ->assertInertia(fn (Assert $page) => $page
                ->where('editable', true)
                ->where('over', true)
                ->has('locks.title')
                ->has('locks.location')
                ->has('locks.capacity')
                ->has('locks.event_ends_at')
                ->has('locks.application_deadline')
                ->has('locks.qr_enabled')
                ->missing('locks.completion_deadline'));

        $this->save($this->organiser, $event, ['title' => 'Renamed', 'capacity' => 99])
            ->assertSessionHasErrors(['title', 'capacity']);
        $this->assertSame('Ended, still published', $event->refresh()->title);

        $this->save($this->organiser, $event, ['completion_deadline' => now()->addDays(2)->format('Y-m-d\TH:i')])
            ->assertSessionHasNoErrors();
        $this->assertTrue($event->refresh()->completion_deadline->isFuture());
        $this->assertSame(Event::STATUS_PUBLISHED, $event->status);

        $this->actingAs($this->organiser)->get('/my-events?filter=past')
            ->assertInertia(fn (Assert $page) => $page
                ->where('events.0.title', 'Ended, still published')
                ->where('events.0.can_edit', true));
    }

    public function test_an_event_that_has_not_ended_is_not_over(): void
    {
        $event = $this->upcoming();

        $this->actingAs($this->organiser)->get("/events/{$event->id}/edit")
            ->assertInertia(fn (Assert $page) => $page->where('over', false)->missing('locks.title'));
    }

    public function test_an_edit_is_logged_with_who_when_and_what(): void
    {
        $event = $this->upcoming();

        $this->save($this->organiser, $event, ['title' => 'SQL Workshop II', 'capacity' => 40])
            ->assertSessionHasNoErrors();

        $edit = EventEdit::sole();
        $this->assertSame($event->id, $edit->event_id);
        $this->assertTrue($edit->actor->is($this->organiser));
        $this->assertNotNull($edit->created_at);
        $this->assertSame(['old' => 'SQL Workshop', 'new' => 'SQL Workshop II'], $edit->changes['title']);
        $this->assertSame(['old' => 30, 'new' => 40], $edit->changes['capacity']);
        $this->assertEqualsCanonicalizing(['title', 'capacity'], array_keys($edit->changes));
    }

    public function test_a_changed_date_is_logged_by_the_minute(): void
    {
        $event = $this->upcoming();
        $old = $event->completion_deadline->format('Y-m-d H:i');
        $new = now()->addDays(20)->startOfHour();

        $this->save($this->organiser, $event, ['completion_deadline' => $new->format('Y-m-d\TH:i')]);

        $this->assertSame(['old' => $old, 'new' => $new->format('Y-m-d H:i')], EventEdit::sole()->changes['completion_deadline']);
    }

    public function test_a_save_that_changes_nothing_is_not_logged(): void
    {
        // Seconds stored by another route are not a change: the form only holds minutes.
        $event = $this->upcoming(['event_ends_at' => now()->addDays(5)->startOfHour()->addHours(2)->addSeconds(30)]);

        $this->save($this->organiser, $event)->assertSessionHasNoErrors();

        $this->assertSame(0, EventEdit::count());
    }

    public function test_a_refused_save_is_not_logged(): void
    {
        $event = $this->upcoming();

        $this->save($this->organiser, $event, ['title' => 'New', 'event_ends_at' => $event->event_starts_at->copy()->subHour()->format('Y-m-d\TH:i')])
            ->assertSessionHasErrors('event_ends_at');

        $this->assertSame(0, EventEdit::count());
    }

    public function test_the_edit_page_lists_the_history_newest_first(): void
    {
        $event = $this->upcoming();

        $this->save($this->organiser, $event, ['title' => 'First change']);
        $this->travel(5)->minutes();
        $this->save($this->organiser, $event, ['capacity' => 25, 'completion_deadline' => now()->addDays(15)->startOfHour()->format('Y-m-d\TH:i')]);

        $this->actingAs($this->organiser)->get("/events/{$event->id}/edit")
            ->assertInertia(fn (Assert $page) => $page
                ->has('history', 2)
                ->where('history.0.by', 'Olga Organiser')
                ->where('history.0.changes.0', ['field' => 'capacity', 'old' => '30', 'new' => '25'])
                ->where('history.0.changes.1.field', 'completion_deadline')
                ->where('history.1.changes', [['field' => 'title', 'old' => 'SQL Workshop', 'new' => 'First change']])
                ->where('historyTotal', 2));
    }

    public function test_an_event_with_no_edits_has_an_empty_history(): void
    {
        $event = $this->upcoming();

        $this->actingAs($this->organiser)->get("/events/{$event->id}/edit")
            ->assertInertia(fn (Assert $page) => $page->has('history', 0));
    }

    public function test_the_history_shows_names_and_words_not_ids(): void
    {
        $event = $this->upcoming(['qr_enabled' => false]);
        $other = TopicTag::create(['name' => 'Chess', 'category' => 'sports', 'is_active' => true]);

        $this->save($this->organiser, $event, ['topic_tag_id' => $other->id, 'qr_enabled' => true, 'application_type' => 'teacher_select']);

        $this->actingAs($this->organiser)->get("/events/{$event->id}/edit")
            ->assertInertia(fn (Assert $page) => $page
                ->where('history.0.changes', fn ($changes) => collect($changes)->keyBy('field')->only(['topic_tag_id', 'qr_enabled', 'application_type'])->map(fn ($c) => [$c['old'], $c['new']])->all() === [
                    'topic_tag_id' => ['SQL', 'Chess'],
                    'application_type' => ['First come, first served', 'The organiser chooses'],
                    'qr_enabled' => ['No', 'Yes'],
                ])
                ->where('history.0.changes', fn ($changes) => collect($changes)->doesntContain('field', 'category')));
    }

    public function test_the_page_says_when_only_the_last_20_edits_are_shown(): void
    {
        $event = $this->upcoming();

        foreach (range(1, 21) as $i) {
            $this->save($this->organiser, $event, ['title' => "Title {$i}"])->assertSessionHasNoErrors();
        }

        $this->actingAs($this->organiser)->get("/events/{$event->id}/edit")
            ->assertInertia(fn (Assert $page) => $page
                ->has('history', 20)
                ->where('historyTotal', 21)
                ->where('historyLimit', 20)
                ->where('history.0.changes.0.new', 'Title 21'));
    }
}

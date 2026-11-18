<?php

namespace Tests\Feature\Events;

use App\Enums\Capability;
use App\Models\Event;
use App\Models\EventApplication;
use App\Services\PointLedger;
use Illuminate\Console\Scheduling\Schedule;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Task #101: events become Completed when they end, without a manual step (27 Oct, G16).
 * Completed changes the label and nothing else, so everything that worked on an ended
 * published event still works on a completed one.
 */
class EventCompletionTest extends EventTestCase
{
    /**
     * A published event that ended two days ago and gives points for five more days.
     *
     * @param  array<string, mixed>  $data
     */
    private function ended(array $data = []): Event
    {
        return $this->makeEvent([
            'event_starts_at' => now()->subDays(2)->subHours(2),
            'event_ends_at' => now()->subDays(2),
            'application_deadline' => now()->subDays(3),
            'completion_deadline' => now()->addDays(5),
            ...$data,
        ]);
    }

    public function test_published_events_that_have_ended_become_completed(): void
    {
        $ended = $this->ended();
        $running = $this->event; // started an hour ago, ends in an hour
        $later = $this->makeEvent(['event_starts_at' => now()->addDay(), 'event_ends_at' => now()->addDay()->addHours(2), 'application_deadline' => now()->addHours(12)]);

        $this->artisan('events:complete')
            ->expectsOutput('1 ended events marked completed.')
            ->assertSuccessful();

        $this->assertSame(Event::STATUS_COMPLETED, $ended->fresh()->status);
        $this->assertSame(Event::STATUS_PUBLISHED, $running->fresh()->status);
        $this->assertSame(Event::STATUS_PUBLISHED, $later->fresh()->status);
    }

    public function test_the_running_event_is_completed_once_it_ends(): void
    {
        $this->travel(61)->minutes();

        $this->artisan('events:complete')->assertSuccessful();

        $this->assertSame(Event::STATUS_COMPLETED, $this->event->fresh()->status);
    }

    public function test_drafts_events_on_hold_and_cancelled_events_keep_their_status(): void
    {
        $draft = $this->ended(['status' => Event::STATUS_DRAFT, 'published_at' => null]);
        $onHold = $this->ended(['status' => Event::STATUS_ON_HOLD]);
        $cancelled = $this->ended(['status' => Event::STATUS_CANCELLED, 'cancelled_at' => now()->subDays(3), 'cancellation_reason' => 'Room flooded']);

        $this->artisan('events:complete')
            ->expectsOutput('0 ended events marked completed.')
            ->assertSuccessful();

        $this->assertSame(Event::STATUS_DRAFT, $draft->fresh()->status);
        $this->assertSame(Event::STATUS_ON_HOLD, $onHold->fresh()->status);
        $this->assertSame(Event::STATUS_CANCELLED, $cancelled->fresh()->status);
    }

    public function test_running_it_again_changes_nothing(): void
    {
        $ended = $this->ended();
        $this->artisan('events:complete');
        $stamp = $ended->fresh()->updated_at;

        $this->travel(5)->minutes();
        $this->artisan('events:complete')
            ->expectsOutput('0 ended events marked completed.')
            ->assertSuccessful();

        $this->assertEquals($stamp, $ended->fresh()->updated_at);
    }

    public function test_the_scheduler_runs_it_every_minute(): void
    {
        $runs = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains((string) $event->command, 'events:complete'));

        $this->assertCount(1, $runs);
        $this->assertSame('* * * * *', $runs->first()->expression);
    }

    public function test_a_completed_event_takes_points_until_its_points_end_date(): void
    {
        $ended = $this->ended();
        $this->artisan('events:complete');
        $ledger = app(PointLedger::class);

        $this->assertNull($ledger->refusalFor($ended->fresh()));

        $this->travel(6)->days();
        $this->assertSame('deadline_passed', $ledger->refusalFor($ended->fresh())?->reason);
    }

    public function test_students_still_find_a_completed_event_under_past(): void
    {
        $student = $this->student();
        $this->assign($student, $this->studentRole(), $this->course);
        $ended = $this->ended(['title' => 'Last week']);
        $this->artisan('events:complete');

        $this->actingAs($student)->get('/events?when=past')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Events/Index')
                ->has('events.data', 1)
                ->where('events.data.0.title', 'Last week'));

        $this->assertSame(Event::STATUS_COMPLETED, $ended->fresh()->status);
    }

    public function test_the_organiser_sees_it_under_past_with_the_same_actions(): void
    {
        $teacher = $this->staff([Capability::EventsCreate, Capability::EventsManage, Capability::ApplicationsManage, Capability::PointsCredit]);
        $this->event->delete();
        $this->ended(['created_by_id' => $teacher->id]);

        $before = null;
        $this->actingAs($teacher)->get('/my-events?filter=past')
            ->assertInertia(function (Assert $page) use (&$before) {
                $before = $page->toArray()['props']['events'][0];
            });

        $this->artisan('events:complete');

        $this->actingAs($teacher)->get('/my-events?filter=past')
            ->assertInertia(fn (Assert $page) => $page
                ->where('counts.past', 1)
                ->where('counts.upcoming', 0)
                ->where('events.0.status', Event::STATUS_COMPLETED)
                ->where('events.0.can_review', $before['can_review'])
                ->where('events.0.can_credit', $before['can_credit'])
                ->where('events.0.can_edit', $before['can_edit']));
    }

    public function test_applicants_of_a_completed_event_can_still_be_reviewed(): void
    {
        $teacher = $this->staff([Capability::EventsCreate, Capability::EventsManage, Capability::ApplicationsManage]);
        $event = $this->ended(['application_type' => 'teacher_select']);
        $pending = $this->accepted($this->student(), $event, EventApplication::PENDING);
        $this->artisan('events:complete');

        $this->actingAs($teacher)
            ->post(route('events.applicants.select', $event), ['selected' => [$pending->id]])
            ->assertSessionHasNoErrors();

        $this->assertSame(EventApplication::ACCEPTED, $pending->fresh()->status);
    }
}

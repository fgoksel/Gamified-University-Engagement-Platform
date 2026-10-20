<?php

namespace Tests\Feature\Events;

use App\Enums\Capability;
use App\Models\Event;
use App\Models\EventApplication;
use App\Models\Topic;
use App\Models\TopicAuditEvent;
use App\Models\User;
use App\Services\PointLedger;
use App\Services\TopicService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Task #66: what archiving, restoring, moving and deleting topics do to events.
 */
class TopicLifecycleEventsTest extends EventTestCase
{
    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        // An open event run by the Databases course, announced to the whole faculty.
        $this->event->update([
            'audience_topic_id' => $this->faculty->id,
            'event_starts_at' => now()->addDays(5),
            'event_ends_at' => now()->addDays(5)->addHours(2),
            'application_deadline' => now()->addDays(4),
            'completion_deadline' => now()->addDays(12),
        ]);

        $this->student = $this->student('Sam', 'SAM001');
        $this->assign($this->student, $this->studentRole(), $this->faculty);
    }

    /**
     * @return list<string>
     */
    private function catalogTitles(User $user): array
    {
        $titles = [];
        $this->actingAs($user)->get('/events')->assertInertia(function (Assert $page) use (&$titles) {
            $titles = collect($page->toArray()['props']['events']['data'])->pluck('title')->all();
        });

        return $titles;
    }

    // ---- Archive and restore ---------------------------------------------

    public function test_archiving_the_owning_topic_freezes_the_event_even_when_its_audience_is_still_open(): void
    {
        $this->assertSame([$this->event->title], $this->catalogTitles($this->student));

        app(TopicService::class)->archive($this->admin, $this->course);

        // Not listed, no detail page, no applying.
        $this->assertSame([], $this->catalogTitles($this->student));
        $this->actingAs($this->student)->get("/events/{$this->event->id}")->assertNotFound();
        $this->assertFalse(Gate::forUser($this->student)->allows('apply', $this->event->fresh()));
        $this->actingAs($this->student)->post("/events/{$this->event->id}/apply")
            ->assertSessionHasErrors(['event' => 'Applications are frozen because this topic is archived.']);
    }

    public function test_archiving_freezes_management_and_points(): void
    {
        $organiser = $this->staff([Capability::EventsManage, Capability::PointsCredit, Capability::QrCodesManage]);
        $this->accepted($this->student);
        $this->event->update(['event_starts_at' => now()->subHour(), 'application_deadline' => now()->subDay()]);

        app(TopicService::class)->archive($this->admin, $this->faculty);
        $event = $this->event->fresh();

        $this->assertFalse(Gate::forUser($organiser)->allows('manage', $event));
        $this->assertFalse(Gate::forUser($organiser)->allows('credit', $event));
        $this->assertFalse(Gate::forUser($organiser)->allows('manageQr', $event));
        $this->assertNotNull(app(PointLedger::class)->refusalFor($event));
    }

    public function test_restoring_brings_the_event_back_exactly_as_it_was(): void
    {
        $this->accepted($this->student, status: EventApplication::WAITLISTED);
        $snapshot = fn () => $this->event->fresh()->only(['status', 'capacity', 'audience_topic_id', 'owning_topic_id', 'is_hidden'])
            + ['published_at' => $this->event->fresh()->published_at?->toIso8601String()];
        $before = $snapshot();

        app(TopicService::class)->archive($this->admin, $this->course);
        app(TopicService::class)->restore($this->admin, $this->course->fresh());

        $this->assertSame($before, $snapshot());
        $this->assertSame(EventApplication::WAITLISTED, EventApplication::where('student_id', $this->student->id)->value('status'));
        $this->assertSame([$this->event->title], $this->catalogTitles($this->student));
    }

    public function test_the_archive_message_and_history_say_how_many_events_were_frozen(): void
    {
        $this->makeEvent(['title' => 'Second', 'status' => 'draft', 'published_at' => null]);
        $this->makeEvent(['title' => 'Old', 'status' => 'cancelled']);

        $this->actingAs($this->admin)->post("/topics/{$this->course->id}/archive")
            ->assertSessionHas('success', fn ($message) => str_ends_with($message, '2 events in it are frozen until you restore it.'));

        $audit = TopicAuditEvent::where('action', 'topic.archived')->sole();
        $this->assertSame(2, $audit->after['events_frozen']);
    }

    // ---- Move --------------------------------------------------------------

    public function test_a_move_that_would_leave_an_event_announced_to_a_topic_no_longer_above_it_is_refused(): void
    {
        $otherFaculty = $this->topic('Other faculty', $this->university);

        $preview = app(TopicService::class)->movePreview($this->admin, $this->course, $otherFaculty);
        $this->assertCount(1, $preview['blocked']);
        $this->assertStringContainsString($this->event->title, $preview['blocked'][0]);
        $this->assertStringContainsString('open to Faculty', $preview['blocked'][0]);

        try {
            app(TopicService::class)->move($this->admin, $this->course, $otherFaculty);
            $this->fail('The move should have been refused.');
        } catch (ValidationException) {
            $this->assertSame($this->faculty->id, $this->course->fresh()->parent_id);
        }
    }

    public function test_a_move_is_allowed_when_every_audience_stays_above(): void
    {
        $otherFaculty = $this->topic('Other faculty', $this->university);
        // Announced to the whole university, which stays above the course after the move.
        $this->event->update(['audience_topic_id' => $this->university->id]);
        // Announced to the course itself, which moves along.
        $this->makeEvent(['title' => 'Course only']);
        // Cancelled events do not block.
        $this->makeEvent(['title' => 'Old', 'status' => 'cancelled', 'audience_topic_id' => $this->faculty->id]);

        $this->assertSame([], app(TopicService::class)->movePreview($this->admin, $this->course, $otherFaculty)['blocked']);

        app(TopicService::class)->move($this->admin, $this->course, $otherFaculty);
        $this->assertSame($otherFaculty->id, $this->course->fresh()->parent_id);
    }

    // ---- Delete -------------------------------------------------------------

    public function test_a_topic_used_by_events_cannot_be_deleted_and_the_reason_says_why(): void
    {
        $empty = $this->topic('Empty lab', $this->course);
        $this->assertSame([], app(TopicService::class)->deleteBlockers($empty));

        Event::factory()->create(['owning_topic_id' => $empty->id, 'audience_topic_id' => $empty->id, 'status' => 'cancelled']);

        $blockers = app(TopicService::class)->deleteBlockers($empty->fresh());
        $this->assertContains('1 event(s) are run from or announced to it (cancelled and past ones count too).', $blockers);

        $this->expectException(ValidationException::class);
        app(TopicService::class)->prepareDelete($this->admin, $empty);
    }

    public function test_an_unused_topic_can_still_be_deleted(): void
    {
        $empty = $this->topic('Empty lab', $this->course);

        $prepared = app(TopicService::class)->prepareDelete($this->admin, $empty);
        app(TopicService::class)->executeDelete($this->admin, $prepared['token'], 'Empty lab');

        $this->assertNull(Topic::find($empty->id));
    }
}

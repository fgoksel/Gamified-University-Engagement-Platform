<?php

namespace Tests\Feature\Events;

use App\Enums\Capability;
use App\Enums\UserRole;
use App\Models\Event;
use App\Models\EventApplication;
use App\Models\RoleDefinition;
use App\Models\Semester;
use App\Models\Topic;
use App\Models\User;
use App\Notifications\EventApplicationDecisionNotification;
use App\Services\ApplicantReviewService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Topics\TopicTestCase;

/**
 * Task #94: one-tap Apply with Undo, and the student's place on the waitlist.
 * Undo works for a minute after applying, like a withdrawal (a freed place goes to
 * the waitlist), but the student screens then treat the application as never sent.
 */
class ApplyUndoTest extends TopicTestCase
{
    private Topic $faculty;

    private Topic $course;

    private RoleDefinition $studentRole;

    protected function setUp(): void
    {
        parent::setUp();

        $university = $this->topic('University');
        $this->faculty = $this->topic('Faculty of Informatics', $university);
        $this->course = $this->topic('Databases', $this->faculty);

        Semester::create([
            'name' => '2026/27 autumn',
            'starts_at' => now()->subMonth()->toDateString(),
            'ends_at' => now()->addMonths(3)->toDateString(),
            'status' => 'active',
        ]);

        $this->studentRole = $this->role('Student', [Capability::View, Capability::EventsView, Capability::EventsApply]);
    }

    private function student(string $neptun): User
    {
        $student = $this->makeUser(UserRole::Student);
        $student->forceFill(['neptun_code' => $neptun])->save();
        $this->assign($student, $this->studentRole, $this->faculty);

        return $student;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function event(array $attributes = []): Event
    {
        return Event::factory()->create([
            'owning_topic_id' => $this->course->id,
            'audience_topic_id' => $this->faculty->id,
            'application_type' => 'fcfs',
            'event_starts_at' => now()->addDays(5),
            'event_ends_at' => now()->addDays(5)->addHours(2),
            'application_deadline' => now()->addDays(4),
            'completion_deadline' => now()->addDays(12),
            ...$attributes,
        ]);
    }

    public function test_undo_within_a_minute_frees_the_place_and_looks_like_never_applied(): void
    {
        $event = $this->event(['capacity' => 5]);
        $student = $this->student('UND001');

        $this->actingAs($student)->post("/events/{$event->id}/apply")->assertSessionHasNoErrors();
        $this->travel(30)->seconds();

        $this->actingAs($student)->from("/events/{$event->id}")
            ->post("/events/{$event->id}/apply/undo")
            ->assertRedirect("/events/{$event->id}")
            ->assertSessionHas('success', 'Application undone');

        $application = EventApplication::where('student_id', $student->id)->firstOrFail();
        $this->assertSame(EventApplication::WITHDRAWN, $application->status);
        $this->assertNotNull($application->undone_at);
        $this->assertNull($application->withdrawn_at);
        $this->assertDatabaseHas('event_application_transitions', [
            'event_application_id' => $application->id,
            'from_status' => EventApplication::ACCEPTED,
            'to_status' => EventApplication::WITHDRAWN,
            'reason' => 'Undone by the student',
        ]);

        // The card shows "Apply" again, not "Apply again": no status at all.
        $this->actingAs($student)->get('/events')
            ->assertInertia(fn (Assert $page) => $page
                ->where('events.data.0.my_status', null)
                ->where('events.data.0.places_left', 5)
                ->where('events.data.0.can_apply', true));

        // Not part of the history on My events.
        $this->actingAs($student)->get('/my-events')
            ->assertInertia(fn (Assert $page) => $page->has('applications', 0));
    }

    public function test_undo_after_a_minute_is_refused(): void
    {
        $event = $this->event();
        $student = $this->student('UND002');
        $this->actingAs($student)->post("/events/{$event->id}/apply");

        $this->travel(61)->seconds();

        $this->actingAs($student)->post("/events/{$event->id}/apply/undo")
            ->assertSessionHasErrors(['event' => "It's too late to undo. You can leave the event from My events."]);
        $this->assertSame(EventApplication::ACCEPTED, EventApplication::where('student_id', $student->id)->value('status'));
    }

    public function test_undo_is_refused_once_points_were_credited(): void
    {
        $event = $this->event();
        $student = $this->student('UND003');
        $this->actingAs($student)->post("/events/{$event->id}/apply");
        EventApplication::where('student_id', $student->id)->update(['scored_at' => now()]);

        $this->actingAs($student)->post("/events/{$event->id}/apply/undo")
            ->assertSessionHasErrors(['event' => "It's too late to undo. You can leave the event from My events."]);
        $this->assertSame(EventApplication::ACCEPTED, EventApplication::where('student_id', $student->id)->value('status'));
    }

    public function test_a_student_cannot_undo_someone_elses_application(): void
    {
        $event = $this->event();
        $owner = $this->student('UND004');
        $other = $this->student('UND005');
        $this->actingAs($owner)->post("/events/{$event->id}/apply");

        $this->actingAs($other)->post("/events/{$event->id}/apply/undo")
            ->assertSessionHasErrors(['event' => 'You have no application to undo.']);
        $this->assertSame(EventApplication::ACCEPTED, EventApplication::where('student_id', $owner->id)->value('status'));
    }

    public function test_a_waitlisted_student_is_promoted_when_an_accepted_student_undoes(): void
    {
        Notification::fake();

        $event = $this->event(['capacity' => 1]);
        $first = $this->student('UND006');
        $second = $this->student('UND007');
        $this->actingAs($first)->post("/events/{$event->id}/apply");
        $this->travel(1)->seconds();
        $this->actingAs($second)->post("/events/{$event->id}/apply");
        $this->assertSame(EventApplication::WAITLISTED, EventApplication::where('student_id', $second->id)->value('status'));

        $this->actingAs($first)->post("/events/{$event->id}/apply/undo")->assertSessionHasNoErrors();

        $this->assertSame(EventApplication::ACCEPTED, EventApplication::where('student_id', $second->id)->value('status'));
        Notification::assertSentTo($second, EventApplicationDecisionNotification::class,
            fn ($n) => $n->decision === EventApplicationDecisionNotification::PROMOTED);
    }

    public function test_the_student_can_apply_again_after_undo(): void
    {
        $event = $this->event();
        $student = $this->student('UND008');
        $this->actingAs($student)->post("/events/{$event->id}/apply");
        $this->actingAs($student)->post("/events/{$event->id}/apply/undo")->assertSessionHasNoErrors();

        $this->actingAs($student)->post("/events/{$event->id}/apply")
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', "You're in!");

        $application = EventApplication::where('student_id', $student->id)->firstOrFail();
        $this->assertSame(EventApplication::ACCEPTED, $application->status);
        $this->assertNull($application->undone_at);
        $this->assertDatabaseHas('event_application_transitions', [
            'event_application_id' => $application->id,
            'reason' => 'Applied again after undo',
        ]);
    }

    public function test_the_event_props_say_until_when_undo_is_possible(): void
    {
        $event = $this->event();
        $student = $this->student('UND009');

        $this->actingAs($student)->get("/events/{$event->id}")
            ->assertInertia(fn (Assert $page) => $page->where('event.undo_until', null));

        $this->freezeSecond();
        $this->actingAs($student)->post("/events/{$event->id}/apply");
        $expected = now()->addSeconds(EventApplication::UNDO_SECONDS)->toIso8601String();

        // Also after a reload inside the minute: the card and the page both say so.
        $this->travel(20)->seconds();
        $this->actingAs($student)->get("/events/{$event->id}")
            ->assertInertia(fn (Assert $page) => $page->where('event.undo_until', $expected));
        $this->actingAs($student)->get('/events')
            ->assertInertia(fn (Assert $page) => $page->where('events.data.0.undo_until', $expected));

        $this->travel(41)->seconds();
        $this->actingAs($student)->get("/events/{$event->id}")
            ->assertInertia(fn (Assert $page) => $page->where('event.undo_until', null));
    }

    public function test_a_student_can_leave_until_the_event_starts_and_then_apply_again(): void
    {
        $event = $this->event();
        $student = $this->student('LEV001');
        $this->actingAs($student)->post("/events/{$event->id}/apply");
        $this->travel(2)->minutes();

        // Leave event shows for an active application before the start.
        $this->actingAs($student)->get("/events/{$event->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('event.my_status', 'accepted')
                ->where('event.has_started', false));

        $this->actingAs($student)->post("/events/{$event->id}/withdraw")
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', "You've left the event.");

        // "Apply again": the status is withdrawn and the student may apply.
        $this->actingAs($student)->get("/events/{$event->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('event.my_status', 'withdrawn')
                ->where('event.can_apply', true));

        $this->actingAs($student)->post("/events/{$event->id}/apply")
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', "You're in!");
    }

    public function test_leaving_is_refused_once_the_event_has_started(): void
    {
        $event = $this->event([
            'event_starts_at' => now()->addHour(),
            'event_ends_at' => now()->addHours(3),
            'application_deadline' => now()->addMinutes(30),
        ]);
        $student = $this->student('LEV002');
        $this->actingAs($student)->post("/events/{$event->id}/apply");

        $this->travel(2)->hours();

        $this->actingAs($student)->get("/events/{$event->id}")
            ->assertInertia(fn (Assert $page) => $page->where('event.has_started', true));
        $this->actingAs($student)->post("/events/{$event->id}/withdraw")
            ->assertSessionHasErrors(['event' => 'You cannot withdraw from an event that has already started.']);
        $this->assertSame(EventApplication::ACCEPTED, EventApplication::where('student_id', $student->id)->value('status'));
    }

    public function test_the_place_message_waits_until_the_undo_minute_is_over(): void
    {
        Notification::fake();

        $event = $this->event();
        $student = $this->student('NTF001');
        $this->actingAs($student)->post("/events/{$event->id}/apply");

        $application = EventApplication::where('student_id', $student->id)->firstOrFail();
        Notification::assertSentTo($student, EventApplicationDecisionNotification::class,
            fn ($n) => $n->decision === EventApplicationDecisionNotification::CONFIRMED
                && $n->applicationId === $application->id
                && $n->delay !== null
                && now()->addSeconds(EventApplication::UNDO_SECONDS)->diffInSeconds($n->delay, true) < 2);
    }

    public function test_no_place_message_is_sent_after_an_undo(): void
    {
        $event = $this->event();
        $student = $this->student('NTF002');
        $this->actingAs($student)->post("/events/{$event->id}/apply");
        $application = EventApplication::where('student_id', $student->id)->firstOrFail();

        $message = new EventApplicationDecisionNotification(
            EventApplicationDecisionNotification::CONFIRMED, $event->id, $event->title, $application->id
        );
        $this->assertTrue($message->shouldSend($student, 'mail'));

        $this->actingAs($student)->post("/events/{$event->id}/apply/undo")->assertSessionHasNoErrors();

        $this->assertFalse($message->shouldSend($student, 'mail'));
        $this->assertFalse($message->shouldSend($student, 'database'));
    }

    public function test_applying_again_after_undo_sends_one_place_message_not_two(): void
    {
        $event = $this->event();
        $student = $this->student('NTF003');

        $this->actingAs($student)->post("/events/{$event->id}/apply");
        $first = EventApplication::where('student_id', $student->id)->firstOrFail();
        $firstMessage = new EventApplicationDecisionNotification(
            EventApplicationDecisionNotification::CONFIRMED, $event->id, $event->title, $first->id, $first->applied_at->toIso8601String()
        );

        $this->actingAs($student)->post("/events/{$event->id}/apply/undo");
        $this->travel(5)->seconds();
        $this->actingAs($student)->post("/events/{$event->id}/apply");
        $again = $first->fresh();
        $secondMessage = new EventApplicationDecisionNotification(
            EventApplicationDecisionNotification::CONFIRMED, $event->id, $event->title, $again->id, $again->applied_at->toIso8601String()
        );

        // Same application row and status, but only the newest message goes out.
        $this->assertFalse($firstMessage->shouldSend($student, 'mail'));
        $this->assertTrue($secondMessage->shouldSend($student, 'mail'));
    }

    public function test_the_organiser_does_not_see_undone_applications(): void
    {
        $event = $this->event();
        $kept = $this->student('ORG001');
        $undone = $this->student('ORG002');
        $this->actingAs($kept)->post("/events/{$event->id}/apply");
        $this->actingAs($undone)->post("/events/{$event->id}/apply");
        $this->actingAs($undone)->post("/events/{$event->id}/apply/undo")->assertSessionHasNoErrors();

        $listed = app(ApplicantReviewService::class)->applicants($event)->pluck('student.id')->all();

        $this->assertSame([$kept->id], $listed);
    }

    public function test_waitlisted_students_see_their_place_in_the_queue_on_every_page(): void
    {
        $event = $this->event(['capacity' => 1]);
        $this->actingAs($this->student('WL0000'))->post("/events/{$event->id}/apply");

        $waiting = [];
        foreach (['WL0001', 'WL0002', 'WL0003'] as $neptun) {
            $this->travel(1)->seconds();
            $waiting[] = $student = $this->student($neptun);
            $this->actingAs($student)->post("/events/{$event->id}/apply");
        }
        $third = $waiting[2];

        $this->actingAs($third)->get('/events')
            ->assertInertia(fn (Assert $page) => $page
                ->where('events.data.0.my_status', 'waitlisted')
                ->where('events.data.0.waitlist_position', 3));
        $this->actingAs($third)->get("/events/{$event->id}")
            ->assertInertia(fn (Assert $page) => $page->where('event.waitlist_position', 3));
        $this->actingAs($third)->get('/my-events')
            ->assertInertia(fn (Assert $page) => $page->where('applications.0.waitlist_position', 3));

        // The first in the queue is next in line; an accepted student has no position.
        $this->actingAs($waiting[0])->get('/events')
            ->assertInertia(fn (Assert $page) => $page->where('events.data.0.waitlist_position', 1));
    }

    public function test_students_who_join_the_waitlist_in_the_same_second_get_different_places_in_line(): void
    {
        // applied_at has one-second precision; the id decides, the same way the promotion does.
        $event = $this->event(['capacity' => 1]);
        $this->actingAs($this->student('TIE000'))->post("/events/{$event->id}/apply");
        $this->travel(1)->seconds();
        $first = $this->student('TIE001');
        $second = $this->student('TIE002');
        $this->freezeTime(function () use ($event, $first, $second) {
            $this->actingAs($first)->post("/events/{$event->id}/apply");
            $this->actingAs($second)->post("/events/{$event->id}/apply");
        });

        $this->actingAs($first)->get("/events/{$event->id}")
            ->assertInertia(fn (Assert $page) => $page->where('event.waitlist_position', 1));
        $this->actingAs($second)->get("/events/{$event->id}")
            ->assertInertia(fn (Assert $page) => $page->where('event.waitlist_position', 2));
    }

    public function test_the_first_in_line_is_promoted_when_two_joined_in_the_same_second(): void
    {
        $event = $this->event(['capacity' => 1]);
        $accepted = $this->student('TIE100');
        $this->actingAs($accepted)->post("/events/{$event->id}/apply");
        $this->travel(2)->minutes();
        $first = $this->student('TIE101');
        $second = $this->student('TIE102');
        $this->freezeTime(function () use ($event, $first, $second) {
            $this->actingAs($first)->post("/events/{$event->id}/apply");
            $this->actingAs($second)->post("/events/{$event->id}/apply");
        });
        // Make the tie certain: both applied in the same second, so only the id can decide.
        EventApplication::whereIn('student_id', [$first->id, $second->id])->update(['applied_at' => now()->startOfSecond()]);

        $this->actingAs($accepted)->post("/events/{$event->id}/withdraw")->assertSessionHasNoErrors();

        $this->assertSame(EventApplication::ACCEPTED, EventApplication::where('student_id', $first->id)->value('status'));
        $this->assertSame(EventApplication::WAITLISTED, EventApplication::where('student_id', $second->id)->value('status'));
    }

    public function test_the_waitlist_positions_take_one_query_for_the_whole_list(): void
    {
        $student = $this->student('WL0100');
        foreach (range(1, 4) as $i) {
            $event = $this->event(['capacity' => 1, 'title' => "Full event {$i}"]);
            $this->actingAs($this->student("WLA00{$i}"))->post("/events/{$event->id}/apply");
            $this->actingAs($student)->post("/events/{$event->id}/apply");
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($student)->get('/events')
            ->assertInertia(fn (Assert $page) => $page->where('events.data.0.waitlist_position', 1));
        $queries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], '"ahead"'));
        DB::disableQueryLog();

        $this->assertCount(1, $queries);
    }
}

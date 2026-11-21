<?php

namespace Tests\Feature\Admin;

use App\Enums\Capability;
use App\Enums\UserRole;
use App\Exceptions\EventActionRefused;
use App\Filament\Pages\EventModeration;
use App\Models\Event;
use App\Models\EventApplication;
use App\Models\PointTransaction;
use App\Models\Semester;
use App\Models\TopicAuditEvent;
use App\Models\User;
use App\Notifications\EventModeratedNotification;
use App\Services\EventCatalog;
use App\Services\EventModerator;
use App\Services\NotificationFeed;
use App\Services\OrganizedEvents;
use App\Services\PointCalculationService;
use App\Services\PointLedger;
use App\Services\TopicEventRules;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Livewire;
use Tests\Feature\Events\EventTestCase;

/**
 * Task #58 (UC-3.5): the system admin finds any event and puts it on hold, cancels it or removes
 * it, each with a reason. Removing reverses every credit with a linked ledger entry, tells the
 * students and the organizer, and is written to the audit log.
 */
class EventModerationTest extends EventTestCase
{
    private User $organiser;

    protected function setUp(): void
    {
        parent::setUp();

        // The person who made the event, with every capability an organizer has at the course.
        $this->organiser = $this->staff(
            [Capability::EventsCreate, Capability::EventsManage, Capability::ApplicationsManage, Capability::PointsCredit, Capability::QrCodesManage],
            null,
            'Olga Organiser',
        );
        $this->event->update(['created_by_id' => $this->organiser->id]);
    }

    private function moderator(): EventModerator
    {
        return app(EventModerator::class);
    }

    private function ledger(): PointLedger
    {
        return app(PointLedger::class);
    }

    /** A student of the course: sees and applies to its events. */
    private function enrolled(?string $name = null): User
    {
        $student = $this->student($name);
        $this->assign($student, $this->studentRole(), $this->course);

        return $student;
    }

    /** The points of the event for a student, written as every credit is: through the ledger model. */
    private function credit(User $student, ?Event $event = null, ?int $semester = null): PointTransaction
    {
        $event ??= $this->event;

        return PointTransaction::create([
            'student_id' => $student->id,
            'event_id' => $event->id,
            'semester_id' => $semester ?? $event->semester_id,
            'credited_by_id' => $this->organiser->id,
            'points' => $event->points_value,
            'type' => PointTransaction::CREDIT,
            'source' => PointLedger::QR_LIVE,
            'category' => $event->category,
        ]);
    }

    private function refused(callable $attempt, string $reason, int $status): void
    {
        try {
            $attempt();
        } catch (EventActionRefused $refused) {
            $this->assertSame($reason, $refused->reason);
            $this->assertSame($status, $refused->status);

            return;
        }

        $this->fail('The action was not refused.');
    }

    /** What the audit log holds about events: nothing before anyone moderates. */
    private function eventAudit(): int
    {
        return TopicAuditEvent::query()->where('subject_type', 'event')->count();
    }

    private function closedSemester(): Semester
    {
        return Semester::create([
            'name' => 'Last year',
            'starts_at' => now()->subYear()->toDateString(),
            'ends_at' => now()->subMonths(6)->toDateString(),
            'status' => 'archived',
        ]);
    }

    // ---------------------------------------------------------------- who and why

    public function test_only_a_system_admin_moderates(): void
    {
        Notification::fake();

        foreach ([$this->organiser, $this->enrolled(), $this->makeUser(UserRole::Teacher)] as $person) {
            foreach (['hold', 'cancel', 'remove'] as $action) {
                $this->refused(fn () => $this->moderator()->{$action}($this->event, $person, 'Reason'), 'forbidden', 403);
            }
        }

        $this->assertSame(Event::STATUS_PUBLISHED, $this->event->fresh()->status);
        $this->assertSame(0, $this->eventAudit());
        $this->assertSame(0, PointTransaction::query()->count());
        Notification::assertNothingSent();
    }

    public function test_a_reason_is_required_and_kept_trimmed(): void
    {
        foreach (['', "  \n "] as $blank) {
            $this->refused(fn () => $this->moderator()->hold($this->event, $this->admin, $blank), 'reason_required', 422);
        }

        $this->refused(fn () => $this->moderator()->remove($this->event, $this->admin, str_repeat('a', EventModerator::MAX_REASON + 1)), 'reason_too_long', 422);
        $this->assertSame(Event::STATUS_PUBLISHED, $this->event->fresh()->status);

        $this->moderator()->hold($this->event, $this->admin, "  Spam  \n");

        $this->assertSame('Spam', TopicAuditEvent::query()->where('action', 'event.held')->sole()->after['reason']);
    }

    // ---------------------------------------------------------------- hold

    public function test_hold_freezes_a_published_event_and_tells_the_applicants_and_the_organizer(): void
    {
        $accepted = $this->enrolled();
        $pending = $this->enrolled();
        $waitlisted = $this->enrolled();
        $withdrawn = $this->enrolled();
        $rejected = $this->enrolled();
        $this->accepted($accepted);
        $this->accepted($pending, null, EventApplication::PENDING);
        $this->accepted($waitlisted, null, EventApplication::WAITLISTED);
        $this->accepted($withdrawn, null, EventApplication::WITHDRAWN);
        $this->accepted($rejected, null, EventApplication::REJECTED);

        Notification::fake();
        $held = $this->moderator()->hold($this->event, $this->admin, ' Misleading description ');

        $this->assertTrue($held->isOnHold());
        $this->assertSame(Event::STATUS_ON_HOLD, $this->event->fresh()->status);

        foreach ([$accepted, $pending, $waitlisted] as $student) {
            Notification::assertSentTo($student, EventModeratedNotification::class,
                fn ($n, array $channels) => $n->change === EventModeratedNotification::HELD
                    && $n->eventId === $this->event->id
                    && $n->reason === null
                    && $n->points === 0
                    && $channels === ['mail', 'database']);
        }

        Notification::assertSentTo($this->organiser, EventModeratedNotification::class,
            fn ($n) => $n->change === EventModeratedNotification::HELD && $n->reason === 'Misleading description');
        Notification::assertNotSentTo($withdrawn, EventModeratedNotification::class);
        Notification::assertNotSentTo($rejected, EventModeratedNotification::class);
        Notification::assertCount(4);

        $audit = TopicAuditEvent::query()->where('action', 'event.held')->sole();
        $this->assertSame($this->admin->id, $audit->actor_id);
        $this->assertSame($this->course->id, $audit->topic_id);
        $this->assertSame('event', $audit->subject_type);
        $this->assertSame($this->event->id, $audit->subject_id);
        $this->assertSame(['status' => 'published'], $audit->before);
        $this->assertSame(['event' => $this->event->title, 'status' => 'on_hold', 'reason' => 'Misleading description'], $audit->after);
    }

    public function test_only_a_published_event_can_be_held(): void
    {
        Notification::fake();

        foreach ([Event::STATUS_DRAFT, Event::STATUS_ON_HOLD, Event::STATUS_COMPLETED, Event::STATUS_CANCELLED, Event::STATUS_REMOVED] as $status) {
            $event = $this->makeEvent(['status' => $status]);

            $this->refused(fn () => $this->moderator()->hold($event, $this->admin, 'Reason'), 'not_published', 409);
            $this->assertSame($status, $event->fresh()->status);
        }

        $this->assertSame(0, $this->eventAudit());
        Notification::assertNothingSent();
    }

    // ---------------------------------------------------------------- cancel

    public function test_cancel_ends_the_event_keeps_its_points_and_tells_the_applicants_and_the_organizer(): void
    {
        $applicant = $this->enrolled();
        $this->accepted($applicant);
        $credited = $this->enrolled();
        $this->credit($credited);

        Notification::fake();
        $cancelled = $this->moderator()->cancel($this->event, $this->admin, 'Not allowed on campus');

        $this->assertSame(Event::STATUS_CANCELLED, $cancelled->status);
        $this->assertNotNull($cancelled->cancelled_at);
        $this->assertSame('Not allowed on campus', $cancelled->cancellation_reason);

        // Cancelling gives no points back: that is what removing is for.
        $this->assertSame(0, PointTransaction::query()->where('type', PointTransaction::REVERSAL)->count());
        $this->assertSame(10, $this->ledger()->totals($credited)['total']);

        Notification::assertSentTo($applicant, EventModeratedNotification::class,
            fn ($n) => $n->change === EventModeratedNotification::CANCELLED && $n->reason === null);
        Notification::assertSentTo($this->organiser, EventModeratedNotification::class,
            fn ($n) => $n->change === EventModeratedNotification::CANCELLED && $n->reason === 'Not allowed on campus');
        Notification::assertCount(2);

        $audit = TopicAuditEvent::query()->where('action', 'event.cancelled')->sole();
        $this->assertSame($this->admin->id, $audit->actor_id);
        $this->assertSame(['status' => 'published'], $audit->before);
        $this->assertSame(['event' => $this->event->title, 'status' => 'cancelled', 'reason' => 'Not allowed on campus'], $audit->after);
    }

    public function test_cancel_works_on_every_open_event_and_on_nothing_that_has_ended(): void
    {
        foreach ([Event::STATUS_DRAFT, Event::STATUS_PUBLISHED, Event::STATUS_ON_HOLD] as $status) {
            $event = $this->makeEvent(['status' => $status]);

            $this->assertSame(Event::STATUS_CANCELLED, $this->moderator()->cancel($event, $this->admin, 'Reason')->status, $status);
        }

        foreach ([Event::STATUS_CANCELLED, Event::STATUS_COMPLETED, Event::STATUS_REMOVED] as $status) {
            $event = $this->makeEvent(['status' => $status]);

            $this->refused(fn () => $this->moderator()->cancel($event, $this->admin, 'Reason'), 'closed', 409);
            $this->assertSame($status, $event->fresh()->status);
        }
    }

    // ---------------------------------------------------------------- remove

    public function test_removing_reverses_every_credit_with_a_linked_ledger_entry(): void
    {
        $students = [$this->enrolled('Anna'), $this->enrolled('Ben'), $this->enrolled('Cora')];
        $credits = array_map(fn (User $student) => $this->credit($student), $students);

        $this->assertSame(10, $this->ledger()->totals($students[0])['total']);

        $taken = $this->moderator()->remove($this->event, $this->admin, ' Fake attendance ');

        $this->assertSame(['credits' => 3, 'points' => 30], $taken);

        $event = $this->event->fresh();
        $this->assertSame(Event::STATUS_REMOVED, $event->status);
        $this->assertTrue($event->isRemoved());
        $this->assertNotNull($event->removed_at);
        $this->assertSame($this->admin->id, $event->removed_by_id);
        $this->assertSame($this->admin->id, $event->removedBy->id);
        $this->assertSame('Fake attendance', $event->removal_reason);

        $reversals = PointTransaction::query()->where('type', PointTransaction::REVERSAL)->orderBy('reversal_of_id')->get();
        $this->assertCount(3, $reversals);

        foreach ($credits as $index => $credit) {
            $reversal = $reversals[$index];

            $this->assertSame($credit->id, $reversal->reversal_of_id);
            $this->assertSame($credit->student_id, $reversal->student_id);
            $this->assertSame($this->event->id, $reversal->event_id);
            $this->assertSame($credit->semester_id, $reversal->semester_id);
            $this->assertSame($credit->category, $reversal->category);
            $this->assertSame($credit->source, $reversal->source);
            $this->assertSame(-10, $reversal->points);
            $this->assertSame($this->admin->id, $reversal->credited_by_id);
            $this->assertSame('Fake attendance', $reversal->reversal_reason);
        }

        // The ledger only grew: the three credits are still there, and the event nets to nothing.
        $this->assertSame(3, PointTransaction::query()->where('type', PointTransaction::CREDIT)->count());
        $this->assertSame(0, (int) PointTransaction::query()->where('event_id', $this->event->id)->sum('points'));

        foreach ($students as $student) {
            $this->assertSame(0, $this->ledger()->totals($student)['total']);
        }

        $audit = TopicAuditEvent::query()->where('action', 'event.removed')->sole();
        $this->assertSame($this->admin->id, $audit->actor_id);
        $this->assertSame($this->course->id, $audit->topic_id);
        $this->assertSame($this->event->id, $audit->subject_id);
        $this->assertSame(['status' => 'published'], $audit->before);
        $this->assertSame([
            'event' => $this->event->title,
            'status' => 'removed',
            'reason' => 'Fake attendance',
            'credits_reversed' => 3,
            'points_reversed' => 30,
        ], $audit->after);
    }

    public function test_a_credit_that_was_already_reversed_is_not_reversed_twice(): void
    {
        $anna = $this->enrolled();
        $first = $this->credit($anna);
        $this->credit($this->enrolled());
        $this->ledger()->reverse($first, $this->admin, 'Wrong student');

        $this->assertSame(['credits' => 1, 'points' => 10], $this->moderator()->remove($this->event, $this->admin, 'Gone'));

        $this->assertSame(2, PointTransaction::query()->where('type', PointTransaction::REVERSAL)->count());
        $this->assertSame(0, (int) PointTransaction::query()->where('event_id', $this->event->id)->sum('points'));
    }

    public function test_a_draft_without_points_can_be_removed_and_a_removed_event_cannot_be_removed_again(): void
    {
        $draft = $this->makeEvent(['status' => Event::STATUS_DRAFT, 'published_at' => null]);

        $this->assertSame(['credits' => 0, 'points' => 0], $this->moderator()->remove($draft, $this->admin, str_repeat('a', EventModerator::MAX_REASON)));
        $this->assertSame(EventModerator::MAX_REASON, mb_strlen($draft->fresh()->removal_reason));
        $this->assertSame(0, PointTransaction::query()->count());

        $this->refused(fn () => $this->moderator()->remove($draft, $this->admin, 'Again'), 'already_removed', 409);
        $this->assertSame(1, TopicAuditEvent::query()->where('action', 'event.removed')->count());
    }

    public function test_an_event_that_was_cancelled_or_completed_is_removed_with_its_points(): void
    {
        foreach ([Event::STATUS_CANCELLED, Event::STATUS_COMPLETED, Event::STATUS_ON_HOLD] as $status) {
            $event = $this->makeEvent(['status' => $status]);
            $this->credit($this->enrolled(), $event);

            $this->assertSame(['credits' => 1, 'points' => 10], $this->moderator()->remove($event, $this->admin, 'Gone'), $status);
            $this->assertSame(Event::STATUS_REMOVED, $event->fresh()->status);
        }
    }

    public function test_a_closed_semester_stops_the_whole_removal(): void
    {
        $open = $this->credit($this->enrolled());
        $old = $this->credit($this->enrolled(), null, $this->closedSemester()->id);

        Notification::fake();
        $this->refused(fn () => $this->moderator()->remove($this->event, $this->admin, 'Gone'), 'semester_closed', 409);

        // All or nothing: the credit that came first was not reversed either.
        $event = $this->event->fresh();
        $this->assertSame(Event::STATUS_PUBLISHED, $event->status);
        $this->assertNull($event->removed_at);
        $this->assertNull($event->removed_by_id);
        $this->assertNull($event->removal_reason);
        $this->assertSame(0, PointTransaction::query()->where('type', PointTransaction::REVERSAL)->count());
        $this->assertSame(10, $this->ledger()->totals(User::query()->findOrFail($open->student_id))['total']);
        $this->assertSame(10, $this->ledger()->totals(User::query()->findOrFail($old->student_id))['total']);
        $this->assertSame(0, $this->eventAudit());
        Notification::assertNothingSent();
    }

    public function test_totals_and_standings_drop_at_once(): void
    {
        $anna = $this->enrolled();
        $this->credit($anna);
        $points = app(PointCalculationService::class);

        // Both are cached; reading them now fills the cache.
        $this->assertSame(10, $points->studentTotals($anna->id)['total']);
        $this->assertSame([$anna->id => 10], collect($points->areaStandings($this->course->id))->pluck('points', 'student_id')->all());

        $this->moderator()->remove($this->event, $this->admin, 'Gone');

        $this->assertSame(0, $points->studentTotals($anna->id)['total']);
        $this->assertSame([], $points->areaStandings($this->course->id));
    }

    public function test_points_from_before_a_reset_are_reversed_but_not_announced(): void
    {
        $before = $this->enrolled('Before');
        $after = $this->enrolled('After');
        $this->credit($before);
        $this->ledger()->resetAll($this->admin);
        $this->credit($after);

        Notification::fake();
        $taken = $this->moderator()->remove($this->event, $this->admin, 'Gone');

        // Every credit gets its linked entry, so the ledger is whole.
        $this->assertSame(['credits' => 2, 'points' => 20], $taken);
        $this->assertSame(2, PointTransaction::query()->where('type', PointTransaction::REVERSAL)->count());

        // The first student's points were reset away already, so nothing changes for them.
        Notification::assertSentTo($after, EventModeratedNotification::class, fn ($n) => $n->points === 10);
        Notification::assertNotSentTo($before, EventModeratedNotification::class);
    }

    public function test_a_credit_cannot_be_written_after_the_removal(): void
    {
        $student = $this->enrolled();
        $this->accepted($student);
        $stale = $this->event->fresh(); // still says published, as a scan that started a moment ago sees it

        $this->moderator()->remove($this->event, $this->admin, 'Gone');

        $this->refused(fn () => $this->ledger()->credit($stale, $student, $this->organiser, PointLedger::QR_LIVE), 'inactive', 409);
        $this->assertSame(0, PointTransaction::query()->count());
    }

    // ---------------------------------------------------------------- who is told

    public function test_removal_tells_students_what_happened_and_the_organizer_why(): void
    {
        $applicant = $this->enrolled();
        $this->accepted($applicant);
        $credited = $this->enrolled();
        $this->credit($credited);
        $both = $this->enrolled();
        $this->accepted($both);
        $this->credit($both);
        $withdrawn = $this->enrolled();
        $this->accepted($withdrawn, null, EventApplication::WITHDRAWN);

        Notification::fake();
        $this->moderator()->remove($this->event, $this->admin, 'Fake');

        // A student who only applied is told the event is gone; one who got points is told they were taken back.
        Notification::assertSentTo($applicant, EventModeratedNotification::class,
            fn ($n) => $n->change === EventModeratedNotification::REMOVED && $n->reason === null && $n->points === 0);
        Notification::assertSentTo($credited, EventModeratedNotification::class,
            fn ($n) => $n->change === EventModeratedNotification::REMOVED && $n->reason === null && $n->points === 10);
        Notification::assertSentToTimes($both, EventModeratedNotification::class, 1);
        Notification::assertSentTo($both, EventModeratedNotification::class, fn ($n) => $n->points === 10);
        Notification::assertNotSentTo($withdrawn, EventModeratedNotification::class);

        // The reason goes to the organizer only.
        Notification::assertSentTo($this->organiser, EventModeratedNotification::class,
            fn ($n) => $n->change === EventModeratedNotification::REMOVED && $n->reason === 'Fake' && $n->points === 0);
        Notification::assertCount(4);
    }

    public function test_an_organizer_who_applied_to_their_own_event_is_told_once_and_with_the_reason(): void
    {
        $this->accepted($this->organiser);
        $this->credit($this->organiser);

        Notification::fake();
        $this->moderator()->remove($this->event, $this->admin, 'Fake');

        Notification::assertSentToTimes($this->organiser, EventModeratedNotification::class, 1);
        Notification::assertSentTo($this->organiser, EventModeratedNotification::class, fn ($n) => $n->reason === 'Fake');
    }

    public function test_the_mail_and_the_notifications_page_say_the_same(): void
    {
        $student = $this->enrolled('Anna');

        foreach ([
            [EventModeratedNotification::HELD, null, 0],
            [EventModeratedNotification::CANCELLED, 'Not allowed', 0],
            [EventModeratedNotification::REMOVED, null, 1],
            [EventModeratedNotification::REMOVED, 'Fake', 3],
        ] as [$change, $reason, $points]) {
            [$title, $lines] = EventModeratedNotification::words($change, 'Chess night', $reason, $points);

            $mail = (new EventModeratedNotification($change, 7, 'Chess night', $reason, $points))->toMail($student);
            $this->assertSame($title, $mail->subject);
            $this->assertSame($lines, $mail->introLines);

            $student->notifications()->delete();
            $student->notify(new EventModeratedNotification($change, 7, 'Chess night', $reason, $points));
            $shown = app(NotificationFeed::class)->present($student->notifications()->sole());

            $this->assertSame($title, $shown['title']);
            $this->assertSame(implode(' ', $lines), $shown['body']);
            $this->assertSame('/my-events', $shown['link']);
        }

        // The words themselves, once in English.
        $this->assertSame(['Chess night was removed', [
            'A system admin removed Chess night. It will not take place and is no longer shown anywhere.',
            'Reason: Fake',
            'The 3 points you got from it were taken back.',
        ]], EventModeratedNotification::words(EventModeratedNotification::REMOVED, 'Chess night', 'Fake', 3));
        $this->assertSame('The point you got from it was taken back.', EventModeratedNotification::words(EventModeratedNotification::REMOVED, 'Chess night', null, 1)[1][1]);
        $this->assertSame('Chess night is on hold', EventModeratedNotification::words(EventModeratedNotification::HELD, 'Chess night')[0]);
        $this->assertSame('Chess night is cancelled', EventModeratedNotification::words(EventModeratedNotification::CANCELLED, 'Chess night')[0]);
    }

    // ---------------------------------------------------------------- gone for everybody

    public function test_a_removed_event_is_gone_for_everybody(): void
    {
        $student = $this->enrolled();
        $this->accepted($student);
        $catalog = app(EventCatalog::class);
        $organized = app(OrganizedEvents::class);
        $id = $this->event->id;

        // Before: everybody finds it.
        $this->assertTrue($catalog->query($student)->whereKey($id)->exists());
        $this->assertTrue($catalog->query($this->organiser)->whereKey($id)->exists());
        $this->assertTrue($catalog->inBranch($this->organiser, $this->course)->whereKey($id)->exists());
        $this->assertSame(1, $organized->counts($this->organiser)['upcoming']);
        $this->actingAs($student)->get("/events/{$id}")->assertOk();
        $this->actingAs($student)->get('/my-events')->assertInertia(fn (Assert $page) => $page->component('Events/Applied')->has('applications', 1));

        $this->moderator()->remove($this->event, $this->admin, 'Gone');

        // After: the catalog, the explorer, the organizer's tabs and the student's applications leave it out.
        $this->assertFalse($catalog->query($student)->whereKey($id)->exists());
        $this->assertFalse($catalog->query($this->organiser)->whereKey($id)->exists());
        $this->assertFalse($catalog->inBranch($this->organiser, $this->course)->whereKey($id)->exists());
        $this->assertFalse($catalog->inBranch($this->admin, $this->university)->whereKey($id)->exists());
        $this->assertSame(0, array_sum($organized->counts($this->organiser)));

        foreach (OrganizedEvents::FILTERS as $filter) {
            $this->assertSame([], $organized->list($this->organiser, $filter), $filter);
        }

        // The page of the event is "not found" for the student and for the organizer who made it.
        foreach ([$student, $this->organiser] as $person) {
            $this->actingAs($person)->get("/events/{$id}")->assertNotFound();
        }

        $this->actingAs($student)->get('/my-events')->assertInertia(fn (Assert $page) => $page->component('Events/Applied')->has('applications', 0));
        $this->actingAs($student)->get('/events')->assertInertia(fn (Assert $page) => $page->component('Events/Index')->has('events.data', 0));
        $this->actingAs($this->organiser)->get('/my-events')->assertInertia(fn (Assert $page) => $page->component('Events/Organized')->has('events', 0));
    }

    public function test_a_removed_event_refuses_every_ability_even_to_a_system_admin(): void
    {
        $student = $this->enrolled();
        $live = $this->makeEvent();

        $this->moderator()->remove($this->event, $this->admin, 'Gone');
        $removed = $this->event->fresh();

        foreach ([$this->admin, $this->organiser, $student] as $person) {
            foreach (['view', 'staff', 'apply', 'manage', 'update', 'manageApplicants', 'credit', 'manageQr'] as $ability) {
                $this->assertTrue(Gate::forUser($person)->denies($ability, $removed), "{$person->name} may {$ability} a removed event");
            }
        }

        // Only an event is asked about: creating events, and every other event, is as before.
        $this->assertTrue(Gate::forUser($this->organiser)->allows('create', Event::class));
        $this->assertTrue(Gate::forUser($this->admin)->allows('create', Event::class));
        $this->assertTrue(Gate::forUser($this->organiser)->allows('manage', $live));
        $this->assertTrue(Gate::forUser($student)->allows('view', $live));
    }

    public function test_nobody_can_act_on_a_removed_event(): void
    {
        $student = $this->enrolled();
        $this->moderator()->remove($this->event, $this->admin, 'Gone');
        $id = $this->event->id;

        // JSON requests: a plain page request that is refused is sent back with a message instead.
        foreach (['hold', 'restore', 'cancel', 'publish'] as $action) {
            $this->actingAs($this->organiser)->postJson("/events/{$id}/{$action}", ['reason' => 'Because'])->assertForbidden();
        }

        $this->actingAs($this->organiser)->deleteJson("/events/{$id}")->assertForbidden();
        $this->actingAs($this->organiser)->get("/events/{$id}/edit")->assertNotFound();
        // Applying is refused by the application rules ("not open for applications"), and nothing is written.
        $this->actingAs($student)->postJson("/events/{$id}/apply")->assertStatus(422)->assertJsonValidationErrors('event');
        $this->assertSame(0, EventApplication::query()->where('event_id', $id)->count());

        // Points: nothing can be credited, whichever way.
        $this->assertSame('inactive', $this->ledger()->refusalFor($this->event->fresh())->reason);
        $this->assertSame(Event::STATUS_REMOVED, $this->event->fresh()->status);
    }

    public function test_a_removed_event_no_longer_blocks_moving_or_freezing_an_area_but_still_blocks_deleting_it(): void
    {
        $other = $this->topic('Other faculty', $this->university);
        $announced = $this->makeEvent(['audience_topic_id' => $this->faculty->id]);
        $rules = app(TopicEventRules::class);

        // Moving the course out of the faculty would strand an event announced to the faculty.
        $this->assertNotEmpty($rules->moveProblems($this->course, $other));
        $this->assertSame(2, $rules->liveEventsIn($this->course));

        $this->moderator()->remove($announced, $this->admin, 'Gone');

        $this->assertSame([], $rules->moveProblems($this->course, $other));
        $this->assertSame(1, $rules->liveEventsIn($this->course));

        // The removed event keeps its row, so an area that ever had an event is still not deleted.
        $this->assertNotEmpty($rules->deleteBlockers($this->course));
    }

    // ---------------------------------------------------------------- the admin page

    public function test_only_a_system_admin_opens_the_page_and_it_no_longer_lists_planned_features(): void
    {
        $this->actingAs($this->organiser)->get('/admin/event-moderation')->assertRedirect('/');

        $this->actingAs($this->admin)->get('/admin/event-moderation')
            ->assertOk()
            ->assertSee(__('admin.event_moderation.title'))
            ->assertSee(__('admin.event_moderation.subheading'))
            ->assertDontSee(__('admin.coming_soon.heading'))
            ->assertDontSee(__('admin.coming_soon.this_page_will_let'));
    }

    public function test_the_page_lists_every_event_and_finds_one_by_title_area_or_organizer(): void
    {
        $chemistry = $this->topic('Chemistry', $this->faculty);
        $pete = $this->staff([Capability::EventsCreate], $chemistry, 'Pete Chemist');
        $mine = $this->makeEvent(['title' => 'Database night']);
        $theirs = $this->makeEvent(['title' => 'Lab safety', 'owning_topic_id' => $chemistry->id, 'audience_topic_id' => $chemistry->id, 'created_by_id' => $pete->id]);
        $removed = $this->makeEvent(['title' => 'Spam party', 'status' => Event::STATUS_REMOVED, 'removed_at' => now(), 'removed_by_id' => $this->admin->id, 'removal_reason' => 'Pure spam']);

        $page = Livewire::actingAs($this->admin)->test(EventModeration::class)
            ->assertCanSeeTableRecords([$this->event, $mine, $theirs, $removed])
            ->assertSee('Pure spam')
            ->assertSee(__('admin.event_moderation.removed_by_on', ['name' => 'Admin One', 'date' => $removed->fresh()->removed_at->translatedFormat('j M Y')]));

        $page->searchTable('Lab safety')->assertCanSeeTableRecords([$theirs])->assertCanNotSeeTableRecords([$this->event, $mine, $removed]);
        $page->searchTable('Chemistry')->assertCanSeeTableRecords([$theirs])->assertCanNotSeeTableRecords([$this->event, $mine, $removed]);
        $page->searchTable('Pete Chemist')->assertCanSeeTableRecords([$theirs])->assertCanNotSeeTableRecords([$this->event, $mine, $removed]);
        $page->searchTable('Olga Organiser')->assertCanSeeTableRecords([$this->event])->assertCanNotSeeTableRecords([$theirs, $removed]);

        $page->searchTable('')->filterTable('status', Event::STATUS_REMOVED)
            ->assertCanSeeTableRecords([$removed])
            ->assertCanNotSeeTableRecords([$this->event, $mine, $theirs]);
    }

    public function test_the_actions_of_a_row_follow_its_status(): void
    {
        $rows = [
            'draft' => $this->makeEvent(['status' => Event::STATUS_DRAFT]),
            'published' => $this->event,
            'on_hold' => $this->makeEvent(['status' => Event::STATUS_ON_HOLD]),
            'completed' => $this->makeEvent(['status' => Event::STATUS_COMPLETED]),
            'cancelled' => $this->makeEvent(['status' => Event::STATUS_CANCELLED]),
            'removed' => $this->makeEvent(['status' => Event::STATUS_REMOVED]),
        ];

        // action => the statuses it is offered for
        $offered = [
            'holdEvent' => ['published'],
            'cancelEvent' => ['draft', 'published', 'on_hold'],
            'removeEvent' => ['draft', 'published', 'on_hold', 'completed', 'cancelled'],
        ];

        $page = Livewire::actingAs($this->admin)->test(EventModeration::class);

        foreach ($offered as $action => $statuses) {
            foreach ($rows as $status => $event) {
                in_array($status, $statuses, true)
                    ? $page->assertTableActionVisible($action, $event)
                    : $page->assertTableActionHidden($action, $event);
            }
        }
    }

    public function test_the_page_holds_cancels_and_removes_with_a_reason(): void
    {
        $second = $this->makeEvent(['title' => 'Second event']);
        $third = $this->makeEvent(['title' => 'Third event']);
        $this->credit($this->enrolled(), $third);
        $this->credit($this->enrolled(), $third);

        $page = Livewire::actingAs($this->admin)->test(EventModeration::class);

        $page->callTableAction('holdEvent', $this->event, ['reason' => 'Check the claims'])
            ->assertNotified(__('events.status.title_on_hold', ['title' => $this->event->title]));
        $this->assertSame(Event::STATUS_ON_HOLD, $this->event->fresh()->status);

        $page->callTableAction('cancelEvent', $second, ['reason' => 'Not allowed'])
            ->assertNotified(__('events.status.title_cancelled', ['title' => 'Second event']));
        $this->assertSame(Event::STATUS_CANCELLED, $second->fresh()->status);
        $this->assertSame('Not allowed', $second->fresh()->cancellation_reason);

        $page->callTableAction('removeEvent', $third, ['reason' => 'Fake attendance'])
            ->assertNotified(__('admin.event_moderation.title_removed', ['title' => 'Third event']));
        $this->assertSame(Event::STATUS_REMOVED, $third->fresh()->status);
        $this->assertSame('Fake attendance', $third->fresh()->removal_reason);
        $this->assertSame(2, PointTransaction::query()->where('type', PointTransaction::REVERSAL)->where('event_id', $third->id)->count());

        $this->assertSame(['event.held', 'event.cancelled', 'event.removed'], TopicAuditEvent::query()->where('subject_type', 'event')->orderBy('id')->pluck('action')->all());
    }

    public function test_the_page_asks_for_a_reason_every_time(): void
    {
        // A page of its own for each try: after a refused form the dialog stays open.
        $page = fn () => Livewire::actingAs($this->admin)->test(EventModeration::class);

        foreach (['holdEvent', 'cancelEvent', 'removeEvent'] as $action) {
            $page()->callTableAction($action, $this->event, ['reason' => ''])->assertHasTableActionErrors(['reason' => 'required']);
            $page()->callTableAction($action, $this->event, ['reason' => str_repeat('a', EventModerator::MAX_REASON + 1)])->assertHasTableActionErrors(['reason' => 'max']);
        }

        $this->assertSame(Event::STATUS_PUBLISHED, $this->event->fresh()->status);
        $this->assertSame(0, $this->eventAudit());
    }

    public function test_the_page_says_when_a_rule_refused_and_changes_nothing(): void
    {
        $this->credit($this->enrolled(), null, $this->closedSemester()->id);

        Livewire::actingAs($this->admin)->test(EventModeration::class)
            ->callTableAction('removeEvent', $this->event, ['reason' => 'Gone'])
            ->assertNotified(__('admin.event_moderation.nothing_changed'));

        $this->assertSame(Event::STATUS_PUBLISHED, $this->event->fresh()->status);
        $this->assertSame(0, PointTransaction::query()->where('type', PointTransaction::REVERSAL)->count());
    }

    public function test_the_removal_dialog_says_what_it_will_do_before_the_admin_confirms(): void
    {
        $this->credit($this->enrolled());
        $this->credit($this->enrolled());

        Livewire::actingAs($this->admin)->test(EventModeration::class)
            ->mountTableAction('removeEvent', $this->event)
            ->assertMountedActionModalSee([
                __('admin.event_moderation.remove_heading', ['title' => $this->event->title]),
                __('admin.event_moderation.remove_warning', ['title' => $this->event->title]),
                trans_choice('admin.event_moderation.remove_credits', 2, ['points' => 20]),
            ]);

        // The numbers are the ones the removal then writes.
        $this->assertSame(['applicants' => 0, 'credits' => 2, 'points' => 20], $this->moderator()->impact($this->event));
    }
}

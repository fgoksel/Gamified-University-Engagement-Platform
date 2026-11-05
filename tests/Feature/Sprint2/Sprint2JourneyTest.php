<?php

namespace Tests\Feature\Sprint2;

use App\Enums\Capability;
use App\Enums\UserRole;
use App\Models\Event;
use App\Models\EventApplication;
use App\Models\PointTransaction;
use App\Models\RoleDefinition;
use App\Models\Semester;
use App\Models\Topic;
use App\Models\TopicTag;
use App\Models\User;
use App\Notifications\EventApplicationDecisionNotification;
use App\Services\PointLedger;
use App\Services\QrPassport;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use LogicException;
use Tests\Feature\Topics\TopicTestCase;

/**
 * Task #67: the Sprint 2 integration run, end to end through the same routes
 * the pages use. Organizer -> event -> application -> attendance -> points, by
 * QR crediting, across two branches of one university:
 *
 *   University
 *   ├── Faculty of Informatics  (branch A, organiser Ivan)
 *   │   └── Databases           (students Anna, Bence, Csilla, Dani)
 *   └── Faculty of Sport        (branch B, organiser Sara)
 *       └── Athletics Club      (student Erik)
 *
 * The acceptance criteria of #67 are each covered by a test below: refused
 * cross-branch actions, no overbooking, no double credit and waitlist promotion.
 */
class Sprint2JourneyTest extends TopicTestCase
{
    private Topic $informatics;

    private Topic $databases;

    private Topic $sport;

    private Topic $athletics;

    private User $ivan;

    private User $sara;

    /** @var array<string, User> */
    private array $students = [];

    private TopicTag $tag;

    protected function setUp(): void
    {
        parent::setUp();

        $university = $this->topic('University');
        $this->informatics = $this->topic('Faculty of Informatics', $university);
        $this->databases = $this->topic('Databases', $this->informatics);
        $this->sport = $this->topic('Faculty of Sport', $university);
        $this->athletics = $this->topic('Athletics Club', $this->sport);

        $organiser = $this->role('Event organiser', [
            Capability::View, Capability::EventsView, Capability::EventsCreate, Capability::EventsManage,
            Capability::ApplicationsManage, Capability::PointsCredit, Capability::QrCodesManage,
        ]);
        $this->ivan = $this->makeUser(UserRole::Teacher, 'Ivan Informatics');
        $this->sara = $this->makeUser(UserRole::Teacher, 'Sara Sport');
        $this->assign($this->ivan, $organiser, $this->informatics);
        $this->assign($this->sara, $organiser, $this->sport);

        $student = $this->role('Event student', [Capability::View, Capability::EventsView, Capability::EventsApply]);
        foreach (['Anna' => $this->databases, 'Bence' => $this->databases, 'Csilla' => $this->databases, 'Dani' => $this->databases, 'Erik' => $this->athletics] as $name => $topic) {
            $this->students[$name] = $this->enrol($name, $student, $topic);
        }

        Semester::create([
            'name' => '2026/27 autumn',
            'starts_at' => now()->subMonth()->toDateString(),
            'ends_at' => now()->addMonths(4)->toDateString(),
            'status' => 'active',
        ]);
        $this->tag = TopicTag::create(['name' => 'Workshop', 'category' => 'academic', 'is_active' => true]);
    }

    private function enrol(string $name, RoleDefinition $role, Topic $topic): User
    {
        $student = $this->makeUser(UserRole::Student, $name);
        $student->forceFill(['neptun_code' => strtoupper(substr($name, 0, 3)).'123'])->save();
        $this->assign($student, $role, $topic);

        return $student;
    }

    /**
     * Create and publish an event through the Event Form, as the organiser would.
     *
     * @param  array<string, mixed>  $data
     */
    private function publish(User $organiser, Topic $at, array $data = []): Event
    {
        $this->actingAs($organiser)->post('/events', [
            'action' => 'publish',
            'title' => 'Event '.uniqid(),
            'description' => 'Part of the Sprint 2 integration run.',
            'topic_tag_id' => $this->tag->id,
            'owning_topic_id' => $at->id,
            'audience_topic_id' => $at->id,
            'capacity' => 2,
            'application_type' => 'fcfs',
            'points_value' => 15,
            'event_starts_at' => now()->addDays(2)->toDateTimeString(),
            'event_ends_at' => now()->addDays(2)->addHours(2)->toDateTimeString(),
            'application_deadline' => now()->addDays(2)->subHour()->toDateTimeString(),
            'completion_deadline' => now()->addDays(9)->toDateTimeString(),
            'qr_enabled' => true,
            ...$data,
        ])->assertRedirect(route('events.mine'))->assertSessionHas('success', 'Your event is published.');

        return Event::query()->latest('id')->firstOrFail();
    }

    private function apply(string $student, Event $event): string
    {
        return $this->actingAs($this->students[$student])
            ->postJson("/events/{$event->id}/apply")
            ->assertCreated()
            ->json('status');
    }

    private function statusOf(string $student, Event $event): string
    {
        return EventApplication::query()
            ->where('event_id', $event->id)
            ->where('student_id', $this->students[$student]->id)
            ->value('status');
    }

    /**
     * Move the clock to just after the event starts, when attendance can be credited.
     */
    private function startEvent(Event $event): void
    {
        $this->travelTo($event->event_starts_at->copy()->addMinutes(15));
    }

    /**
     * The organiser scans a student's QR passport (Tasks #43, #52).
     */
    private function scanPassport(User $organiser, string $student, Event $event): TestResponse
    {
        return $this->actingAs($organiser)->postJson("/events/{$event->id}/credit", [
            'identifier' => app(QrPassport::class)->issue($this->students[$student]),
        ]);
    }

    private function slipCode(User $organiser, Event $event): string
    {
        $url = $this->actingAs($organiser)
            ->postJson("/events/{$event->id}/qr-tokens", ['type' => 'slip'])
            ->assertCreated()
            ->json('url');

        // The phone camera opens ".../qr/redeem/<code>"; the scan page posts the code.
        return substr($url, strrpos($url, '/') + 1);
    }

    private function points(string $student): int
    {
        return app(PointLedger::class)->totals($this->students[$student])['total'];
    }

    /**
     * A page or form the person may not use: they are sent back to the Leaderboard
     * with a message (UC-1.2); JSON callers get a plain 403 instead.
     */
    private function assertRefused(TestResponse $response): void
    {
        $response->assertRedirect('/')->assertSessionHas('error', 'You do not have permission to view this page.');
    }

    public function test_fcfs_journey_with_waitlist_promotion_and_passport_crediting(): void
    {
        Notification::fake();
        $event = $this->publish($this->ivan, $this->databases, ['capacity' => 2]);

        // Two places: Anna and Bence get them, Csilla and Dani wait in order.
        $this->assertSame('accepted', $this->apply('Anna', $event));
        $this->travel(1)->minutes();
        $this->assertSame('accepted', $this->apply('Bence', $event));
        $this->travel(1)->minutes();
        $this->assertSame('waitlisted', $this->apply('Csilla', $event));
        $this->travel(1)->minutes();
        $this->assertSame('waitlisted', $this->apply('Dani', $event));

        // Anna withdraws before the deadline: Csilla, the oldest on the waitlist, moves up.
        $this->actingAs($this->students['Anna'])->postJson("/events/{$event->id}/withdraw")->assertOk();
        $this->assertSame('withdrawn', $this->statusOf('Anna', $event));
        $this->assertSame('accepted', $this->statusOf('Csilla', $event));
        $this->assertSame('waitlisted', $this->statusOf('Dani', $event));
        Notification::assertSentTo($this->students['Csilla'], EventApplicationDecisionNotification::class,
            fn ($n) => $n->decision === EventApplicationDecisionNotification::PROMOTED);

        // Attendance cannot be credited before the event starts.
        $this->scanPassport($this->ivan, 'Bence', $event)
            ->assertStatus(422)
            ->assertJsonPath('errors.event.0', 'The event has not started yet.');

        // At the event, the organiser scans the passport of everyone who shows up.
        $this->startEvent($event);
        $this->scanPassport($this->ivan, 'Bence', $event)->assertOk()->assertJsonPath('status', 'credited');
        $this->scanPassport($this->ivan, 'Csilla', $event)->assertOk()->assertJsonPath('status', 'credited')->assertJsonPath('credited_count', 2);
        // Dani is still waitlisted and Anna withdrew: neither is credited.
        $this->scanPassport($this->ivan, 'Dani', $event)->assertOk()->assertJsonPath('status', 'not_accepted');
        $this->scanPassport($this->ivan, 'Anna', $event)->assertOk()->assertJsonPath('status', 'not_accepted');

        $this->assertSame([15, 15, 0, 0], [$this->points('Bence'), $this->points('Csilla'), $this->points('Dani'), $this->points('Anna')]);
        $this->assertSame(PointLedger::PASSPORT, PointTransaction::query()->where('student_id', $this->students['Bence']->id)->value('source'));

        // The organiser's page shows both as credited.
        $this->actingAs($this->ivan)->get("/events/{$event->id}/scan")->assertOk()
            ->assertInertia(fn ($page) => $page->where('stats.credited_count', 2)->where('stats.accepted_count', 2));
    }

    public function test_selection_journey_with_qr_passport_and_slip_crediting(): void
    {
        Notification::fake();
        $event = $this->publish($this->ivan, $this->databases, ['application_type' => 'teacher_select', 'capacity' => 2]);

        foreach (['Anna', 'Bence', 'Csilla'] as $name) {
            $this->assertSame('pending', $this->apply($name, $event));
        }
        $applications = EventApplication::query()->where('event_id', $event->id)->get()->keyBy('student_id');
        $id = fn (string $name) => $applications[$this->students[$name]->id]->id;

        // Ticking three for two places is refused unless the organiser agrees to grow the event.
        $this->actingAs($this->ivan)
            ->post("/events/{$event->id}/applicants/select", ['selected' => [$id('Anna'), $id('Bence'), $id('Csilla')]])
            ->assertSessionHasErrors('capacity');
        $this->assertSame(0, EventApplication::query()->where('event_id', $event->id)->where('status', 'accepted')->count());

        // The organiser selects Anna and Bence and rejects Csilla.
        $this->actingAs($this->ivan)
            ->post("/events/{$event->id}/applicants/select", ['selected' => [$id('Anna'), $id('Bence')]])
            ->assertSessionHas('success');
        $this->actingAs($this->ivan)
            ->post("/events/{$event->id}/applicants/{$id('Csilla')}/reject")
            ->assertSessionHas('success');
        $this->assertSame(['accepted', 'accepted', 'rejected'], [$this->statusOf('Anna', $event), $this->statusOf('Bence', $event), $this->statusOf('Csilla', $event)]);
        Notification::assertSentTo($this->students['Anna'], EventApplicationDecisionNotification::class, fn ($n) => $n->decision === 'accepted');
        Notification::assertSentTo($this->students['Csilla'], EventApplicationDecisionNotification::class, fn ($n) => $n->decision === 'rejected');

        $this->startEvent($event);

        // Teacher scans the student: Anna shows her QR passport (Tasks #43, #52).
        $passport = app(QrPassport::class)->issue($this->students['Anna']);
        $this->actingAs($this->ivan)->postJson("/events/{$event->id}/credit", ['identifier' => $passport])
            ->assertOk()->assertJsonPath('status', 'credited');

        // Student scans the event: Bence scans a printed slip (Tasks #45, #51, #84).
        $code = $this->slipCode($this->ivan, $event);
        $this->actingAs($this->students['Bence'])->postJson('/qr/redeem', ['code' => $code])
            ->assertOk()->assertJsonPath('outcome', 'credited')->assertJsonPath('points', 15);

        // Csilla was rejected: the slip gives her nothing, and neither does her passport.
        $this->actingAs($this->students['Csilla'])->postJson('/qr/redeem', ['code' => $code])
            ->assertForbidden()->assertJsonPath('reason', 'not_registered');
        $this->actingAs($this->ivan)
            ->postJson("/events/{$event->id}/credit", ['identifier' => app(QrPassport::class)->issue($this->students['Csilla'])])
            ->assertOk()->assertJsonPath('status', 'not_accepted');

        $this->assertSame(
            ['passport', 'qr_slip'],
            PointTransaction::query()->where('event_id', $event->id)->orderBy('id')->pluck('source')->all()
        );
        $this->assertSame([15, 15, 0], [$this->points('Anna'), $this->points('Bence'), $this->points('Csilla')]);
    }

    public function test_actions_across_branches_are_refused(): void
    {
        $sportEvent = $this->publish($this->sara, $this->athletics, ['title' => 'Campus 5K Run']);
        $sportApplication = EventApplication::create(['event_id' => $sportEvent->id, 'student_id' => $this->students['Erik']->id, 'status' => 'accepted', 'applied_at' => now()]);

        // Ivan cannot create an event in the Sport branch.
        $this->actingAs($this->ivan)->post('/events', [
            'action' => 'publish', 'title' => 'Sneaky', 'description' => 'x', 'topic_tag_id' => $this->tag->id,
            'owning_topic_id' => $this->athletics->id, 'audience_topic_id' => $this->athletics->id,
            'capacity' => 5, 'application_type' => 'fcfs', 'points_value' => 5,
            'event_starts_at' => now()->addDays(2)->toDateTimeString(), 'event_ends_at' => now()->addDays(2)->addHour()->toDateTimeString(),
            'application_deadline' => now()->addDay()->toDateTimeString(), 'completion_deadline' => now()->addDays(3)->toDateTimeString(),
        ])->assertSessionHasErrors(['owning_topic_id' => 'You cannot create events at this topic.']);

        // ...nor see, run, review, credit or make QR codes for Sara's event.
        $this->actingAs($this->ivan)->get("/events/{$sportEvent->id}")->assertNotFound();
        $this->assertRefused($this->actingAs($this->ivan)->get("/events/{$sportEvent->id}/applicants"));
        $this->assertRefused($this->actingAs($this->ivan)->post("/events/{$sportEvent->id}/applicants/{$sportApplication->id}/reject"));
        $this->assertRefused($this->actingAs($this->ivan)->post("/events/{$sportEvent->id}/hold"));
        $this->assertRefused($this->actingAs($this->ivan)->post("/events/{$sportEvent->id}/cancel", ['reason' => 'x']));
        $this->assertRefused($this->actingAs($this->ivan)->get("/events/{$sportEvent->id}/scan"));
        // The QR endpoint does not even say that an event it hides exists.
        $this->actingAs($this->ivan)->postJson("/events/{$sportEvent->id}/qr-tokens", ['type' => 'slip'])->assertNotFound();
        $this->assertSame(['published', 'accepted'], [$sportEvent->fresh()->status, $sportApplication->fresh()->status]);

        $this->startEvent($sportEvent);
        $this->scanPassport($this->ivan, 'Erik', $sportEvent)->assertForbidden();
        $this->travelBack();

        // An Informatics student cannot see or apply to a Sport event, and the reverse.
        $informaticsEvent = $this->publish($this->ivan, $this->databases);
        $this->actingAs($this->students['Anna'])->get("/events/{$sportEvent->id}")->assertNotFound();
        $this->actingAs($this->students['Anna'])->postJson("/events/{$sportEvent->id}/apply")->assertStatus(422);
        $this->actingAs($this->students['Erik'])->postJson("/events/{$informaticsEvent->id}/apply")->assertStatus(422);

        // Sara's printed slip gives an Informatics student nothing.
        $this->startEvent($sportEvent);
        $code = $this->slipCode($this->sara, $sportEvent);
        $this->actingAs($this->students['Anna'])->postJson('/qr/redeem', ['code' => $code])->assertForbidden();

        // Inside her own branch, Sara can do all of it.
        $this->scanPassport($this->sara, 'Erik', $sportEvent)->assertOk()->assertJsonPath('status', 'credited');

        $this->assertSame(1, PointTransaction::count());
        $this->assertSame(0, EventApplication::query()->where('event_id', $sportEvent->id)->where('student_id', $this->students['Anna']->id)->count());
    }

    public function test_the_last_place_is_never_given_twice(): void
    {
        $event = $this->publish($this->ivan, $this->databases, ['capacity' => 1]);

        $results = array_map(fn (string $name) => $this->apply($name, $event), ['Anna', 'Bence', 'Csilla']);

        $this->assertSame(['accepted', 'waitlisted', 'waitlisted'], $results);
        $this->assertSame(1, EventApplication::query()->where('event_id', $event->id)->where('status', 'accepted')->count());

        // Applying again is refused, so nobody can queue twice.
        $this->actingAs($this->students['Bence'])->postJson("/events/{$event->id}/apply")
            ->assertStatus(422)->assertJsonPath('errors.event.0', 'You have already applied to this event.');

        // In selection mode, ticking more than the capacity is refused too (D08).
        $selection = $this->publish($this->ivan, $this->databases, [
            'application_type' => 'teacher_select',
            'capacity' => 1,
            // A week later, so it does not overlap the first event.
            'event_starts_at' => now()->addDays(9)->toDateTimeString(),
            'event_ends_at' => now()->addDays(9)->addHours(2)->toDateTimeString(),
            'application_deadline' => now()->addDays(8)->toDateTimeString(),
            'completion_deadline' => now()->addDays(16)->toDateTimeString(),
        ]);
        $this->apply('Anna', $selection);
        $this->apply('Bence', $selection);
        $ids = EventApplication::query()->where('event_id', $selection->id)->pluck('id')->all();
        $this->actingAs($this->ivan)->post("/events/{$selection->id}/applicants/select", ['selected' => $ids])
            ->assertSessionHasErrors('capacity');
        $this->assertSame(0, EventApplication::query()->where('event_id', $selection->id)->where('status', 'accepted')->count());
    }

    public function test_no_student_is_ever_credited_twice_whichever_route_is_used(): void
    {
        $event = $this->publish($this->ivan, $this->databases, ['capacity' => 5]);
        $this->apply('Anna', $event);
        $this->startEvent($event);

        $this->scanPassport($this->ivan, 'Anna', $event)->assertJsonPath('status', 'credited');

        // The passport again and a printed slip: both refused.
        $this->scanPassport($this->ivan, 'Anna', $event)->assertJsonPath('status', 'already_credited');
        $slip = $this->slipCode($this->ivan, $event);
        $this->actingAs($this->students['Anna'])->postJson('/qr/redeem', ['code' => $slip])
            ->assertStatus(409)->assertJsonPath('reason', 'already_credited');

        $this->assertSame(1, PointTransaction::query()->where('event_id', $event->id)->count());
        $this->assertSame(15, $this->points('Anna'));
    }

    public function test_the_ledger_only_grows_and_a_correction_is_a_reversal_row(): void
    {
        $event = $this->publish($this->ivan, $this->databases, ['capacity' => 5]);
        $this->apply('Anna', $event);
        $this->startEvent($event);
        $this->scanPassport($this->ivan, 'Anna', $event)->assertJsonPath('status', 'credited');
        $credit = PointTransaction::query()->firstOrFail();

        $this->assertThrows(fn () => $credit->update(['points' => 500]), LogicException::class);
        $this->assertThrows(fn () => $credit->delete(), LogicException::class);
        $this->assertSame(15, $credit->fresh()->points);

        // Only a System Admin may take points back, and only by adding a negative row.
        $ledger = app(PointLedger::class);
        $this->assertThrows(fn () => $ledger->reverse($credit, $this->ivan, 'Mistake'));
        $reversal = $ledger->reverse($credit, $this->admin, 'Credited by mistake');

        $this->assertSame(-15, $reversal->points);
        $this->assertSame($credit->id, $reversal->reversal_of_id);
        $this->assertSame(2, PointTransaction::count());
        $this->assertSame(0, $this->points('Anna'));
        $this->assertThrows(fn () => $ledger->reverse($credit, $this->admin, 'Again'));
    }

    public function test_archiving_a_branch_freezes_its_events_until_it_is_restored(): void
    {
        $lead = $this->makeUser(UserRole::Teacher, 'Branch lead');
        $this->assign($lead, $this->branchLead(), $this->informatics);
        $event = $this->publish($this->ivan, $this->databases, ['capacity' => 5]);
        $this->apply('Anna', $event);

        $this->actingAs($lead)->post("/topics/{$this->databases->id}/archive")->assertSessionHas('success');

        // Frozen: no new applications, nothing credited, and students no longer see it.
        $this->actingAs($this->students['Bence'])->postJson("/events/{$event->id}/apply")->assertStatus(422);
        $this->actingAs($this->students['Bence'])->get("/events/{$event->id}")->assertNotFound();
        $this->startEvent($event);
        $this->scanPassport($this->ivan, 'Anna', $event)->assertForbidden();
        $this->assertSame(0, PointTransaction::count());

        // Restoring the topic brings the event back exactly as it was.
        $this->actingAs($lead)->post("/topics/{$this->databases->id}/restore")->assertSessionHas('success');
        $this->scanPassport($this->ivan, 'Anna', $event)->assertOk()->assertJsonPath('status', 'credited');
    }
}

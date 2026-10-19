<?php

namespace Tests\Feature\Events;

use App\Enums\Capability;
use App\Enums\UserRole;
use App\Models\Event;
use App\Models\EventApplication;
use App\Models\Semester;
use App\Models\Topic;
use App\Models\TopicTag;
use App\Models\User;
use App\Notifications\EventApplicationDecisionNotification;
use App\Services\EventApplicationService;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Topics\TopicTestCase;

/**
 * Task #50: Student: My Events, withdrawal and waitlist promotion (UC-2.3).
 */
class StudentWithdrawalTest extends TopicTestCase
{
    private Topic $university;

    private Topic $faculty;

    private Topic $course;

    private User $teacher;

    private Semester $semester;

    private TopicTag $tag;

    private EventApplicationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->university = $this->topic('University');
        $this->faculty = $this->topic('Faculty of Informatics', $this->university);
        $this->course = $this->topic('Databases', $this->faculty);

        $this->teacher = $this->makeUser(UserRole::Teacher, 'Teacher One');
        $this->assign($this->teacher, $this->role('Teacher', [Capability::View, Capability::Create]), $this->course);

        $this->semester = Semester::create([
            'name' => '2026/27 autumn',
            'starts_at' => now()->subMonth()->toDateString(),
            'ends_at' => now()->addMonths(3)->toDateString(),
            'status' => 'active',
        ]);

        $this->tag = TopicTag::create([
            'name' => 'SQL Workshop',
            'category' => 'scientific',
            'is_active' => true,
        ]);

        $this->service = app(EventApplicationService::class);
    }

    private function createEvent(array $overrides = []): Event
    {
        return Event::create([
            'title' => 'SQL Workshop Event',
            'description' => 'Hands-on database workshop.',
            'category' => 'scientific',
            'topic_tag_id' => $this->tag->id,
            'created_by_id' => $this->teacher->id,
            'owning_topic_id' => $this->course->id,
            'audience_topic_id' => $this->course->id,
            'semester_id' => $this->semester->id,
            'capacity' => 1,
            'application_type' => 'fcfs',
            'points_value' => 20,
            'event_starts_at' => now()->addDays(5),
            'event_ends_at' => now()->addDays(5)->addHours(2),
            'application_deadline' => now()->addDays(3),
            'completion_deadline' => now()->addDays(6),
            'status' => Event::STATUS_PUBLISHED,
            'published_at' => now(),
            'is_hidden' => false,
            'reveal_at' => null,
            'qr_enabled' => true,
            ...$overrides,
        ]);
    }

    private function createAudienceStudent(string $neptun = 'STU001'): User
    {
        $student = $this->makeUser(UserRole::Student, "Student {$neptun}");
        $student->update(['neptun_code' => $neptun, 'status' => 'active']);
        $this->assign($student, $this->role('Student', [Capability::View, Capability::EventsView, Capability::EventsApply]), $this->course);

        return $student;
    }

    public function test_student_can_withdraw_from_accepted_application_before_event_starts(): void
    {
        $event = $this->createEvent(['capacity' => 1]);
        $student = $this->createAudienceStudent('STU001');

        $app = $this->service->apply($student, $event);
        $this->assertSame(EventApplication::ACCEPTED, $app->status);

        $response = $this->actingAs($student)->post(route('events.withdraw', $event));
        $response->assertSessionHas('success');

        $app->refresh();
        $this->assertSame(EventApplication::WITHDRAWN, $app->status);
        $this->assertNotNull($app->withdrawn_at);

        $this->assertDatabaseHas('event_application_transitions', [
            'event_application_id' => $app->id,
            'from_status' => EventApplication::ACCEPTED,
            'to_status' => EventApplication::WITHDRAWN,
            'actor_id' => $student->id,
            'reason' => 'Student withdrawal',
        ]);
    }

    public function test_student_can_withdraw_from_pending_application(): void
    {
        $event = $this->createEvent(['application_type' => 'teacher_select']);
        $student = $this->createAudienceStudent('STU001');

        $app = $this->service->apply($student, $event);
        $this->assertSame(EventApplication::PENDING, $app->status);

        $this->service->withdraw($student, $event);

        $app->refresh();
        $this->assertSame(EventApplication::WITHDRAWN, $app->status);
        $this->assertNotNull($app->withdrawn_at);

        $this->assertDatabaseHas('event_application_transitions', [
            'event_application_id' => $app->id,
            'from_status' => EventApplication::PENDING,
            'to_status' => EventApplication::WITHDRAWN,
            'actor_id' => $student->id,
            'reason' => 'Student withdrawal',
        ]);
    }

    public function test_student_can_withdraw_from_waitlisted_application(): void
    {
        $event = $this->createEvent(['capacity' => 1]);
        $student1 = $this->createAudienceStudent('STU001');
        $student2 = $this->createAudienceStudent('STU002');

        $this->service->apply($student1, $event); // accepted
        $app2 = $this->service->apply($student2, $event); // waitlisted
        $this->assertSame(EventApplication::WAITLISTED, $app2->status);

        $this->service->withdraw($student2, $event);

        $app2->refresh();
        $this->assertSame(EventApplication::WITHDRAWN, $app2->status);
    }

    public function test_fcfs_withdrawal_before_deadline_auto_promotes_oldest_waitlisted_student(): void
    {
        $event = $this->createEvent(['capacity' => 1, 'application_deadline' => now()->addDays(2)]);
        $student1 = $this->createAudienceStudent('STU001');
        $student2 = $this->createAudienceStudent('STU002');
        $student3 = $this->createAudienceStudent('STU003');

        $app1 = $this->service->apply($student1, $event);
        $this->travel(1)->minutes();
        $app2 = $this->service->apply($student2, $event);
        $this->travel(1)->minutes();
        $app3 = $this->service->apply($student3, $event);

        $this->assertSame(EventApplication::ACCEPTED, $app1->status);
        $this->assertSame(EventApplication::WAITLISTED, $app2->status);
        $this->assertSame(EventApplication::WAITLISTED, $app3->status);

        Notification::fake();

        // Student 1 withdraws before application deadline
        $this->service->withdraw($student1, $event);

        $app1->refresh();
        $app2->refresh();
        $app3->refresh();

        // Student 1 is withdrawn
        $this->assertSame(EventApplication::WITHDRAWN, $app1->status);

        // Student 2 (oldest waitlisted) is automatically promoted to accepted
        $this->assertSame(EventApplication::ACCEPTED, $app2->status);
        $this->assertNotNull($app2->decided_at);

        Notification::assertSentTo(
            $student2,
            EventApplicationDecisionNotification::class,
            fn (EventApplicationDecisionNotification $n) => $n->decision === EventApplicationDecisionNotification::ACCEPTED
                && $n->eventId === $event->id
        );
        Notification::assertNotSentTo($student3, EventApplicationDecisionNotification::class);

        // Audit transition recorded for promotion
        $this->assertDatabaseHas('event_application_transitions', [
            'event_application_id' => $app2->id,
            'from_status' => EventApplication::WAITLISTED,
            'to_status' => EventApplication::ACCEPTED,
            'reason' => 'Promoted from waitlist upon vacancy',
        ]);

        // Student 3 remains waitlisted
        $this->assertSame(EventApplication::WAITLISTED, $app3->status);
    }

    public function test_fcfs_withdrawal_after_deadline_does_not_promote_waitlisted_student(): void
    {
        $event = $this->createEvent([
            'capacity' => 1,
            'application_deadline' => now()->addDay(),
            'event_starts_at' => now()->addDays(3),
        ]);
        $student1 = $this->createAudienceStudent('STU001');
        $student2 = $this->createAudienceStudent('STU002');

        $app1 = $this->service->apply($student1, $event);
        $app2 = $this->service->apply($student2, $event);

        $this->assertSame(EventApplication::ACCEPTED, $app1->status);
        $this->assertSame(EventApplication::WAITLISTED, $app2->status);

        // Fast-forward to after application deadline, but before event start
        $this->travelTo(now()->addDays(2));

        $this->service->withdraw($student1, $event);

        $app1->refresh();
        $app2->refresh();

        $this->assertSame(EventApplication::WITHDRAWN, $app1->status);
        // Student 2 must NOT be promoted after application deadline (D09)
        $this->assertSame(EventApplication::WAITLISTED, $app2->status);
    }

    public function test_selection_mode_withdrawal_does_not_promote_waitlisted_student(): void
    {
        $event = $this->createEvent([
            'capacity' => 1,
            'application_type' => 'teacher_select',
            'application_deadline' => now()->addDays(2),
        ]);
        $student1 = $this->createAudienceStudent('STU001');
        $student2 = $this->createAudienceStudent('STU002');

        $app1 = $this->service->apply($student1, $event);
        $app2 = $this->service->apply($student2, $event);

        // Teacher accepted student 1 and waitlisted student 2
        $app1->update(['status' => EventApplication::ACCEPTED, 'decided_at' => now()]);
        $app2->update(['status' => EventApplication::WAITLISTED, 'decided_at' => now()]);

        // Student 1 withdraws
        $this->service->withdraw($student1, $event);

        $app1->refresh();
        $app2->refresh();

        $this->assertSame(EventApplication::WITHDRAWN, $app1->status);
        // In selection mode, no automatic promotion upon withdrawal (D08)
        $this->assertSame(EventApplication::WAITLISTED, $app2->status);
    }

    public function test_waitlisted_student_withdrawal_does_not_promote_anyone(): void
    {
        $event = $this->createEvent(['capacity' => 1]);
        $student1 = $this->createAudienceStudent('STU001');
        $student2 = $this->createAudienceStudent('STU002');
        $student3 = $this->createAudienceStudent('STU003');

        $app1 = $this->service->apply($student1, $event); // accepted
        $app2 = $this->service->apply($student2, $event); // waitlisted
        $app3 = $this->service->apply($student3, $event); // waitlisted

        // Student 2 (waitlisted) withdraws
        $this->service->withdraw($student2, $event);

        $app1->refresh();
        $app2->refresh();
        $app3->refresh();

        $this->assertSame(EventApplication::ACCEPTED, $app1->status);
        $this->assertSame(EventApplication::WITHDRAWN, $app2->status);
        $this->assertSame(EventApplication::WAITLISTED, $app3->status);
    }

    public function test_cannot_withdraw_after_event_starts(): void
    {
        $event = $this->createEvent([
            'event_starts_at' => now()->addDay(),
        ]);
        $student = $this->createAudienceStudent('STU001');
        $app = $this->service->apply($student, $event);

        // Move time past event start
        $this->travelTo(now()->addDays(2));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('You cannot withdraw from an event that has already started.');

        $this->service->withdraw($student, $event);
    }

    public function test_cannot_withdraw_if_already_credited(): void
    {
        $event = $this->createEvent();
        $student = $this->createAudienceStudent('STU001');
        $app = $this->service->apply($student, $event);

        // Mark application as scored/credited
        $app->update(['scored_at' => now()]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('You cannot withdraw from an event that has already been credited.');

        $this->service->withdraw($student, $event);
    }

    public function test_cannot_withdraw_if_already_withdrawn(): void
    {
        $event = $this->createEvent();
        $student = $this->createAudienceStudent('STU001');
        $this->service->apply($student, $event);

        $this->service->withdraw($student, $event);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('You have already withdrawn from this event.');

        $this->service->withdraw($student, $event);
    }

    public function test_cannot_withdraw_if_application_rejected(): void
    {
        $event = $this->createEvent(['application_type' => 'teacher_select']);
        $student = $this->createAudienceStudent('STU001');
        $app = $this->service->apply($student, $event);

        $app->update(['status' => EventApplication::REJECTED, 'decided_at' => now()]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('You cannot withdraw a rejected application.');

        $this->service->withdraw($student, $event);
    }

    public function test_withdrawn_student_can_reapply_before_deadline_and_is_placed_at_end_of_queue(): void
    {
        $event = $this->createEvent([
            'capacity' => 1,
            'application_deadline' => now()->addDays(2),
        ]);
        $student1 = $this->createAudienceStudent('STU001');
        $student2 = $this->createAudienceStudent('STU002');

        $app1 = $this->service->apply($student1, $event); // accepted
        $app2 = $this->service->apply($student2, $event); // waitlisted

        // Student 1 withdraws -> Student 2 is auto-promoted to accepted
        $this->service->withdraw($student1, $event);
        $app2->refresh();
        $this->assertSame(EventApplication::ACCEPTED, $app2->status);

        // Student 1 re-applies before deadline -> goes to waitlist
        $reappliedApp = $this->service->apply($student1, $event);
        $this->assertSame(EventApplication::WAITLISTED, $reappliedApp->status);
        $this->assertNull($reappliedApp->withdrawn_at);

        $this->assertDatabaseHas('event_application_transitions', [
            'event_application_id' => $reappliedApp->id,
            'from_status' => EventApplication::WITHDRAWN,
            'to_status' => EventApplication::WAITLISTED,
            'reason' => 'Re-applied after withdrawal',
        ]);
    }

    public function test_student_cannot_withdraw_another_students_application(): void
    {
        $event = $this->createEvent();
        $student1 = $this->createAudienceStudent('STU001');
        $student2 = $this->createAudienceStudent('STU002');

        $this->service->apply($student1, $event);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('You do not have an application for this event.');

        // Student 2 tries to withdraw for an event they never applied to
        $this->service->withdraw($student2, $event);
    }

    public function test_my_events_page_shows_only_students_own_applications(): void
    {
        $event1 = $this->createEvent(['title' => 'Databases SQL Workshop']);
        $event2 = $this->createEvent(['title' => 'Advanced Algorithms Night']);

        $student1 = $this->createAudienceStudent('STU001');
        $student2 = $this->createAudienceStudent('STU002');

        $this->service->apply($student1, $event1);
        $this->service->apply($student2, $event2);

        $this->actingAs($student1)
            ->get(route('events.mine'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Events/Applied')
                ->has('applications', 1)
                ->where('applications.0.event.title', 'Databases SQL Workshop')
            );
    }

    public function test_withdrawing_from_on_hold_event_is_allowed(): void
    {
        $event = $this->createEvent();
        $student = $this->createAudienceStudent('STU001');
        $app = $this->service->apply($student, $event);

        // Put event on hold
        $event->update(['status' => Event::STATUS_ON_HOLD]);

        // Student withdraws freely while on hold
        $this->service->withdraw($student, $event);

        $app->refresh();
        $this->assertSame(EventApplication::WITHDRAWN, $app->status);
    }
}

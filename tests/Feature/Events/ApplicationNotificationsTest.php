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
use App\Notifications\EventStatusChangedNotification;
use App\Services\EventApplicationService;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Topics\TopicTestCase;

/**
 * Task #53: every change to a student's application, and every cancel, hold or
 * restore of an event they applied to, sends one in-app notification and one email.
 */
class ApplicationNotificationsTest extends TopicTestCase
{
    private Topic $course;

    private User $teacher;

    private Semester $semester;

    private TopicTag $tag;

    private EventApplicationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->course = $this->topic('Databases', $this->topic('Faculty of Informatics'));

        $this->teacher = $this->makeUser(UserRole::Teacher, 'Teacher One');
        $this->assign($this->teacher, $this->role('Teacher', [Capability::View, Capability::EventsView, Capability::EventsManage]), $this->course);

        $this->semester = Semester::create([
            'name' => '2026/27 autumn',
            'starts_at' => now()->subMonth()->toDateString(),
            'ends_at' => now()->addMonths(3)->toDateString(),
            'status' => 'active',
        ]);

        $this->tag = TopicTag::create(['name' => 'SQL', 'category' => 'scientific', 'is_active' => true]);

        $this->service = app(EventApplicationService::class);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function event(array $overrides = []): Event
    {
        return Event::create([
            'title' => 'SQL Workshop',
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
            'qr_enabled' => true,
            ...$overrides,
        ]);
    }

    private function student(string $neptun): User
    {
        $student = $this->makeUser(UserRole::Student, "Student {$neptun}");
        $student->update(['neptun_code' => $neptun, 'status' => 'active']);
        $this->assign($student, $this->role('Student', [Capability::View, Capability::EventsView, Capability::EventsApply]), $this->course);

        return $student;
    }

    private function applicant(Event $event, string $status): User
    {
        $student = $this->makeUser(UserRole::Student);
        EventApplication::create(['event_id' => $event->id, 'student_id' => $student->id, 'status' => $status]);

        return $student;
    }

    public function test_fcfs_applicants_hear_whether_they_got_a_place_or_were_waitlisted(): void
    {
        Notification::fake();
        $event = $this->event(['capacity' => 1]);
        $first = $this->student('STU001');
        $second = $this->student('STU002');

        $this->service->apply($first, $event);
        $this->service->apply($second, $event);

        Notification::assertSentTo($first, EventApplicationDecisionNotification::class,
            fn ($n, array $channels) => $n->decision === EventApplicationDecisionNotification::CONFIRMED
                && $n->eventId === $event->id
                && $channels === ['mail', 'database']);
        Notification::assertSentTo($second, EventApplicationDecisionNotification::class,
            fn ($n, array $channels) => $n->decision === EventApplicationDecisionNotification::WAITLISTED
                && $channels === ['mail', 'database']);
        Notification::assertCount(2);
    }

    public function test_a_pending_application_in_selection_mode_sends_nothing_yet(): void
    {
        Notification::fake();
        $event = $this->event(['application_type' => 'teacher_select']);

        $this->service->apply($this->student('STU001'), $event);

        Notification::assertNothingSent();
    }

    public function test_a_refused_application_sends_nothing(): void
    {
        Notification::fake();
        $event = $this->event(['status' => Event::STATUS_ON_HOLD]);

        try {
            $this->service->apply($this->student('STU001'), $event);
            $this->fail('Applying to an event on hold should be refused.');
        } catch (ValidationException) {
        }

        Notification::assertNothingSent();
    }

    public function test_a_promotion_from_the_waitlist_is_its_own_message(): void
    {
        $event = $this->event(['capacity' => 1]);
        $first = $this->student('STU001');
        $second = $this->student('STU002');
        $this->service->apply($first, $event);
        $this->travel(1)->minutes();
        $this->service->apply($second, $event);

        Notification::fake();
        $this->service->withdraw($first, $event);

        Notification::assertSentTo($second, EventApplicationDecisionNotification::class,
            fn ($n) => $n->decision === EventApplicationDecisionNotification::PROMOTED);
        Notification::assertNotSentTo($first, EventApplicationDecisionNotification::class);
        Notification::assertCount(1);
    }

    public function test_hold_and_restore_tell_the_students_who_still_have_an_application(): void
    {
        $event = $this->event(['capacity' => 5]);
        $accepted = $this->applicant($event, EventApplication::ACCEPTED);
        $pending = $this->applicant($event, EventApplication::PENDING);
        $waitlisted = $this->applicant($event, EventApplication::WAITLISTED);
        $withdrawn = $this->applicant($event, EventApplication::WITHDRAWN);
        $rejected = $this->applicant($event, EventApplication::REJECTED);

        Notification::fake();
        $this->actingAs($this->teacher)->post("/events/{$event->id}/hold")->assertSessionHas('success');

        foreach ([$accepted, $pending, $waitlisted] as $student) {
            Notification::assertSentTo($student, EventStatusChangedNotification::class,
                fn ($n, array $channels) => $n->change === EventStatusChangedNotification::ON_HOLD
                    && $n->eventId === $event->id
                    && $channels === ['mail', 'database']);
        }
        Notification::assertNotSentTo($withdrawn, EventStatusChangedNotification::class);
        Notification::assertNotSentTo($rejected, EventStatusChangedNotification::class);
        Notification::assertCount(3);

        Notification::fake();
        $this->actingAs($this->teacher)->post("/events/{$event->id}/restore")->assertSessionHas('success');

        Notification::assertSentTo($accepted, EventStatusChangedNotification::class,
            fn ($n) => $n->change === EventStatusChangedNotification::RESTORED);
        Notification::assertCount(3);
    }

    public function test_cancelling_tells_applicants_why(): void
    {
        $event = $this->event(['capacity' => 5]);
        $accepted = $this->applicant($event, EventApplication::ACCEPTED);
        $withdrawn = $this->applicant($event, EventApplication::WITHDRAWN);

        Notification::fake();
        $this->actingAs($this->teacher)->post("/events/{$event->id}/cancel", ['reason' => '  Speaker is ill '])
            ->assertSessionHas('success');

        Notification::assertSentTo($accepted, EventStatusChangedNotification::class,
            fn ($n) => $n->change === EventStatusChangedNotification::CANCELLED && $n->reason === 'Speaker is ill');
        Notification::assertNotSentTo($withdrawn, EventStatusChangedNotification::class);
        Notification::assertCount(1);
    }

    public function test_a_refused_lifecycle_action_sends_nothing(): void
    {
        $event = $this->event(['capacity' => 5]);
        $this->applicant($event, EventApplication::ACCEPTED);

        Notification::fake();
        // Restoring an event that is not on hold, and cancelling without a reason, are refused.
        $this->actingAs($this->teacher)->post("/events/{$event->id}/restore")->assertSessionHas('error');
        $this->actingAs($this->teacher)->post("/events/{$event->id}/cancel", ['reason' => ''])->assertSessionHasErrors('reason');

        Notification::assertNothingSent();
    }

    public function test_each_change_stores_exactly_one_in_app_notification_and_sends_one_email(): void
    {
        $event = $this->event(['capacity' => 5]);
        $student = $this->student('STU001');
        $this->service->apply($student, $event);

        // A minute apart, so the order below is unambiguous.
        $this->travel(1)->minutes();
        $this->actingAs($this->teacher)->post("/events/{$event->id}/hold");
        $this->travel(1)->minutes();
        $this->actingAs($this->teacher)->post("/events/{$event->id}/restore");
        $this->travel(1)->minutes();
        $this->actingAs($this->teacher)->post("/events/{$event->id}/cancel", ['reason' => 'Room flooded']);

        $this->assertSame(
            ['confirmed', 'on_hold', 'restored', 'cancelled'],
            $student->notifications()->reorder('created_at')->get()->map(fn ($n) => $n->data['decision'] ?? $n->data['change'])->all()
        );
        $this->assertSame(4, $student->unreadNotifications()->count());
    }

    public function test_every_email_has_its_own_subject_and_names_the_event(): void
    {
        $student = $this->student('STU001');
        $subjects = [];

        foreach ([
            EventApplicationDecisionNotification::ACCEPTED,
            EventApplicationDecisionNotification::REJECTED,
            EventApplicationDecisionNotification::REMOVED,
            EventApplicationDecisionNotification::CONFIRMED,
            EventApplicationDecisionNotification::WAITLISTED,
            EventApplicationDecisionNotification::PROMOTED,
        ] as $decision) {
            $mail = (new EventApplicationDecisionNotification($decision, 1, 'SQL Workshop'))->toMail($student);
            $subjects[] = $mail->subject;
            $this->assertStringContainsString('SQL Workshop', implode(' ', $mail->introLines), $decision);
        }

        foreach ([
            EventStatusChangedNotification::CANCELLED,
            EventStatusChangedNotification::ON_HOLD,
            EventStatusChangedNotification::RESTORED,
        ] as $change) {
            $mail = (new EventStatusChangedNotification($change, 1, 'SQL Workshop', 'Speaker is ill'))->toMail($student);
            $subjects[] = $mail->subject;
            $this->assertStringContainsString('SQL Workshop', implode(' ', $mail->introLines), $change);
        }

        $this->assertCount(9, array_unique($subjects));

        $cancelled = (new EventStatusChangedNotification(EventStatusChangedNotification::CANCELLED, 1, 'SQL Workshop', 'Speaker is ill'))->toMail($student);
        $this->assertContains('Reason: Speaker is ill', $cancelled->introLines);
    }
}

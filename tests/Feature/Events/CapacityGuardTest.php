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
use App\Services\EventApplicationService;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Topics\TopicTestCase;

/**
 * Task #39: Backend Capacity Guard & Overbooking Protection.
 */
class CapacityGuardTest extends TopicTestCase
{
    private Topic $university;

    private Topic $faculty;

    private Topic $course;

    private Topic $otherCourse;

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
        $this->otherCourse = $this->topic('Programming', $this->faculty);

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
            'title' => 'SQL Night',
            'description' => 'Hands-on SQL practice.',
            'category' => 'scientific',
            'topic_tag_id' => $this->tag->id,
            'created_by_id' => $this->teacher->id,
            'owning_topic_id' => $this->course->id,
            'audience_topic_id' => $this->course->id,
            'semester_id' => $this->semester->id,
            'capacity' => 2,
            'application_type' => 'fcfs',
            'points_value' => 10,
            'event_starts_at' => now()->addDays(5),
            'event_ends_at' => now()->addDays(5)->addHours(2),
            'application_deadline' => now()->addDays(4),
            'completion_deadline' => now()->addDays(6),
            'status' => Event::STATUS_PUBLISHED,
            'published_at' => now(),
            'is_hidden' => false,
            'reveal_at' => null,
            'qr_enabled' => true,
            ...$overrides,
        ]);
    }

    private function createAudienceStudent(string $neptun = 'STU001', ?Topic $topic = null): User
    {
        $student = $this->makeUser(UserRole::Student, "Student {$neptun}");
        $student->update(['neptun_code' => $neptun, 'status' => 'active']);

        $targetTopic = $topic ?? $this->course;
        // Assign student to the audience topic node
        $this->assign($student, $this->role('Student', [Capability::View, Capability::EventsView, Capability::EventsApply]), $targetTopic);

        return $student;
    }

    public function test_fcfs_auto_accepts_when_seats_are_available(): void
    {
        $event = $this->createEvent(['capacity' => 2, 'application_type' => 'fcfs']);
        $student1 = $this->createAudienceStudent('STU001');
        $student2 = $this->createAudienceStudent('STU002');

        $app1 = $this->service->apply($student1, $event);
        $this->assertSame(EventApplication::ACCEPTED, $app1->status);
        $this->assertNotNull($app1->decided_at);
        $this->assertDatabaseHas('event_applications', [
            'event_id' => $event->id,
            'student_id' => $student1->id,
            'status' => 'accepted',
        ]);
        $this->assertDatabaseHas('event_application_transitions', [
            'event_application_id' => $app1->id,
            'from_status' => null,
            'to_status' => 'accepted',
            'actor_id' => $student1->id,
        ]);

        $app2 = $this->service->apply($student2, $event);
        $this->assertSame(EventApplication::ACCEPTED, $app2->status);
        $this->assertNotNull($app2->decided_at);
    }

    public function test_fcfs_auto_waitlists_when_capacity_is_reached(): void
    {
        $event = $this->createEvent(['capacity' => 2, 'application_type' => 'fcfs']);
        $student1 = $this->createAudienceStudent('STU001');
        $student2 = $this->createAudienceStudent('STU002');
        $student3 = $this->createAudienceStudent('STU003');

        $this->service->apply($student1, $event);
        $this->service->apply($student2, $event);

        // Third student exceeds capacity 2 -> should be waitlisted
        $app3 = $this->service->apply($student3, $event);

        $this->assertSame(EventApplication::WAITLISTED, $app3->status);
        $this->assertNull($app3->decided_at);
        $this->assertDatabaseHas('event_applications', [
            'event_id' => $event->id,
            'student_id' => $student3->id,
            'status' => 'waitlisted',
        ]);
        $this->assertDatabaseHas('event_application_transitions', [
            'event_application_id' => $app3->id,
            'from_status' => null,
            'to_status' => 'waitlisted',
            'actor_id' => $student3->id,
        ]);

        $this->assertSame(2, $event->applications()->where('status', EventApplication::ACCEPTED)->count());
        $this->assertSame(1, $event->applications()->where('status', EventApplication::WAITLISTED)->count());
    }

    public function test_teacher_select_mode_places_all_applicants_into_pending_regardless_of_capacity(): void
    {
        $event = $this->createEvent(['capacity' => 1, 'application_type' => 'teacher_select']);
        $student1 = $this->createAudienceStudent('STU001');
        $student2 = $this->createAudienceStudent('STU002');

        $app1 = $this->service->apply($student1, $event);
        $app2 = $this->service->apply($student2, $event);

        $this->assertSame(EventApplication::PENDING, $app1->status);
        $this->assertSame(EventApplication::PENDING, $app2->status);
        $this->assertNull($app1->decided_at);
        $this->assertNull($app2->decided_at);
        $this->assertSame(2, $event->applications()->where('status', EventApplication::PENDING)->count());
    }

    public function test_reapplication_after_withdrawal_moves_student_to_end_of_queue(): void
    {
        $event = $this->createEvent(['capacity' => 1, 'application_type' => 'fcfs']);
        $student1 = $this->createAudienceStudent('STU001');
        $student2 = $this->createAudienceStudent('STU002');

        // Student 1 gets the only seat
        $app1 = $this->service->apply($student1, $event);
        $this->assertSame(EventApplication::ACCEPTED, $app1->status);

        // Student 1 withdraws
        $app1->update([
            'status' => EventApplication::WITHDRAWN,
            'withdrawn_at' => now(),
        ]);

        // Student 2 applies and gets the vacated seat
        $app2 = $this->service->apply($student2, $event);
        $this->assertSame(EventApplication::ACCEPTED, $app2->status);

        // Student 1 re-applies -> capacity is 1 and full, so Student 1 is now waitlisted
        $reapplied = $this->service->apply($student1, $event);

        $this->assertSame($app1->id, $reapplied->id);
        $this->assertSame(EventApplication::WAITLISTED, $reapplied->status);
        $this->assertNull($reapplied->withdrawn_at);

        // Transition audit records the withdrawal -> waitlisted movement
        $this->assertDatabaseHas('event_application_transitions', [
            'event_application_id' => $app1->id,
            'from_status' => 'withdrawn',
            'to_status' => 'waitlisted',
            'actor_id' => $student1->id,
        ]);
        $this->assertSame(1, EventApplication::where('event_id', $event->id)->where('student_id', $student1->id)->count());
    }

    public function test_cannot_reapply_if_already_active(): void
    {
        $event = $this->createEvent(['capacity' => 2]);
        $student = $this->createAudienceStudent('STU001');

        $this->service->apply($student, $event);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('You have already applied to this event.');

        $this->service->apply($student, $event);
    }

    public function test_cannot_reapply_if_previously_rejected(): void
    {
        $event = $this->createEvent(['capacity' => 2]);
        $student = $this->createAudienceStudent('STU001');

        EventApplication::create([
            'event_id' => $event->id,
            'student_id' => $student->id,
            'status' => EventApplication::REJECTED,
            'applied_at' => now()->subDay(),
            'decided_at' => now(),
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Your application was previously rejected and cannot be resubmitted.');

        $this->service->apply($student, $event);
    }

    public function test_cannot_apply_to_draft_on_hold_completed_or_cancelled_events(): void
    {
        $student = $this->createAudienceStudent('STU001');

        foreach ([Event::STATUS_DRAFT, Event::STATUS_ON_HOLD, Event::STATUS_COMPLETED, Event::STATUS_CANCELLED] as $status) {
            $event = $this->createEvent(['status' => $status]);

            try {
                $this->service->apply($student, $event);
                $this->fail("Expected ValidationException for event with status {$status}");
            } catch (ValidationException $e) {
                $this->assertStringContainsString('This event is not open for applications.', $e->getMessage());
            }
        }
    }

    public function test_cannot_apply_after_application_deadline(): void
    {
        $event = $this->createEvent(['application_deadline' => now()->subMinute()]);
        $student = $this->createAudienceStudent('STU001');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('The application deadline for this event has passed.');

        $this->service->apply($student, $event);
    }

    public function test_cannot_apply_after_event_starts(): void
    {
        $event = $this->createEvent([
            'event_starts_at' => now()->subMinute(),
            'application_deadline' => now()->addHour(),
        ]);
        $student = $this->createAudienceStudent('STU001');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('This event has already started.');

        $this->service->apply($student, $event);
    }

    public function test_cannot_apply_to_unrevealed_surprise_event(): void
    {
        $event = $this->createEvent([
            'is_hidden' => true,
            'reveal_at' => now()->addHours(2),
        ]);
        $student = $this->createAudienceStudent('STU001');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('This surprise event has not been revealed yet.');

        $this->service->apply($student, $event);
    }

    public function test_can_apply_to_revealed_surprise_event(): void
    {
        $event = $this->createEvent([
            'is_hidden' => true,
            'reveal_at' => now()->subMinute(),
        ]);
        $student = $this->createAudienceStudent('STU001');

        $app = $this->service->apply($student, $event);
        $this->assertSame(EventApplication::ACCEPTED, $app->status);
    }

    public function test_cannot_apply_if_not_in_audience_scope(): void
    {
        // Event audience is Databases course
        $event = $this->createEvent(['audience_topic_id' => $this->course->id]);

        // Student is assigned only to Programming course
        $student = $this->createAudienceStudent('STU001', $this->otherCourse);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('You do not belong to the audience for this event.');

        $this->service->apply($student, $event);
    }

    public function test_cannot_apply_to_invitation_only_event(): void
    {
        $event = $this->createEvent(['audience_topic_id' => null]);
        $student = $this->createAudienceStudent('STU001');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('This event is by invitation only.');

        $this->service->apply($student, $event);
    }

    public function test_inactive_student_cannot_apply(): void
    {
        $event = $this->createEvent();
        $student = $this->createAudienceStudent('STU001');
        $student->update(['status' => 'inactive']);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Only active accounts may apply to events.');

        $this->service->apply($student, $event);
    }

    public function test_http_endpoint_applies_and_returns_correct_response(): void
    {
        $event = $this->createEvent(['capacity' => 1, 'application_type' => 'fcfs']);
        $student1 = $this->createAudienceStudent('STU001');
        $student2 = $this->createAudienceStudent('STU002');

        // Student 1 applies via JSON -> 201 accepted
        $response1 = $this->actingAs($student1)
            ->postJson(route('events.apply', $event))
            ->assertStatus(201)
            ->assertJson([
                'status' => 'accepted',
                'message' => 'Your application has been accepted!',
            ]);

        // Student 2 applies via JSON -> 201 waitlisted
        $response2 = $this->actingAs($student2)
            ->postJson(route('events.apply', $event))
            ->assertStatus(201)
            ->assertJson([
                'status' => 'waitlisted',
                'message' => 'The event is at capacity. You have been placed on the waitlist.',
            ]);

        $this->assertSame('accepted', $response1->json('application.status'));
        $this->assertSame('waitlisted', $response2->json('application.status'));
    }

    public function test_time_conflict_detection(): void
    {
        $eventA = $this->createEvent([
            'event_starts_at' => now()->addDays(5)->setTime(10, 0),
            'event_ends_at' => now()->addDays(5)->setTime(12, 0),
        ]);
        $eventOverlap = $this->createEvent([
            'event_starts_at' => now()->addDays(5)->setTime(11, 0),
            'event_ends_at' => now()->addDays(5)->setTime(13, 0),
        ]);
        $eventSeparate = $this->createEvent([
            'event_starts_at' => now()->addDays(5)->setTime(14, 0),
            'event_ends_at' => now()->addDays(5)->setTime(16, 0),
        ]);

        $student = $this->createAudienceStudent('STU001');
        $this->service->apply($student, $eventA);

        $this->assertTrue($this->service->hasTimeConflict($student, $eventOverlap));
        $this->assertFalse($this->service->hasTimeConflict($student, $eventSeparate));
    }

    public function test_student_without_neptun_code_cannot_apply(): void
    {
        $event = $this->createEvent();
        $student = $this->makeUser(UserRole::Student, 'No Neptun Student');
        $student->update(['neptun_code' => null, 'status' => 'active']);
        $this->assign($student, $this->role('Student', [Capability::View, Capability::EventsView, Capability::EventsApply]), $this->course);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('A valid Neptun code is required to apply to events.');

        $this->service->apply($student, $event);
    }

    public function test_revealed_surprise_event_bypasses_application_deadline_before_start(): void
    {
        // Event where application deadline passed, but it is a revealed surprise event (D02)
        $event = $this->createEvent([
            'is_hidden' => true,
            'reveal_at' => now()->subHour(),
            'application_deadline' => now()->subMinutes(30),
            'event_starts_at' => now()->addHour(),
        ]);
        $student = $this->createAudienceStudent('STU001');

        $application = $this->service->apply($student, $event);

        $this->assertSame(EventApplication::ACCEPTED, $application->status);
    }

    public function test_cannot_apply_when_topic_is_archived(): void
    {
        $event = $this->createEvent();
        $this->course->update(['archived_at' => now()]);
        $student = $this->createAudienceStudent('STU001');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Applications are frozen because this topic is archived.');

        $this->service->apply($student, $event);
    }
}

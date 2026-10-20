<?php

namespace Tests\Feature\Events;

use App\Enums\Capability;
use App\Enums\UserRole;
use App\Models\Event;
use App\Models\EventApplication;
use App\Models\PointTransaction;
use App\Models\Semester;
use App\Models\Topic;
use App\Models\TopicTag;
use App\Models\User;
use App\Services\EventCreditService;
use App\Services\QrPassport;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Topics\TopicTestCase;

/**
 * Task #43: Organizer QR Passport scanner and attendance crediting (UC-1.7).
 *
 * Verifies:
 * - Access control via Capability::PointsCredit at owning topic
 * - Real-time attendance verification and point crediting
 * - Single-award ledger invariant (D06)
 * - Anti-Self-Dealing guardrail: organizers cannot credit themselves (D04)
 * - Verification of accepted application status (UC-1.7)
 * - Manual Neptun search fallback for cracked screens (Meeting Q13)
 * - Event lifecycle guards (draft, on hold, cancelled)
 */
class QrPassportScannerTest extends TopicTestCase
{
    private Topic $faculty;

    private Topic $course;

    private Topic $otherCourse;

    private User $teacher;

    private User $otherTeacher;

    private Semester $semester;

    private TopicTag $tag;

    protected function setUp(): void
    {
        parent::setUp();

        $university = $this->topic('University');
        $this->faculty = $this->topic('Faculty of Informatics', $university);
        $this->course = $this->topic('Databases', $this->faculty);
        $this->otherCourse = $this->topic('Programming', $this->faculty);

        // Teacher holds Capability::PointsCredit on $this->course
        $this->teacher = $this->makeUser(UserRole::Teacher, 'Teacher One');
        $creditRole = $this->role('Organizer', [Capability::View, Capability::EventsView, Capability::PointsCredit]);
        $this->assign($this->teacher, $creditRole, $this->course);

        // Other teacher only has access on $this->otherCourse
        $this->otherTeacher = $this->makeUser(UserRole::Teacher, 'Teacher Two');
        $this->assign($this->otherTeacher, $creditRole, $this->otherCourse);

        $this->semester = Semester::create([
            'name' => '2026/27 autumn',
            'starts_at' => now()->subMonth()->toDateString(),
            'ends_at' => now()->addMonths(3)->toDateString(),
            'status' => 'active',
        ]);

        $this->tag = TopicTag::create(['name' => 'SQL', 'category' => 'academic', 'is_active' => true]);
    }

    private function makeEvent(array $overrides = []): Event
    {
        return Event::create([
            'title' => 'Advanced Database Workshop',
            'description' => 'Hands-on practice session.',
            'category' => 'academic',
            'topic_tag_id' => $this->tag->id,
            'created_by_id' => $this->teacher->id,
            'owning_topic_id' => $this->course->id,
            'audience_topic_id' => $this->course->id,
            'semester_id' => $this->semester->id,
            'capacity' => 10,
            'application_type' => 'fcfs',
            'points_value' => 15,
            'event_starts_at' => now()->subHour(),
            'event_ends_at' => now()->addHours(2),
            'application_deadline' => now()->subDay(),
            'completion_deadline' => now()->addDays(3),
            'status' => Event::STATUS_PUBLISHED,
            'published_at' => now()->subDays(2),
            ...$overrides,
        ]);
    }

    private function makeStudent(string $neptun = 'STU001', string $name = 'Student One'): User
    {
        $student = $this->makeUser(UserRole::Student, $name);
        $student->update(['neptun_code' => $neptun]);
        $this->assign($student, $this->viewer(), $this->course);

        return $student;
    }

    private function makeAcceptedApplication(Event $event, User $student): EventApplication
    {
        return EventApplication::create([
            'event_id' => $event->id,
            'student_id' => $student->id,
            'status' => EventApplication::ACCEPTED,
            'applied_at' => now()->subDays(2),
            'decided_at' => now()->subDay(),
            'decided_by_id' => $this->teacher->id,
        ]);
    }

    public function test_organizer_with_points_credit_capability_can_access_scanner_page(): void
    {
        $event = $this->makeEvent();
        $student = $this->makeStudent('NEP001', 'Alice');
        $this->makeAcceptedApplication($event, $student);

        $response = $this->actingAs($this->teacher)
            ->get("/events/{$event->id}/scan");

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Events/Scan')
            ->has('event', fn (Assert $e) => $e
                ->where('id', $event->id)
                ->where('title', 'Advanced Database Workshop')
                ->where('points_value', 15)
                ->where('category', 'academic')
                ->etc()
            )
            ->has('stats', fn (Assert $s) => $s
                ->where('accepted_count', 1)
                ->where('credited_count', 0)
            )
            ->has('students', 1)
            ->where('students.0.id', $student->id)
            ->where('students.0.name', 'Alice')
            ->where('students.0.neptun_code', 'NEP001')
            ->where('students.0.is_credited', false)
        );
    }

    public function test_user_without_credit_capability_is_forbidden_from_scanner_page_and_endpoint(): void
    {
        $event = $this->makeEvent();
        $student = $this->makeStudent();

        // 1. Regular student is redirected from web page with error, forbidden on JSON API
        $this->actingAs($student)
            ->get("/events/{$event->id}/scan")
            ->assertRedirect('/')
            ->assertSessionHas('error');

        $this->actingAs($student)
            ->postJson("/events/{$event->id}/credit", ['identifier' => $student->neptun_code])
            ->assertForbidden();

        // 2. Teacher on another course without capability here is redirected from web page, forbidden on JSON API
        $this->actingAs($this->otherTeacher)
            ->get("/events/{$event->id}/scan")
            ->assertRedirect('/')
            ->assertSessionHas('error');

        $this->actingAs($this->otherTeacher)
            ->postJson("/events/{$event->id}/credit", ['identifier' => $student->neptun_code])
            ->assertForbidden();
    }

    public function test_organizer_with_inherited_capability_from_ancestor_topic_can_scan_and_credit(): void
    {
        $event = $this->makeEvent();
        $student = $this->makeStudent('DEANSTU', 'Dean Student');
        $this->makeAcceptedApplication($event, $student);

        // Faculty lead assigned PointsCredit at ancestor topic ($this->faculty)
        $facultyLead = $this->makeUser(UserRole::Teacher, 'Faculty Lead');
        $creditRole = $this->role('Faculty Organizer', [Capability::View, Capability::EventsView, Capability::PointsCredit]);
        $this->assign($facultyLead, $creditRole, $this->faculty);

        $this->actingAs($facultyLead)
            ->get("/events/{$event->id}/scan")
            ->assertOk();

        $response = $this->actingAs($facultyLead)
            ->postJson("/events/{$event->id}/credit", ['identifier' => 'DEANSTU']);

        $response->assertOk()
            ->assertJson([
                'status' => 'credited',
                'points' => 15,
                'student' => [
                    'id' => $student->id,
                    'name' => 'Dean Student',
                    'neptun_code' => 'DEANSTU',
                ],
            ]);
    }

    public function test_system_admin_is_authorized_at_service_level(): void
    {
        $event = $this->makeEvent();
        $student = $this->makeStudent('ADMSTU', 'Admin Student');
        $this->makeAcceptedApplication($event, $student);

        $result = app(EventCreditService::class)->credit($this->admin, $event, 'ADMSTU');

        $this->assertSame('credited', $result['status']);
        $this->assertSame(15, $result['points']);
    }

    public function test_scanning_a_signed_qr_passport_successfully_credits_points(): void
    {
        $event = $this->makeEvent();
        $student = $this->makeStudent('NEP101', 'Bob Martin');
        $application = $this->makeAcceptedApplication($event, $student);

        // What the student's "My QR passport" page shows (Task #52).
        $passport = app(QrPassport::class)->issue($student);

        $response = $this->actingAs($this->teacher)
            ->postJson("/events/{$event->id}/credit", [
                'identifier' => $passport,
                'source' => 'passport',
            ]);

        $response->assertOk()
            ->assertJson([
                'status' => 'credited',
                'points' => 15,
                'student' => [
                    'id' => $student->id,
                    'name' => 'Bob Martin',
                    'neptun_code' => 'NEP101',
                ],
                'credited_count' => 1,
            ]);

        // Verify ledger entry
        $this->assertDatabaseHas('point_transactions', [
            'student_id' => $student->id,
            'event_id' => $event->id,
            'semester_id' => $event->semester_id,
            'credited_by_id' => $this->teacher->id,
            'points' => 15,
            'type' => 'credit',
            'source' => 'passport',
            'category' => 'academic',
        ]);

        // Verify application completed and scored timestamps
        $application->refresh();
        $this->assertNotNull($application->scored_at);
        $this->assertNotNull($application->completed_at);
    }

    public function test_scanning_accepted_student_via_neptun_code(): void
    {
        $event = $this->makeEvent();
        $student = $this->makeStudent('NEP202', 'Charlie');
        $this->makeAcceptedApplication($event, $student);

        $response = $this->actingAs($this->teacher)
            ->postJson("/events/{$event->id}/credit", [
                'identifier' => 'nep202', // case-insensitive
            ]);

        $response->assertOk()
            ->assertJson([
                'status' => 'credited',
                'points' => 15,
                'student' => [
                    'id' => $student->id,
                    'name' => 'Charlie',
                    'neptun_code' => 'NEP202',
                ],
            ]);

        $this->assertDatabaseHas('point_transactions', [
            'student_id' => $student->id,
            'event_id' => $event->id,
            'points' => 15,
        ]);
    }

    public function test_a_json_payload_may_name_a_neptun_code_but_not_an_id(): void
    {
        $event = $this->makeEvent();
        $student = $this->makeStudent('NEP303', 'Diana');
        $this->makeAcceptedApplication($event, $student);

        // An id can be made up by anyone, so it is not accepted from a scan (Task #52).
        $this->actingAs($this->teacher)
            ->postJson("/events/{$event->id}/credit", ['identifier' => json_encode(['student_id' => $student->id])])
            ->assertOk()
            ->assertJson(['status' => 'not_found']);

        $this->actingAs($this->teacher)
            ->postJson("/events/{$event->id}/credit", ['identifier' => json_encode(['neptun_code' => 'nep303'])])
            ->assertOk()
            ->assertJson([
                'status' => 'credited',
                'points' => 15,
                'student' => [
                    'id' => $student->id,
                ],
            ]);
    }

    public function test_manual_search_fallback_credits_with_source_manual(): void
    {
        $event = $this->makeEvent();
        $student = $this->makeStudent('NEP404', 'Edward');
        $this->makeAcceptedApplication($event, $student);

        $response = $this->actingAs($this->teacher)
            ->postJson("/events/{$event->id}/credit", [
                'identifier' => (string) $student->id,
                'source' => 'manual',
            ]);

        $response->assertOk()
            ->assertJson([
                'status' => 'credited',
                'points' => 15,
            ]);

        $this->assertDatabaseHas('point_transactions', [
            'student_id' => $student->id,
            'event_id' => $event->id,
            'source' => 'manual',
        ]);
    }

    public function test_scanning_same_student_twice_is_rejected_as_already_credited(): void
    {
        $event = $this->makeEvent();
        $student = $this->makeStudent('NEP505', 'Fiona');
        $this->makeAcceptedApplication($event, $student);

        // First scan
        $first = $this->actingAs($this->teacher)
            ->postJson("/events/{$event->id}/credit", ['identifier' => 'NEP505']);
        $first->assertOk()->assertJson(['status' => 'credited']);

        // Second scan
        $second = $this->actingAs($this->teacher)
            ->postJson("/events/{$event->id}/credit", ['identifier' => 'NEP505']);

        $second->assertOk()
            ->assertJson([
                'status' => 'already_credited',
                'message' => 'Points have already been credited to Fiona for this event.',
                'student' => [
                    'id' => $student->id,
                    'name' => 'Fiona',
                ],
            ]);

        // Ledger invariant: exactly 1 transaction exists
        $this->assertSame(1, PointTransaction::query()->where('event_id', $event->id)->where('student_id', $student->id)->count());
    }

    public function test_organizer_cannot_credit_themselves_per_d04(): void
    {
        $event = $this->makeEvent();

        // Give teacher a Neptun code and an accepted application
        $this->teacher->update(['neptun_code' => 'TEACH1']);
        $this->makeAcceptedApplication($event, $this->teacher);

        $response = $this->actingAs($this->teacher)
            ->postJson("/events/{$event->id}/credit", [
                'identifier' => 'TEACH1',
            ]);

        $response->assertOk()
            ->assertJson([
                'status' => 'self_credit',
                'message' => 'Points cannot be credited to the person who authorised them.',
                'student' => [
                    'id' => $this->teacher->id,
                    'name' => $this->teacher->name,
                ],
            ]);

        $this->assertSame(0, PointTransaction::query()->where('event_id', $event->id)->count());
    }

    public function test_scanning_unregistered_or_non_accepted_student_is_rejected(): void
    {
        $event = $this->makeEvent();

        // 1. Student without any application
        $unregistered = $this->makeStudent('UNREG1', 'Unregistered User');

        $res1 = $this->actingAs($this->teacher)
            ->postJson("/events/{$event->id}/credit", ['identifier' => 'UNREG1']);

        $res1->assertOk()
            ->assertJson([
                'status' => 'not_accepted',
                'message' => 'Unregistered User is not registered or selected for this event.',
            ]);

        // 2. Student with waitlisted application
        $waitlistedStudent = $this->makeStudent('WAIT01', 'Waitlisted User');
        EventApplication::create([
            'event_id' => $event->id,
            'student_id' => $waitlistedStudent->id,
            'status' => EventApplication::WAITLISTED,
            'applied_at' => now(),
        ]);

        $res2 = $this->actingAs($this->teacher)
            ->postJson("/events/{$event->id}/credit", ['identifier' => 'WAIT01']);

        $res2->assertOk()
            ->assertJson([
                'status' => 'not_accepted',
                'message' => 'Waitlisted User is not registered or selected for this event.',
            ]);

        // 3. Student with withdrawn application
        $withdrawnStudent = $this->makeStudent('WITH01', 'Withdrawn User');
        EventApplication::create([
            'event_id' => $event->id,
            'student_id' => $withdrawnStudent->id,
            'status' => EventApplication::WITHDRAWN,
            'applied_at' => now()->subDay(),
            'withdrawn_at' => now(),
        ]);

        $res3 = $this->actingAs($this->teacher)
            ->postJson("/events/{$event->id}/credit", ['identifier' => 'WITH01']);

        $res3->assertOk()
            ->assertJson([
                'status' => 'not_accepted',
            ]);

        $this->assertSame(0, PointTransaction::query()->count());
    }

    public function test_crediting_refused_for_draft_on_hold_or_cancelled_events(): void
    {
        $student = $this->makeStudent('STU999');

        // 1. Draft event
        $draftEvent = $this->makeEvent(['status' => Event::STATUS_DRAFT, 'published_at' => null]);
        $this->makeAcceptedApplication($draftEvent, $student);

        $this->actingAs($this->teacher)
            ->postJson("/events/{$draftEvent->id}/credit", ['identifier' => 'STU999'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['event']);

        // 2. On-hold event
        $onHoldEvent = $this->makeEvent(['status' => Event::STATUS_ON_HOLD]);
        $this->makeAcceptedApplication($onHoldEvent, $student);

        $this->actingAs($this->teacher)
            ->postJson("/events/{$onHoldEvent->id}/credit", ['identifier' => 'STU999'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['event']);

        // 3. Cancelled event
        $cancelledEvent = $this->makeEvent(['status' => Event::STATUS_CANCELLED]);
        $this->makeAcceptedApplication($cancelledEvent, $student);

        $this->actingAs($this->teacher)
            ->postJson("/events/{$cancelledEvent->id}/credit", ['identifier' => 'STU999'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['event']);
    }

    public function test_scanning_nonexistent_student_returns_not_found(): void
    {
        $event = $this->makeEvent();

        $response = $this->actingAs($this->teacher)
            ->postJson("/events/{$event->id}/credit", ['identifier' => 'NONEXISTENT']);

        $response->assertOk()
            ->assertJson([
                'status' => 'not_found',
                'message' => 'No student found matching the scanned QR code or input.',
            ]);
    }

    public function test_scanner_page_lists_students_with_credited_status(): void
    {
        $event = $this->makeEvent();
        $student1 = $this->makeStudent('S111', 'Student One');
        $student2 = $this->makeStudent('S222', 'Student Two');

        $this->makeAcceptedApplication($event, $student1);
        $this->makeAcceptedApplication($event, $student2);

        // Credit student1
        $this->actingAs($this->teacher)
            ->postJson("/events/{$event->id}/credit", ['identifier' => 'S111']);

        // Visit scan page
        $response = $this->actingAs($this->teacher)
            ->get("/events/{$event->id}/scan");

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Events/Scan')
            ->where('stats.accepted_count', 2)
            ->where('stats.credited_count', 1)
            ->where('students.0.neptun_code', 'S111')
            ->where('students.0.is_credited', true)
            ->where('students.1.neptun_code', 'S222')
            ->where('students.1.is_credited', false)
        );
    }

    public function test_crediting_refused_if_event_has_not_started_yet(): void
    {
        $futureEvent = $this->makeEvent([
            'event_starts_at' => now()->addDay(),
            'event_ends_at' => now()->addDay()->addHours(2),
        ]);
        $student = $this->makeStudent('FUT01', 'Future Student');
        $this->makeAcceptedApplication($futureEvent, $student);

        $this->actingAs($this->teacher)
            ->postJson("/events/{$futureEvent->id}/credit", ['identifier' => 'FUT01'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['event']);
    }

    public function test_crediting_refused_if_completion_deadline_has_passed(): void
    {
        $expiredEvent = $this->makeEvent([
            'event_starts_at' => now()->subDays(5),
            'event_ends_at' => now()->subDays(5)->addHours(2),
            'completion_deadline' => now()->subMinute(),
        ]);
        $student = $this->makeStudent('EXP01', 'Expired Student');
        $this->makeAcceptedApplication($expiredEvent, $student);

        $this->actingAs($this->teacher)
            ->postJson("/events/{$expiredEvent->id}/credit", ['identifier' => 'EXP01'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['event']);
    }
}

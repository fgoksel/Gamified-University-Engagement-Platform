<?php

namespace Tests\Feature\Events;

use App\Enums\Capability;
use App\Enums\UserRole;
use App\Models\Event;
use App\Models\Semester;
use App\Models\Topic;
use App\Models\User;
use App\Services\EventPlacement;
use App\Services\RoleService;
use App\Services\TopicAccess;
use App\Services\TopicService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Topics\TopicTestCase;

/**
 * Task #65: the event capabilities and the rules that use them.
 */
class EventCapabilitiesTest extends TopicTestCase
{
    private Topic $faculty;

    private Topic $course;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculty = $this->topic('Faculty', $this->topic('University'));
        $this->course = $this->topic('Databases', $this->faculty);
        $this->event = $this->makeEvent();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function makeEvent(array $data = []): Event
    {
        return Event::factory()->create([
            'owning_topic_id' => $this->course->id,
            'audience_topic_id' => $this->course->id,
            ...$data,
        ]);
    }

    /**
     * A person holding exactly these capabilities (plus viewing) at a topic.
     *
     * @param  list<Capability>  $capabilities
     */
    private function staff(array $capabilities, ?Topic $at = null, UserRole $account = UserRole::Teacher): User
    {
        $user = $this->makeUser($account);
        $this->assign($user, $this->role('Role '.uniqid(), [Capability::View, Capability::EventsView, ...$capabilities]), $at ?? $this->course);

        return $user;
    }

    private function student(): User
    {
        $student = $this->makeUser(UserRole::Student);
        $student->forceFill(['neptun_code' => strtoupper(substr(md5(uniqid()), 0, 6))])->save();

        return $student;
    }

    private function can(User $user, string $ability, ?Event $event = null): bool
    {
        return Gate::forUser($user)->allows($ability, $event ?? $this->event);
    }

    public function test_the_registry_lists_the_event_capabilities_with_their_prerequisites(): void
    {
        $registry = collect(Capability::registry())->keyBy('value');

        foreach (['events.view', 'events.apply', 'events.create', 'events.manage', 'applications.manage', 'points.credit', 'qr_codes.manage'] as $value) {
            $this->assertTrue($registry->has($value), $value);
        }

        $this->assertEqualsCanonicalizing(['topic.view'], $registry['events.view']['requires']);
        $this->assertEqualsCanonicalizing(['topic.view', 'events.view'], $registry['events.apply']['requires']);
        $this->assertEqualsCanonicalizing(['topic.view', 'events.view'], $registry['events.create']['requires']);
        $this->assertEqualsCanonicalizing(['topic.view', 'events.view', 'events.create'], $registry['events.manage']['requires']);
        $this->assertEqualsCanonicalizing(['topic.view', 'events.view'], $registry['applications.manage']['requires']);
        $this->assertEqualsCanonicalizing(['topic.view', 'events.view'], $registry['points.credit']['requires']);
        $this->assertEqualsCanonicalizing(['topic.view', 'events.view', 'points.credit'], $registry['qr_codes.manage']['requires']);
    }

    public function test_a_role_cannot_be_saved_without_the_prerequisites_of_its_event_capabilities(): void
    {
        $this->expectException(ValidationException::class);

        app(RoleService::class)->create($this->admin, $this->course, [
            'name' => 'Broken',
            'capabilities' => ['topic.view', 'events.manage'],
            'delegable' => [],
        ]);
    }

    public function test_a_valid_event_role_can_be_defined_and_passed_on_within_the_ceiling(): void
    {
        $role = app(RoleService::class)->create($this->admin, $this->course, [
            'name' => 'Event manager',
            'capabilities' => ['topic.view', 'events.view', 'events.create', 'events.manage'],
            'delegable' => ['topic.view', 'events.view'],
        ]);

        $this->assertSame(['topic.view', 'events.view', 'events.create', 'events.manage'], $role->capabilityList());
    }

    public function test_each_capability_opens_only_its_own_action(): void
    {
        $matrix = [
            'manage' => [Capability::EventsManage, Capability::EventsCreate],
            'manageApplicants' => [Capability::ApplicationsManage],
            'credit' => [Capability::PointsCredit],
            'manageQr' => [Capability::QrCodesManage, Capability::PointsCredit],
        ];

        foreach ($matrix as $ability => $capabilities) {
            $user = $this->staff($capabilities);

            foreach (array_keys($matrix) as $other) {
                // The QR capability needs credit to be saved in a role, so it comes with it.
                $expected = $other === $ability || ($ability === 'manageQr' && $other === 'credit');
                $this->assertSame($expected, $this->can($user, $other), "{$ability} -> {$other}");
            }
        }
    }

    public function test_the_creator_has_no_authority_without_a_capability(): void
    {
        $creator = $this->makeUser();
        $event = $this->makeEvent(['created_by_id' => $creator->id]);

        foreach (['staff', 'manage', 'credit', 'manageQr', 'manageApplicants'] as $ability) {
            $this->assertFalse($this->can($creator, $ability, $event), $ability);
        }
    }

    public function test_a_teacher_account_gives_no_event_authority_by_itself(): void
    {
        // The account role "teacher" carries the old account-wide permissions, but they open nothing here.
        $teacher = $this->makeUser(UserRole::Teacher);

        $this->assertFalse(Gate::forUser($teacher)->allows('create', Event::class));
        foreach (['staff', 'manage', 'credit', 'manageQr', 'manageApplicants', 'view'] as $ability) {
            $this->assertFalse($this->can($teacher, $ability), $ability);
        }
    }

    public function test_authority_is_inherited_from_ancestors_and_not_from_siblings(): void
    {
        $dean = $this->staff([Capability::PointsCredit], $this->faculty);
        $sibling = $this->topic('Networks', $this->faculty);
        $labTeacher = $this->staff([Capability::PointsCredit], $sibling);

        $this->assertTrue($this->can($dean, 'credit'));
        $this->assertFalse($this->can($labTeacher, 'credit'));
    }

    public function test_system_admin_can_run_every_event(): void
    {
        foreach (['staff', 'manage', 'credit', 'manageQr', 'manageApplicants', 'view'] as $ability) {
            $this->assertTrue($this->can($this->admin, $ability), $ability);
        }
        $this->assertTrue(Gate::forUser($this->admin)->allows('create', Event::class));
    }

    public function test_an_archived_topic_freezes_event_management(): void
    {
        $user = $this->staff([Capability::PointsCredit, Capability::QrCodesManage]);
        $this->assertTrue($this->can($user, 'credit'));

        app(TopicService::class)->archive($this->admin, $this->faculty);

        foreach (['staff', 'credit', 'manageQr', 'view'] as $ability) {
            $this->assertFalse($this->can($user, $ability), $ability);
        }
    }

    public function test_students_see_published_revealed_events_of_their_audience_only(): void
    {
        $student = $this->student();
        $this->assign($student, $this->role('Student', [Capability::View, Capability::EventsView, Capability::EventsApply]), $this->course);

        $this->assertTrue($this->can($student, 'view'));

        $this->assertFalse($this->can($student, 'view', $this->makeEvent(['status' => 'draft'])), 'draft');
        $this->assertFalse($this->can($student, 'view', $this->makeEvent(['status' => 'on_hold'])), 'on hold');
        $this->assertFalse($this->can($student, 'view', $this->makeEvent(['is_hidden' => true])), 'hidden');
        $this->assertTrue($this->can($student, 'view', $this->makeEvent(['is_hidden' => true, 'reveal_at' => now()->subMinute()])), 'revealed');
        $this->assertFalse($this->can($student, 'view', $this->makeEvent(['audience_topic_id' => null])), 'invitation only');
        $this->assertFalse($this->can($student, 'view', $this->makeEvent(['audience_topic_id' => $this->topic('Elsewhere')->id])), 'other audience');
    }

    public function test_staff_see_drafts_and_hidden_events_students_do_not(): void
    {
        $staff = $this->staff([Capability::EventsCreate]);
        $hidden = $this->makeEvent(['is_hidden' => true, 'status' => 'draft']);

        $this->assertTrue($this->can($staff, 'view', $hidden));
        $this->assertFalse($this->can($this->student(), 'view', $hidden));
    }

    public function test_applying_needs_the_apply_capability_in_the_audience_and_a_neptun_code(): void
    {
        $apply = $this->role('Applicant', [Capability::View, Capability::EventsView, Capability::EventsApply]);
        $student = $this->student();
        $this->assign($student, $apply, $this->course);
        $this->assertTrue($this->can($student, 'apply'));

        $viewOnly = $this->student();
        $this->assign($viewOnly, $this->role('Observer', [Capability::View, Capability::EventsView]), $this->course);
        $this->assertTrue($this->can($viewOnly, 'view'));
        $this->assertFalse($this->can($viewOnly, 'apply'));

        $noNeptun = $this->student();
        $noNeptun->forceFill(['neptun_code' => null])->save();
        $this->assign($noNeptun, $apply, $this->course);
        $this->assertFalse($this->can($noNeptun, 'apply'));

        $this->assertFalse($this->can($student, 'apply', $this->makeEvent(['status' => 'cancelled'])));
    }

    public function test_an_inactive_account_gets_nothing(): void
    {
        $user = $this->staff([Capability::PointsCredit, Capability::EventsCreate]);
        $user->forceFill(['status' => 'inactive'])->save();
        app(TopicAccess::class)->flush();

        $this->assertFalse($this->can($user, 'credit'));
        $this->assertFalse(Gate::forUser($user)->allows('create', Event::class));
    }

    public function test_creating_events_follows_events_create_not_topic_create(): void
    {
        $topicCreateOnly = $this->makeUser(UserRole::Teacher);
        $this->assign($topicCreateOnly, $this->role('Adds topics', [Capability::View, Capability::Create]), $this->course);

        $this->assertCount(0, app(EventPlacement::class)->owningTopics($topicCreateOnly));
        $this->actingAs($topicCreateOnly)->get('/events/create')->assertRedirect('/');

        $organiser = $this->staff([Capability::EventsCreate]);
        $this->assertSame([$this->course->id], app(EventPlacement::class)->owningTopics($organiser)->pluck('id')->all());
        $this->actingAs($organiser)->get('/events/create')->assertOk();
    }

    public function test_a_student_account_with_the_capability_can_create_events_but_a_teacher_without_it_cannot(): void
    {
        $tutor = $this->staff([Capability::EventsCreate], account: UserRole::Student);
        $plainTeacher = $this->makeUser(UserRole::Teacher);

        $this->actingAs($tutor)->get('/events/create')->assertOk();
        $this->actingAs($plainTeacher)->get('/events/create')->assertRedirect('/');
    }

    public function test_the_create_endpoint_refuses_a_person_without_the_capability(): void
    {
        Semester::create(['name' => 'Autumn', 'starts_at' => now()->subMonth()->toDateString(), 'ends_at' => now()->addMonths(3)->toDateString(), 'status' => 'active']);
        $plainTeacher = $this->makeUser(UserRole::Teacher);

        $this->actingAs($plainTeacher)->postJson('/events', ['action' => 'draft', 'title' => 'x'])->assertForbidden();
        $this->assertSame(1, Event::count());
    }
}

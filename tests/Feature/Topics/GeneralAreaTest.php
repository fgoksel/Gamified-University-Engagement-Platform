<?php

namespace Tests\Feature\Topics;

use App\Enums\Capability;
use App\Enums\UserRole;
use App\Filament\Pages\OrganizerManagement;
use App\Filament\Pages\StudentManagement;
use App\Jobs\JoinGeneralAreaJob;
use App\Models\RoleAssignment;
use App\Models\RoleDefinition;
use App\Models\Topic;
use App\Models\TopicAuditEvent;
use App\Models\User;
use App\Services\AssignmentService;
use App\Services\TopicService;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Livewire;

/**
 * Task #133 (#128 Q3): a student whose account becomes active joins every general area with its
 * role, and no other area. Course areas still need the import.
 */
class GeneralAreaTest extends TopicTestCase
{
    private Topic $university;

    private Topic $faculty;

    private Topic $sibling;

    private Topic $course;

    private RoleDefinition $member;

    private User $areaAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->university = $this->topic('University');
        $this->faculty = $this->topic('Faculty of Informatics', $this->university);
        $this->sibling = $this->topic('Faculty of Sport', $this->university);
        $this->course = $this->topic('Databases', $this->faculty);
        $this->member = $this->role('Member', [Capability::View, Capability::EventsView, Capability::EventsApply]);

        // The area admin of the faculty: may give roles and import students there (N1).
        $this->areaAdmin = $this->makeUser(UserRole::Teacher, 'Faculty Admin');
        $this->assign($this->areaAdmin, $this->role('Faculty admin', [
            Capability::View, Capability::AccessView, Capability::AccessAssign, Capability::AccessImport,
            Capability::EventsView, Capability::EventsApply,
        ]), $this->faculty);
    }

    private function makeGeneral(Topic $topic, ?RoleDefinition $role = null): void
    {
        app(TopicService::class)->setGeneral($this->admin, $topic, $role ?? $this->member);
    }

    private function invitedStudent(string $token = 'token-1', UserRole $role = UserRole::Student): User
    {
        $user = $this->makeUser($role, 'New Student', 'invited');
        $user->forceFill([
            'activation_token' => $token,
            'activation_token_expires_at' => now()->addDay(),
            'must_change_password' => true,
            'email_verified_at' => null,
        ])->save();

        return $user;
    }

    private function activateByLink(string $token = 'token-1'): void
    {
        $this->post('/auth/set-password', ['token' => $token, 'password' => 'new-password', 'password_confirmation' => 'new-password'])
            ->assertRedirect(route('login'));
    }

    /**
     * @return list<string> titles of the areas where the person holds the role
     */
    private function areasOf(User $user, ?RoleDefinition $role = null): array
    {
        return RoleAssignment::query()->active()
            ->where(['user_id' => $user->id, 'role_definition_id' => ($role ?? $this->member)->id])
            ->join('topics', 'topics.id', '=', 'role_assignments.topic_id')
            ->orderBy('topics.title')
            ->pluck('topics.title')
            ->all();
    }

    private function save(Topic $topic, ?RoleDefinition $role, ?User $as = null, bool $addActive = false)
    {
        return $this->actingAs($as ?? $this->areaAdmin)->put("/topics/{$topic->id}/general", [
            'role_definition_id' => $role?->id,
            'add_active' => $addActive,
        ]);
    }

    // ─── Joining ──────────────────────────────────────────────────────────────

    public function test_the_activation_link_adds_the_student_to_each_general_area_and_no_other(): void
    {
        $this->makeGeneral($this->university);
        $this->makeGeneral($this->faculty);
        $student = $this->invitedStudent();

        // Nothing while the account is only invited.
        $this->assertSame([], $this->areasOf($student));

        $this->activateByLink();

        $this->assertSame(['Faculty of Informatics', 'University'], $this->areasOf($student));
        // The sibling and the course area are not general: they stay empty.
        $this->assertSame(0, RoleAssignment::query()->whereIn('topic_id', [$this->sibling->id, $this->course->id])->count());

        // The assignment says how it came.
        $assignment = RoleAssignment::query()->where(['user_id' => $student->id, 'topic_id' => $this->university->id])->sole();
        $this->assertSame('general_area', $assignment->authority_snapshot['via']);
        $this->assertSame($this->admin->id, $assignment->granted_by_id);
        $this->assertTrue(TopicAuditEvent::query()->where('action', 'assignment.joined_general_area')->exists());
    }

    public function test_the_filament_activate_adds_the_student_and_a_held_role_is_not_doubled(): void
    {
        $this->makeGeneral($this->university);
        $student = $this->makeUser(UserRole::Student, 'Back Again', 'inactive');
        $student->forceFill(['email_verified_at' => now(), 'must_change_password' => false, 'neptun_code' => 'BACK01'])->save();
        // They already hold the role from before.
        $this->assign($student, $this->member, $this->university);

        Livewire::actingAs($this->admin)
            ->test(StudentManagement::class)
            ->callTableAction('activate', $student)
            ->assertHasNoTableActionErrors();

        $this->assertSame('active', $student->fresh()->status);
        $this->assertSame(1, RoleAssignment::query()->active()->where(['user_id' => $student->id, 'topic_id' => $this->university->id])->count());

        // A second student without the role gets it the same way.
        $other = $this->makeUser(UserRole::Student, 'Other', 'inactive');
        $other->forceFill(['email_verified_at' => now(), 'must_change_password' => false, 'neptun_code' => 'OTHE01'])->save();
        Livewire::actingAs($this->admin)->test(StudentManagement::class)->callTableAction('activate', $other);
        $this->assertSame(['University'], $this->areasOf($other));
    }

    public function test_an_organiser_account_that_becomes_active_joins_nothing(): void
    {
        $this->makeGeneral($this->university);
        $organiser = $this->invitedStudent('token-org', UserRole::Teacher);
        $this->activateByLink('token-org');
        $this->assertSame([], $this->areasOf($organiser));

        $teacher = $this->makeUser(UserRole::Teacher, 'Teacher', 'inactive');
        $teacher->forceFill(['email_verified_at' => now(), 'must_change_password' => false])->save();
        Livewire::actingAs($this->admin)->test(OrganizerManagement::class)->callTableAction('activate', $teacher);

        $this->assertSame('active', $teacher->fresh()->status);
        $this->assertSame(0, RoleAssignment::query()->where('user_id', $teacher->id)->count());
    }

    public function test_an_archived_general_area_adds_nobody_and_turning_off_removes_nobody(): void
    {
        $this->makeGeneral($this->faculty);
        $this->makeGeneral($this->sibling);
        $first = $this->invitedStudent('token-a');
        $this->activateByLink('token-a');
        $this->assertSame(['Faculty of Informatics', 'Faculty of Sport'], $this->areasOf($first));

        // The sibling is archived, the faculty turned off.
        app(TopicService::class)->archive($this->admin, $this->sibling->fresh());
        $this->save($this->faculty, null)->assertSessionHasNoErrors()
            ->assertSessionHas('success', '"Faculty of Informatics" is no longer a general area. Nobody was removed.');

        $second = $this->invitedStudent('token-b');
        $this->activateByLink('token-b');

        $this->assertSame([], $this->areasOf($second));
        // The first student keeps what they got.
        $this->assertSame(['Faculty of Informatics', 'Faculty of Sport'], $this->areasOf($first));
    }

    // ─── Switching ────────────────────────────────────────────────────────────

    public function test_an_area_admin_switches_it_on_and_the_access_tab_shows_it(): void
    {
        $this->actingAs($this->areaAdmin)->get("/topics/{$this->faculty->id}?tab=access")
            ->assertInertia(fn (Assert $page) => $page
                ->where('selected.can.set_general', true)
                ->where('selected.access.general.role', null));

        $this->save($this->faculty, $this->member)
            ->assertRedirect("/topics/{$this->faculty->id}?tab=access")
            ->assertSessionHas('success', '"Faculty of Informatics" is a general area: every student whose account becomes active joins as "Member".');

        $faculty = $this->faculty->fresh();
        $this->assertSame($this->member->id, $faculty->general_role_id);
        $this->assertSame($this->areaAdmin->id, $faculty->general_set_by_id);
        $this->assertNotNull($faculty->general_set_at);
        $this->assertTrue(TopicAuditEvent::query()->where(['action' => 'topic.general_on', 'topic_id' => $this->faculty->id])->exists());

        $this->actingAs($this->areaAdmin)->get("/topics/{$this->faculty->id}?tab=access")
            ->assertInertia(fn (Assert $page) => $page
                ->where('selected.access.general.role', ['id' => $this->member->id, 'name' => 'Member'])
                ->where('selected.access.general.set_by', 'Faculty Admin'));
    }

    public function test_only_give_a_role_and_import_or_a_system_admin_may_switch_it(): void
    {
        // Import only, no Give a role.
        $importer = $this->makeUser(UserRole::Teacher, 'Importer');
        $this->assign($importer, $this->role('Importer', [Capability::View, Capability::AccessView, Capability::AccessImport, Capability::EventsView, Capability::EventsApply]), $this->faculty);
        $this->save($this->faculty, $this->member, $importer)
            ->assertRedirect('/')
            ->assertSessionHas('error', 'You do not have permission to view this page.');

        // Cannot see the area at all.
        $outsider = $this->makeUser(UserRole::Teacher, 'Outsider');
        $this->assign($outsider, $this->role('Sport admin', [Capability::View, Capability::AccessView, Capability::AccessAssign, Capability::AccessImport]), $this->sibling);
        $this->save($this->faculty, $this->member, $outsider)->assertNotFound();

        // A role with powers the area admin may not pass on.
        $strong = $this->role('Manager', [Capability::View, Capability::EventsView, Capability::EventsManage]);
        $this->save($this->faculty, $strong)->assertSessionHasErrors('role_definition_id');

        $this->assertNull($this->faculty->fresh()->general_role_id);

        // The system admin may, anywhere.
        $this->save($this->university, $this->member, $this->admin)->assertSessionHasNoErrors();
        $this->assertSame($this->member->id, $this->university->fresh()->general_role_id);
    }

    public function test_active_students_are_added_only_when_ticked_and_exactly_those_missing(): void
    {
        $active = collect(range(1, 3))->map(fn (int $i) => $this->makeUser(UserRole::Student, "Active {$i}"));
        $alreadyIn = $this->makeUser(UserRole::Student, 'Already In');
        $this->assign($alreadyIn, $this->member, $this->faculty);
        $invited = $this->makeUser(UserRole::Student, 'Invited', 'invited');
        $teacher = $this->makeUser(UserRole::Teacher, 'Teacher');

        // Without the tick: nobody joins.
        $this->save($this->faculty, $this->member)->assertSessionHasNoErrors();
        $this->assertSame(1, RoleAssignment::query()->active()->where('topic_id', $this->faculty->id)->where('role_definition_id', $this->member->id)->count());

        // The dialog's live count: the 3 active students who are not in yet.
        $this->actingAs($this->areaAdmin)->get("/topics/{$this->faculty->id}?tab=access")
            ->assertInertia(fn (Assert $page) => $page->where("selected.access.general.missing.{$this->member->id}", 3));

        $this->save($this->faculty, $this->member, addActive: true)
            ->assertSessionHas('success', '"Faculty of Informatics" is a general area: every student whose account becomes active joins as "Member". 3 active students are being added now.');

        foreach ($active as $student) {
            $this->assertSame(['Faculty of Informatics'], $this->areasOf($student));
        }
        $this->assertSame(1, RoleAssignment::query()->active()->where(['user_id' => $alreadyIn->id, 'topic_id' => $this->faculty->id])->count());
        $this->assertSame([], $this->areasOf($invited));
        $this->assertSame([], $this->areasOf($teacher));
    }

    // ─── Only plain student roles (review of #133) ───────────────────────────

    public function test_only_a_plain_student_role_can_be_the_general_role(): void
    {
        // Roles the area admin may hand out, but with more than a student's powers.
        $areaAdminRole = $this->role('Area admin', [Capability::View, Capability::AccessView, Capability::AccessAssign, Capability::AccessImport, Capability::EventsView, Capability::EventsApply]);
        $organiser = $this->role('Event organiser', [Capability::View, Capability::EventsView, Capability::EventsCreate]);
        $student = $this->role('Event student', [Capability::View, Capability::EventsView, Capability::EventsApply]);

        foreach ([$areaAdminRole, $organiser] as $role) {
            $this->actingAs($this->areaAdmin)
                ->putJson("/topics/{$this->faculty->id}/general", ['role_definition_id' => $role->id])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['role_definition_id' => 'A general area can only give a student role: one that can see the area and its events and apply to events, nothing more.']);

            $this->assertNull($this->faculty->fresh()->general_role_id);
        }

        $this->save($this->faculty, $student)->assertSessionHasNoErrors();
        $this->assertSame($student->id, $this->faculty->fresh()->general_role_id);
    }

    public function test_the_dialog_offers_only_plain_student_roles(): void
    {
        $this->role('Area admin', [Capability::View, Capability::AccessView, Capability::AccessAssign, Capability::AccessImport, Capability::EventsView, Capability::EventsApply]);
        $this->role('Viewer only', [Capability::View]);
        $this->role('Nothing', []);

        $this->actingAs($this->areaAdmin)->get("/topics/{$this->faculty->id}?tab=access")
            ->assertInertia(fn (Assert $page) => $page
                ->where('selected.access.general.roles', fn ($roles) => collect($roles)->pluck('name')->sort()->values()->all() === ['Member', 'Viewer only'])
                // The import dialog keeps its full list.
                ->where('selected.access.import.roles', fn ($roles) => collect($roles)->pluck('name')->contains('Area admin')));

        $this->assertFalse(RoleDefinition::where('name', 'Nothing')->sole()->isPlainStudentRole());
    }

    public function test_a_role_that_later_gains_powers_is_no_longer_given(): void
    {
        $this->makeGeneral($this->faculty);

        // Someone edits the role afterwards and adds a power.
        $this->member->forceFill(['capabilities' => [...$this->member->capabilities, Capability::EventsCreate->value]])->save();

        $student = $this->invitedStudent();
        $this->activateByLink();

        $this->assertSame([], $this->areasOf($student));
    }

    public function test_a_skipped_general_area_writes_one_log_line_with_no_person_in_it(): void
    {
        $this->makeGeneral($this->faculty);
        $students = collect(range(1, 4))->map(fn (int $i) => $this->makeUser(UserRole::Student, "Active {$i}"));

        // The role gains a power after the area was switched on.
        $this->member->forceFill(['capabilities' => [...$this->member->capabilities, Capability::EventsCreate->value]])->save();

        Log::spy();
        (new JoinGeneralAreaJob($this->faculty->id))->handle(app(AssignmentService::class));

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context) => $context === [
            'topic_id' => $this->faculty->id,
            'role_definition_id' => $this->member->id,
        ]);
        foreach ($students as $student) {
            $this->assertSame([], $this->areasOf($student));
        }
    }
}

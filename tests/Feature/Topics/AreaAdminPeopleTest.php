<?php

namespace Tests\Feature\Topics;

use App\Enums\Capability;
use App\Enums\UserRole;
use App\Models\RoleAssignment;
use App\Models\RoleDefinition;
use App\Models\Topic;
use App\Models\User;
use App\Notifications\OrganizerInvitationNotification;
use App\Notifications\StudentInvitationNotification;
use Filament\Notifications\DatabaseNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Task #104: an area admin adds people without the admin panel (N1, 9 November). "Import
 * students" creates the accounts that are missing when the file has Full Name and Email, and
 * "Invite a person" adds one student or organiser. Both create accounts the way the admin panel
 * does (StudentAccounts, OrganizerAccounts) and give the role with the rights of an import.
 */
class AreaAdminPeopleTest extends TopicTestCase
{
    private Topic $university;

    private Topic $faculty;

    private Topic $course;

    private RoleDefinition $student;

    private RoleDefinition $organiser;

    private User $areaAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Notification::fake();

        $this->university = $this->topic('University');
        $this->faculty = $this->topic('Faculty of Informatics', $this->university);
        $this->course = $this->topic('Databases', $this->faculty);

        $this->student = $this->role('Student', [Capability::View, Capability::EventsView, Capability::EventsApply]);
        $this->organiser = $this->role('Organiser', [Capability::View, Capability::EventsView, Capability::EventsCreate]);

        // The area admin of the faculty: the faculty is the top of their own branch.
        $this->areaAdmin = $this->makeUser(UserRole::Teacher, 'Faculty Admin');
        $this->assign($this->areaAdmin, $this->role('Faculty admin', [
            Capability::View, Capability::AccessView, Capability::AccessImport,
            Capability::EventsView, Capability::EventsApply, Capability::EventsCreate,
        ]), $this->faculty);
    }

    /**
     * @return list<string> titles of the areas where the person has the role
     */
    private function placesOf(User $user, ?RoleDefinition $role = null): array
    {
        return RoleAssignment::query()->active()
            ->where(['user_id' => $user->id, 'role_definition_id' => ($role ?? $this->student)->id])
            ->join('topics', 'topics.id', '=', 'role_assignments.topic_id')
            ->orderBy('topics.title')
            ->pluck('topics.title')
            ->all();
    }

    /**
     * The import result the area admin was sent (notifications are faked here).
     */
    private function lastResult(): string
    {
        return (string) (Notification::sent($this->areaAdmin, DatabaseNotification::class)->last()?->data['body'] ?? '');
    }

    private function import(string $csv, array $parents = []): void
    {
        $this->actingAs($this->areaAdmin)->post("/topics/{$this->course->id}/imports", [
            'file' => UploadedFile::fake()->createWithContent('students.csv', $csv),
            'role_definition_id' => $this->student->id,
            'parent_topic_ids' => array_map(fn (Topic $t) => $t->id, $parents),
        ])->assertSessionHasNoErrors();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function invite(array $data, ?User $as = null, ?Topic $topic = null)
    {
        return $this->actingAs($as ?? $this->areaAdmin)->post('/topics/'.($topic ?? $this->course)->id.'/invitations', [
            'name' => 'Nora New',
            'email' => 'nora.new@student.pte.hu',
            'account_type' => 'student',
            'neptun_code' => 'nor001',
            'role_definition_id' => $this->student->id,
            'parent_topic_ids' => [],
            ...$data,
        ]);
    }

    /**
     * An area admin of the faculty who may also give roles (Give a role), so organisers too.
     */
    private function leadWhoMayGiveRoles(): User
    {
        $lead = $this->makeUser(UserRole::Teacher, 'Faculty Lead');
        $this->assign($lead, $this->role('Faculty lead', [
            Capability::View, Capability::AccessView, Capability::AccessAssign, Capability::AccessImport,
            Capability::EventsView, Capability::EventsApply, Capability::EventsCreate,
        ]), $this->faculty);

        return $lead;
    }

    private function account(string $neptun, string $email, string $status = 'active', UserRole $role = UserRole::Student): User
    {
        $user = $this->makeUser($role, "Person {$neptun}", $status);
        $user->forceFill(['neptun_code' => $neptun, 'email' => $email])->save();

        return $user;
    }

    // ─── Import creates missing accounts ──────────────────────────────────────

    public function test_an_import_with_full_name_and_email_creates_invited_accounts_with_the_role_here_and_above(): void
    {
        $anna = $this->account('ANNA01', 'anna@student.pte.hu');

        $this->import("Full Name,Email,Neptun Code\nAnna Old,anna@student.pte.hu,ANNA01\nBela New,Bela.New@student.pte.hu,bel002\n", [$this->faculty]);

        $bela = User::where('neptun_code', 'BEL002')->firstOrFail();
        $this->assertSame('Bela New', $bela->name);
        $this->assertSame('bela.new@student.pte.hu', $bela->email);
        $this->assertSame('invited', $bela->status);
        $this->assertTrue($bela->must_change_password);
        $this->assertTrue($bela->hasRole(UserRole::Student));
        $this->assertTrue($bela->hasValidActivationToken());
        $this->assertTrue($bela->activation_token_expires_at->lte(now()->addHours(24)));
        Notification::assertSentTo($bela, StudentInvitationNotification::class,
            fn (StudentInvitationNotification $n) => $n->activationToken === $bela->activation_token && $n->expiresInHours === 24);

        // Both the existing and the new student are in the course and the ticked faculty.
        $this->assertSame(['Databases', 'Faculty of Informatics'], $this->placesOf($anna));
        $this->assertSame(['Databases', 'Faculty of Informatics'], $this->placesOf($bela));
        Notification::assertNotSentTo($anna, StudentInvitationNotification::class);

        $result = $this->lastResult();
        $this->assertStringContainsString('2 student(s) now have the role "Student" in Databases.', $result);
        $this->assertStringContainsString('1 new account(s) were created and invited.', $result);
        $this->assertStringContainsString('Also added to Faculty of Informatics: 2.', $result);
    }

    public function test_rows_that_cannot_become_an_account_are_skipped_with_a_reason(): void
    {
        $this->account('TAKEN1', 'taken@student.pte.hu');

        $this->import(implode("\n", [
            'Full Name,Email,Neptun Code',
            // Someone else's email address
            'Copy Cat,taken@student.pte.hu,COPY01',
            // Not a Neptun code
            'Short Code,short@student.pte.hu,AB1',
            // No email
            'No Mail,,NOMA01',
            // No name or email: the hint
            ',,EMPTY1',
        ])."\n");

        $result = $this->lastResult();
        $this->assertStringContainsString('COPY01: This email address is already registered', $result);
        $this->assertStringContainsString('AB1: ', $result);
        $this->assertStringContainsString(__('jobs.students.the_neptun_code_must'), $result);
        $this->assertStringContainsString('NOMA01: '.__('jobs.students.invalid_email_address_format'), $result);
        $this->assertStringContainsString('no student account has the Neptun code EMPTY1. Add the columns Full Name and Email to create it.', $result);
        $this->assertStringContainsString('4 row(s) were skipped', $result);

        $this->assertSame(0, User::whereIn('neptun_code', ['COPY01', 'AB1', 'NOMA01', 'EMPTY1'])->count());
        Notification::assertNothingSentTo(User::where('email', 'taken@student.pte.hu')->first());
    }

    public function test_a_file_with_only_neptun_codes_works_as_before(): void
    {
        $anna = $this->account('ANNA01', 'anna@student.pte.hu');

        $this->import("Neptun Code\nANNA01\nZZZ999\n");

        $this->assertSame(['Databases'], $this->placesOf($anna));
        $this->assertNull(User::where('neptun_code', 'ZZZ999')->first());
        $result = $this->lastResult();
        $this->assertStringContainsString('1 student(s) now have the role "Student" in Databases.', $result);
        $this->assertStringContainsString('no student account has the Neptun code ZZZ999. Add the columns Full Name and Email to create it.', $result);
        $this->assertStringNotContainsString('created and invited', $result);
    }

    // ─── Invite a person ──────────────────────────────────────────────────────

    public function test_the_access_tab_offers_the_invitation_to_those_who_may_import(): void
    {
        $this->actingAs($this->areaAdmin)->get("/topics/{$this->course->id}?tab=access")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('selected.can.import', true)
                ->where('selected.access.import.parents', [['id' => $this->faculty->id, 'title' => 'Faculty of Informatics']]));
    }

    public function test_an_area_admin_invites_a_student_with_the_role_here_and_above(): void
    {
        $this->invite(['parent_topic_ids' => [$this->faculty->id]])
            ->assertRedirect("/topics/{$this->course->id}?tab=access")
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'An invitation was sent to nora.new@student.pte.hu. They have the role "Student" in Databases once they activate the account.');

        $nora = User::where('email', 'nora.new@student.pte.hu')->firstOrFail();
        $this->assertSame('NOR001', $nora->neptun_code);
        $this->assertSame('invited', $nora->status);
        $this->assertTrue($nora->hasRole(UserRole::Student));
        $this->assertSame(['Databases', 'Faculty of Informatics'], $this->placesOf($nora));
        Notification::assertSentTo($nora, StudentInvitationNotification::class);

        // The Access tab shows the person as invited.
        $this->actingAs($this->areaAdmin)->get("/topics/{$this->course->id}?tab=access")
            ->assertInertia(fn (Assert $page) => $page
                ->where('selected.access.here', fn ($rows) => collect($rows)->contains(
                    fn ($row) => $row['user']['name'] === 'Nora New' && $row['user']['invited'] === true && $row['user']['active'] === false,
                )));
    }

    public function test_inviting_an_organiser_also_needs_the_right_to_give_roles(): void
    {
        $otto = [
            'name' => 'Otto Organiser',
            'email' => 'otto@pte.hu',
            'account_type' => 'organiser',
            'neptun_code' => '',
            'role_definition_id' => $this->organiser->id,
        ];

        // Import only: students yes (see above), organisers no, and the dialog offers only "Student".
        $this->actingAs($this->areaAdmin)->get("/topics/{$this->course->id}?tab=access")
            ->assertInertia(fn (Assert $page) => $page->where('selected.can.invite_organisers', false));
        $this->invite($otto)
            ->assertRedirect('/')
            ->assertSessionHas('error', 'You do not have permission to view this page.');
        $this->assertNull(User::where('email', 'otto@pte.hu')->first());

        // Import and Give a role: organisers too.
        $lead = $this->leadWhoMayGiveRoles();
        $this->actingAs($lead)->get("/topics/{$this->course->id}?tab=access")
            ->assertInertia(fn (Assert $page) => $page->where('selected.can.invite_organisers', true));
        $this->invite($otto, $lead)->assertSessionHasNoErrors();

        $otto = User::where('email', 'otto@pte.hu')->firstOrFail();
        $this->assertNull($otto->neptun_code);
        $this->assertSame('invited', $otto->status);
        $this->assertTrue($otto->hasRole(UserRole::Teacher));
        $this->assertFalse($otto->hasRole(UserRole::Student));
        $this->assertSame(['Databases'], $this->placesOf($otto, $this->organiser));
        Notification::assertSentTo($otto, OrganizerInvitationNotification::class);
    }

    public function test_a_student_needs_a_valid_neptun_code(): void
    {
        $this->invite(['neptun_code' => ''])->assertSessionHasErrors(['neptun_code' => 'Enter the Neptun code of the student.']);
        $this->invite(['neptun_code' => 'AB1'])->assertSessionHasErrors(['neptun_code' => 'The Neptun code is 6 letters or digits.']);

        $this->assertNull(User::where('email', 'nora.new@student.pte.hu')->first());
    }

    public function test_someone_with_an_account_gets_the_role_when_the_email_is_theirs(): void
    {
        $anna = $this->account('ANNA01', 'anna@student.pte.hu');

        $this->invite(['name' => 'Anna', 'email' => 'Anna@student.pte.hu', 'neptun_code' => 'anna01'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Person ANNA01 already has an account, so no invitation was sent. They now have the role "Student" in Databases.');

        $this->assertSame(['Databases'], $this->placesOf($anna));
        $this->assertSame(1, User::where('neptun_code', 'ANNA01')->count());
        Notification::assertNothingSentTo($anna);

        // Asked again: they have it already.
        $this->invite(['email' => 'anna@student.pte.hu', 'neptun_code' => 'ANNA01'])
            ->assertSessionHasErrors('email');
    }

    public function test_a_neptun_code_typo_never_gives_the_role_to_someone_else(): void
    {
        $anna = $this->account('ANNA01', 'anna@student.pte.hu');

        // A new person's email, but Anna's code: refused, and Anna is named so it can be checked.
        $this->invite(['email' => 'nora.new@student.pte.hu', 'neptun_code' => 'anna01'])
            ->assertSessionHasErrors(['neptun_code' => 'This Neptun code belongs to Person ANNA01 (anna@student.pte.hu). Check the code, or type that person\'s email to give them the role.']);

        // Anna's email with a code that is not hers.
        $this->invite(['email' => 'anna@student.pte.hu', 'neptun_code' => 'ANNA99'])
            ->assertSessionHasErrors(['neptun_code' => 'This email address belongs to Person ANNA01, whose account has a different Neptun code. Check the code.']);

        $this->assertSame([], $this->placesOf($anna));
        $this->assertNull(User::where('email', 'nora.new@student.pte.hu')->first());
        $this->assertNull(User::where('neptun_code', 'ANNA99')->first());
        Notification::assertNothingSent();
    }

    public function test_a_deactivated_account_or_two_different_accounts_are_refused(): void
    {
        $this->account('OFF001', 'off@student.pte.hu', 'inactive');
        $this->account('BEA002', 'bea@student.pte.hu');

        $this->invite(['email' => 'off@student.pte.hu', 'neptun_code' => 'OFF001'])
            ->assertSessionHasErrors(['email' => 'Person OFF001 has an account, but it is deactivated. A deactivated account cannot be given a role.']);

        $this->invite(['email' => 'bea@student.pte.hu', 'neptun_code' => 'OFF001'])
            ->assertSessionHasErrors(['email' => 'This email address and this Neptun code belong to two different accounts. Check both.']);

        $this->assertSame(0, RoleAssignment::query()->where('role_definition_id', $this->student->id)->count());
    }

    public function test_without_the_import_right_it_is_refused_and_an_area_one_cannot_see_is_not_found(): void
    {
        // Sees the access list of the faculty, may not import.
        $viewer = $this->makeUser(UserRole::Teacher, 'Viewer');
        $this->assign($viewer, $this->role('Access viewer', [Capability::View, Capability::AccessView]), $this->faculty);
        // A refusal (403) sends the person home with a message, like everywhere in the app.
        $this->invite([], $viewer)
            ->assertRedirect('/')
            ->assertSessionHas('error', 'You do not have permission to view this page.');

        // Holds nothing in this branch at all.
        $other = $this->topic('Faculty of Law', $this->university);
        $outsider = $this->makeUser(UserRole::Teacher, 'Outsider');
        $this->assign($outsider, $this->role('Law admin', [Capability::View, Capability::AccessView, Capability::AccessImport]), $other);
        $this->invite([], $outsider)->assertNotFound();

        $this->assertNull(User::where('email', 'nora.new@student.pte.hu')->first());
        Notification::assertNothingSent();
    }

    public function test_a_role_the_area_admin_may_not_give_is_refused_and_nothing_is_created(): void
    {
        // Holds powers the area admin cannot pass on.
        $strong = $this->role('Event manager', [Capability::View, Capability::EventsView, Capability::EventsManage]);

        $this->invite(['role_definition_id' => $strong->id])->assertSessionHasErrors('role_definition_id');

        // The University is above the area admin's own branch (S3).
        $this->invite(['parent_topic_ids' => [$this->university->id]])
            ->assertSessionHasErrors(['parent_topic_ids' => 'You can only add people to areas above this one in your own branch.']);

        $this->assertNull(User::where('email', 'nora.new@student.pte.hu')->first());
        Notification::assertNothingSent();
    }

    // ─── Resend invitation ────────────────────────────────────────────────────

    private function resend(User $person, ?User $as = null, ?Topic $topic = null)
    {
        return $this->actingAs($as ?? $this->areaAdmin)
            ->from('/topics/'.($topic ?? $this->course)->id.'?tab=access')
            ->post('/topics/'.($topic ?? $this->course)->id."/invitations/{$person->id}/resend");
    }

    public function test_an_invitation_can_be_sent_again_with_a_new_link(): void
    {
        $this->invite([])->assertSessionHasNoErrors();
        $nora = User::where('email', 'nora.new@student.pte.hu')->firstOrFail();
        $oldToken = $nora->activation_token;

        // The Access tab offers it on the person's row.
        $this->actingAs($this->areaAdmin)->get("/topics/{$this->course->id}?tab=access")
            ->assertInertia(fn (Assert $page) => $page->where('selected.access.here', fn ($rows) => collect($rows)
                ->firstWhere('user.name', 'Nora New')['can_resend'] === true));

        $this->travel(20)->hours();
        $this->resend($nora)
            ->assertRedirect("/topics/{$this->course->id}?tab=access")
            ->assertSessionHas('success', 'A new invitation was sent to nora.new@student.pte.hu. The link works for 24 hours.');

        $nora->refresh();
        $this->assertNotSame($oldToken, $nora->activation_token);
        $this->assertTrue($nora->hasValidActivationToken());
        $this->assertTrue($nora->activation_token_expires_at->gt(now()->addHours(23)));
        // The old link no longer finds the account.
        $this->assertNull(User::where('activation_token', $oldToken)->first());
        Notification::assertSentToTimes($nora, StudentInvitationNotification::class, 2);
        Notification::assertSentTo($nora, StudentInvitationNotification::class,
            fn (StudentInvitationNotification $n) => $n->activationToken === $nora->activation_token && $n->expiresInHours === 24);
    }

    public function test_a_resend_is_refused_for_an_active_account(): void
    {
        $anna = $this->account('ANNA01', 'anna@student.pte.hu');
        $this->assign($anna, $this->student, $this->course);

        $this->resend($anna)->assertSessionHas('error', 'Person ANNA01 has no open invitation: the account is already activated or it is deactivated.');
        Notification::assertNothingSent();
    }

    public function test_a_resend_is_only_for_people_of_the_area_and_those_who_may_invite_them(): void
    {
        $this->invite([])->assertSessionHasNoErrors();
        $nora = User::where('email', 'nora.new@student.pte.hu')->firstOrFail();

        // Someone with a role elsewhere only: not found from this area.
        $other = $this->topic('Algorithms', $this->faculty);
        $this->resend($nora, topic: $other)->assertNotFound();

        // Sees the area, may not import: refused.
        $viewer = $this->makeUser(UserRole::Teacher, 'Viewer');
        $this->assign($viewer, $this->role('Access viewer', [Capability::View, Capability::AccessView]), $this->faculty);
        $this->resend($nora, $viewer)->assertRedirect('/')->assertSessionHas('error', 'You do not have permission to view this page.');

        // An organiser's invitation also needs Give a role.
        $lead = $this->leadWhoMayGiveRoles();
        $this->invite(['name' => 'Otto', 'email' => 'otto@pte.hu', 'account_type' => 'organiser', 'neptun_code' => '', 'role_definition_id' => $this->organiser->id], $lead)
            ->assertSessionHasNoErrors();
        $otto = User::where('email', 'otto@pte.hu')->firstOrFail();
        $this->resend($otto)->assertRedirect('/');
        $this->resend($otto, $lead)->assertSessionHas('success');
        Notification::assertSentToTimes($otto, OrganizerInvitationNotification::class, 2);

        Notification::assertSentToTimes($nora, StudentInvitationNotification::class, 1);
    }
}

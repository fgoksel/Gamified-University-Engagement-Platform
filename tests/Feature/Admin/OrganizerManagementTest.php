<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Filament\Pages\OrganizerManagement;
use App\Models\Faculty;
use App\Models\User;
use App\Notifications\OrganizerInvitationNotification;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class OrganizerManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function makeAdmin(): User
    {
        $admin = User::create([
            'name' => 'System Admin',
            'email' => 'admin@campusengage.hu',
            'password' => 'secret_hash',
            'status' => 'active',
        ]);
        $admin->assignRole(UserRole::Admin);

        return $admin;
    }

    private function makeOrganizer(string $status = 'active', ?int $facultyId = null): User
    {
        $organizer = User::create([
            'name' => 'Test Organizer',
            'email' => 'organizer@pte.hu',
            'password' => 'secret_hash',
            'status' => $status,
            'faculty_id' => $facultyId,
            'activation_token' => $status === 'invited' ? str_repeat('a', 64) : null,
            'activation_token_expires_at' => $status === 'invited' ? now()->addHours(24) : null,
        ]);
        $organizer->assignRole(UserRole::Teacher);

        return $organizer;
    }

    // ─── UC-3.1.1: Listing ────────────────────────────────────────────────────

    public function test_admin_can_view_organizer_management_page_and_table(): void
    {
        $admin = $this->makeAdmin();

        $faculty = Faculty::create([
            'name' => 'Faculty of Engineering and Information Technology',
            'code' => 'MIK',
        ]);

        $organizer = User::create([
            'name' => 'Existing Teacher',
            'email' => 'teacher.existing@mik.pte.hu',
            'password' => 'secret_hash',
            'status' => 'invited',
            'faculty_id' => $faculty->id,
        ]);
        $organizer->assignRole(UserRole::Teacher);

        $student = User::create([
            'name' => 'Existing Student',
            'email' => 'student.existing@mik.pte.hu',
            'neptun_code' => 'STU123',
            'password' => 'secret_hash',
            'status' => 'active',
            'faculty_id' => $faculty->id,
        ]);

        $this->actingAs($admin)
            ->get('/admin/organizers')
            ->assertOk();

        Livewire::actingAs($admin)
            ->test(OrganizerManagement::class)
            ->assertCanSeeTableRecords([$organizer])
            ->assertCanNotSeeTableRecords([$student, $admin])
            ->assertSeeHtml('Existing Teacher')
            ->assertSeeHtml('teacher.existing@mik.pte.hu')
            ->assertSeeHtml('Faculty of Engineering and Information Technology')
            ->assertDontSeeHtml('student.existing@mik.pte.hu')
            ->assertDontSeeHtml('admin@campusengage.hu');
    }

    public function test_admin_can_invite_new_organizer_individually_per_uc_3_1_1(): void
    {
        Notification::fake();

        $admin = $this->makeAdmin();

        $faculty = Faculty::create([
            'name' => 'Faculty of Sciences',
            'code' => 'TTK',
        ]);

        Livewire::actingAs($admin)
            ->test(OrganizerManagement::class)
            ->callAction('invite', [
                'name' => 'Dr. Kovács Péter',
                'email' => 'kovacs.peter@ttk.pte.hu',
                'faculty_id' => $faculty->id,
            ])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('users', [
            'name' => 'Dr. Kovács Péter',
            'email' => 'kovacs.peter@ttk.pte.hu',
            'status' => 'invited',
            'must_change_password' => 1,
            'faculty_id' => $faculty->id,
        ]);

        $user = User::where('email', 'kovacs.peter@ttk.pte.hu')->firstOrFail();
        $this->assertNotNull($user->activation_token);
        $this->assertSame(64, strlen($user->activation_token));
        $this->assertTrue($user->hasValidActivationToken());
        $this->assertTrue($user->hasRole(UserRole::Teacher));

        Notification::assertSentTo(
            $user,
            OrganizerInvitationNotification::class,
            function (OrganizerInvitationNotification $notification) use ($user) {
                return $notification->activationToken === $user->activation_token
                    && $notification->expiresInHours === 24;
            }
        );
    }

    public function test_organizer_invitation_requires_name_and_valid_email(): void
    {
        $admin = $this->makeAdmin();

        Livewire::actingAs($admin)
            ->test(OrganizerManagement::class)
            ->callAction('invite', [
                'name' => '',
                'email' => 'not-an-email',
                'faculty_id' => null,
            ])
            ->assertHasActionErrors(['name' => 'required', 'email' => 'email']);
    }

    public function test_organizer_invitation_rejects_duplicate_email_with_spec_message(): void
    {
        $admin = $this->makeAdmin();

        User::create([
            'name' => 'Original User',
            'email' => 'already.registered@pte.hu',
            'password' => 'secret_hash',
            'status' => 'active',
        ]);

        Livewire::actingAs($admin)
            ->test(OrganizerManagement::class)
            ->callAction('invite', [
                'name' => 'Duplicate Attempt',
                'email' => 'already.registered@pte.hu',
                'faculty_id' => null,
            ])
            ->assertHasActionErrors([
                'email' => 'This email address is already registered in the system.',
            ]);
    }

    public function test_organizer_invitation_can_be_sent_without_faculty(): void
    {
        Notification::fake();

        $admin = $this->makeAdmin();

        Livewire::actingAs($admin)
            ->test(OrganizerManagement::class)
            ->callAction('invite', [
                'name' => 'Faculty-less Organizer',
                'email' => 'nofaculty@pte.hu',
                'faculty_id' => null,
            ])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('users', [
            'email' => 'nofaculty@pte.hu',
            'faculty_id' => null,
            'status' => 'invited',
        ]);
    }

    public function test_invitation_notification_renders_proper_mail_content(): void
    {
        $user = new User([
            'name' => 'Dr. Teszt Elek',
            'email' => 'teszt.elek@pte.hu',
        ]);

        $notification = new OrganizerInvitationNotification('test-token-123', 24);
        $mail = $notification->toMail($user);

        $this->assertSame('Invitation to Gamified University Engagement Platform', $mail->subject);
        $this->assertStringContainsString('Dr. Teszt Elek', $mail->greeting);
        $this->assertStringContainsString('/auth/activate/test-token-123', $mail->actionUrl);
        $this->assertStringContainsString('within 24 hours', implode(' ', $mail->introLines));
    }

    public function test_administrators_and_observers_are_not_displayed_in_organizers_table(): void
    {
        $admin = $this->makeAdmin();

        $observer = User::create([
            'name' => 'Public Observer',
            'email' => 'observer@campusengage.hu',
            'password' => 'secret_hash',
            'status' => 'active',
        ]);
        $observer->assignRole(UserRole::Observer);

        $teacher = User::create([
            'name' => 'Valid Teacher',
            'email' => 'teacher@campusengage.hu',
            'password' => 'secret_hash',
            'status' => 'active',
        ]);
        $teacher->assignRole(UserRole::Teacher);

        Livewire::actingAs($admin)
            ->test(OrganizerManagement::class)
            ->assertCanSeeTableRecords([$teacher])
            ->assertCanNotSeeTableRecords([$admin, $observer])
            ->assertSeeHtml('Valid Teacher')
            ->assertDontSeeHtml('admin@campusengage.hu')
            ->assertDontSeeHtml('observer@campusengage.hu');
    }

    // ─── UC-3.1.2: Deactivation ───────────────────────────────────────────────

    public function test_admin_can_deactivate_active_organizer_per_uc_3_1_2(): void
    {
        $admin = $this->makeAdmin();
        $organizer = $this->makeOrganizer('active');

        Livewire::actingAs($admin)
            ->test(OrganizerManagement::class)
            ->callTableAction('deactivate', $organizer)
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('users', [
            'id' => $organizer->id,
            'status' => 'inactive',
            'activation_token' => null,
            'activation_token_expires_at' => null,
        ]);
    }

    public function test_admin_can_deactivate_invited_organizer_and_revokes_token(): void
    {
        $admin = $this->makeAdmin();
        $organizer = $this->makeOrganizer('invited');

        // Confirm token exists before deactivation.
        $this->assertNotNull($organizer->fresh()->activation_token);

        Livewire::actingAs($admin)
            ->test(OrganizerManagement::class)
            ->callTableAction('deactivate', $organizer)
            ->assertHasNoTableActionErrors();

        $fresh = $organizer->fresh();
        $this->assertSame('inactive', $fresh->status);
        $this->assertNull($fresh->activation_token);
        $this->assertNull($fresh->activation_token_expires_at);
    }

    public function test_deactivate_action_is_hidden_for_already_inactive_organizer(): void
    {
        $admin = $this->makeAdmin();
        $organizer = $this->makeOrganizer('inactive');

        Livewire::actingAs($admin)
            ->test(OrganizerManagement::class)
            ->assertTableActionHidden('deactivate', $organizer);
    }

    public function test_admin_can_reactivate_inactive_organizer_per_uc_3_1_2(): void
    {
        $admin = $this->makeAdmin();
        $organizer = $this->makeOrganizer('inactive');

        Livewire::actingAs($admin)
            ->test(OrganizerManagement::class)
            ->callTableAction('reactivate', $organizer)
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('users', [
            'id' => $organizer->id,
            'status' => 'active',
        ]);
    }

    public function test_reactivate_action_is_hidden_for_active_organizer(): void
    {
        $admin = $this->makeAdmin();
        $organizer = $this->makeOrganizer('active');

        Livewire::actingAs($admin)
            ->test(OrganizerManagement::class)
            ->assertTableActionHidden('reactivate', $organizer);
    }

    public function test_reactivate_action_is_hidden_for_invited_organizer(): void
    {
        $admin = $this->makeAdmin();
        $organizer = $this->makeOrganizer('invited');

        Livewire::actingAs($admin)
            ->test(OrganizerManagement::class)
            ->assertTableActionHidden('reactivate', $organizer);
    }
}

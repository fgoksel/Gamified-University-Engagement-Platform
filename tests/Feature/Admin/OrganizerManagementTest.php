<?php

namespace Tests\Feature\Admin;

use App\Filament\Pages\OrganizerManagement;
use App\Models\Faculty;
use App\Models\User;
use App\Notifications\OrganizerInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class OrganizerManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_organizer_management_page_and_table(): void
    {
        $admin = User::create([
            'name' => 'System Admin',
            'email' => 'admin@campusengage.hu',
            'password' => 'secret_hash',
            'status' => 'active',
        ]);

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
            ->assertCanNotSeeTableRecords([$student])
            ->assertSeeHtml('Existing Teacher')
            ->assertSeeHtml('teacher.existing@mik.pte.hu')
            ->assertSeeHtml('Faculty of Engineering and Information Technology')
            ->assertDontSeeHtml('student.existing@mik.pte.hu');
    }

    public function test_admin_can_invite_new_organizer_individually_per_uc_3_1_1(): void
    {
        Notification::fake();

        $admin = User::create([
            'name' => 'System Admin',
            'email' => 'admin@campusengage.hu',
            'password' => 'secret_hash',
            'status' => 'active',
        ]);

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

        Notification::assertSentTo(
            $user,
            OrganizerInvitationNotification::class,
            function (OrganizerInvitationNotification $notification) use ($user) {
                return $notification->activationToken === $user->activation_token
                    && $notification->expiresInHours === 48;
            }
        );
    }

    public function test_organizer_invitation_requires_name_and_valid_email(): void
    {
        $admin = User::create([
            'name' => 'System Admin',
            'email' => 'admin@campusengage.hu',
            'password' => 'secret_hash',
            'status' => 'active',
        ]);

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
        $admin = User::create([
            'name' => 'System Admin',
            'email' => 'admin@campusengage.hu',
            'password' => 'secret_hash',
            'status' => 'active',
        ]);

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

        $admin = User::create([
            'name' => 'System Admin',
            'email' => 'admin@campusengage.hu',
            'password' => 'secret_hash',
            'status' => 'active',
        ]);

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

        $notification = new OrganizerInvitationNotification('test-token-123', 48);
        $mail = $notification->toMail($user);

        $this->assertSame('Invitation to Gamified University Engagement Platform', $mail->subject);
        $this->assertStringContainsString('Dr. Teszt Elek', $mail->greeting);
        $this->assertStringContainsString('/auth/activate/test-token-123', $mail->actionUrl);
    }
}

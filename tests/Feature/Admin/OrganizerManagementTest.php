<?php

namespace Tests\Feature\Admin;

use App\Models\Faculty;
use App\Models\User;
use App\Notifications\OrganizerInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OrganizerManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('inertia.pages.paths', [
            resource_path('js/Pages'),
            resource_path('js/pages'),
        ]);
    }

    public function test_admin_can_view_organizer_management_index_with_faculties(): void
    {
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

        $response = $this->get('/admin/organizers');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Organizers/Index')
            ->has('organizers', 1)
            ->has('faculties', 1)
            ->where('organizers.0.email', 'teacher.existing@mik.pte.hu')
            ->where('faculties.0.code', 'MIK')
        );
    }

    public function test_admin_can_invite_new_organizer_individually_per_uc_3_1_1(): void
    {
        Notification::fake();

        $faculty = Faculty::create([
            'name' => 'Faculty of Sciences',
            'code' => 'TTK',
        ]);

        $postData = [
            'name' => 'Dr. Kovács Péter',
            'email' => 'kovacs.peter@ttk.pte.hu',
            'faculty_id' => $faculty->id,
        ];

        $response = $this->post('/admin/organizers', $postData);

        $response->assertRedirect('/admin/organizers');
        $response->assertSessionHas('success', 'The invitation was successfully sent to kovacs.peter@ttk.pte.hu.');

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
        $response = $this->post('/admin/organizers', [
            'name' => '',
            'email' => 'not-an-email',
            'faculty_id' => null,
        ]);

        $response->assertSessionHasErrors(['name', 'email']);
    }

    public function test_organizer_invitation_rejects_duplicate_email_with_spec_message(): void
    {
        User::create([
            'name' => 'Original User',
            'email' => 'already.registered@pte.hu',
            'password' => 'secret_hash',
            'status' => 'active',
        ]);

        $response = $this->post('/admin/organizers', [
            'name' => 'Duplicate Attempt',
            'email' => 'already.registered@pte.hu',
            'faculty_id' => null,
        ]);

        $response->assertSessionHasErrors([
            'email' => 'This email address is already registered in the system.',
        ]);
    }

    public function test_organizer_invitation_validates_faculty_must_exist(): void
    {
        $response = $this->post('/admin/organizers', [
            'name' => 'Invalid Faculty Teacher',
            'email' => 'invalid.faculty@pte.hu',
            'faculty_id' => 99999,
        ]);

        $response->assertSessionHasErrors([
            'faculty_id' => 'The selected organizational unit/faculty does not exist.',
        ]);
    }

    public function test_organizer_invitation_can_be_sent_without_faculty(): void
    {
        Notification::fake();

        $response = $this->post('/admin/organizers', [
            'name' => 'Faculty-less Organizer',
            'email' => 'nofaculty@pte.hu',
            'faculty_id' => null,
        ]);

        $response->assertRedirect('/admin/organizers');
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

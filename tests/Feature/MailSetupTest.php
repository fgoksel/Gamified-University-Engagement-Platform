<?php

namespace Tests\Feature;

use App\Mail\TestMail;
use App\Models\User;
use App\Notifications\OrganizerInvitationNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Checks the email setup (Task #33, Technical Specification 5.2.5 and 7.3.2).
 */
class MailSetupTest extends TestCase
{
    public function test_mail_test_command_sends_a_test_email(): void
    {
        Mail::fake();

        $this->artisan('mail:test', ['email' => 'admin@pte.hu'])
            ->expectsOutputToContain('Test email sent to admin@pte.hu')
            ->assertSuccessful();

        Mail::assertSent(TestMail::class, fn (TestMail $mail) => $mail->hasTo('admin@pte.hu'));
    }

    public function test_mail_test_command_rejects_an_invalid_address(): void
    {
        Mail::fake();

        $this->artisan('mail:test', ['email' => 'not-an-email'])
            ->expectsOutputToContain('Not a valid email address')
            ->assertFailed();

        Mail::assertNothingSent();
    }

    public function test_mail_test_command_reports_an_unreachable_mail_server(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => '127.0.0.1',
            'mail.mailers.smtp.port' => 1,
            'mail.mailers.smtp.timeout' => 2,
        ]);

        $this->artisan('mail:test', ['email' => 'admin@pte.hu'])
            ->expectsOutputToContain('The email could not be sent')
            ->assertFailed();
    }

    public function test_invitation_emails_are_queued_not_sent_during_the_request(): void
    {
        $this->assertContains(ShouldQueue::class, class_implements(OrganizerInvitationNotification::class));

        Notification::fake();

        $user = User::factory()->make(['email' => 'teacher@pte.hu']);
        $user->notify(new OrganizerInvitationNotification('token', 24));

        Notification::assertSentTo($user, OrganizerInvitationNotification::class);
    }

    public function test_emails_are_sent_from_the_configured_address(): void
    {
        config([
            'mail.default' => 'array',
            'mail.from.address' => 'no-reply@pte.hu',
            'mail.from.name' => 'Campus Engage',
        ]);

        $this->artisan('mail:test', ['email' => 'admin@pte.hu'])->assertSuccessful();

        $sent = Mail::mailer('array')->getSymfonyTransport()->messages()->first()->getOriginalMessage();

        $this->assertSame('no-reply@pte.hu', $sent->getFrom()[0]->getAddress());
        $this->assertSame('Campus Engage', $sent->getFrom()[0]->getName());
    }
}

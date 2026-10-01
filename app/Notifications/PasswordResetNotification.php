<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The "Forgot password" email (UC-1.1 C).
 */
class PasswordResetNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $token,
        public readonly int $expiresInHours = 24,
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Set a new password for Campus Engage')
            ->greeting('Hello '.$notifiable->name.'!')
            ->line('We received a request to set a new password for your account.')
            ->action('Set new password', route('password.setup', $this->token))
            ->line("This link is valid for {$this->expiresInHours} hours and can be used once.")
            ->line('If you did not ask for this, you can ignore this email. Your password will not change.');
    }
}

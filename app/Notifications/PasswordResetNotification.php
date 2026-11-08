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
            ->subject(__('mail.password_reset.subject'))
            ->greeting(__('mail.greeting', ['name' => $notifiable->name]))
            ->line(__('mail.password_reset.request'))
            ->action(__('mail.password_reset.action'), route('password.setup', $this->token))
            ->line(trans_choice('mail.password_reset.valid', $this->expiresInHours))
            ->line(__('mail.password_reset.ignore'));
    }
}

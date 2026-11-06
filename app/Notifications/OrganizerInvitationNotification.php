<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrganizerInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public readonly string $activationToken,
        public readonly int $expiresInHours = 24
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
        $activationUrl = url('/auth/activate/'.$this->activationToken);

        return (new MailMessage)
            ->subject(__('mail.organizer_invitation.subject'))
            ->greeting(__('mail.greeting_comma', ['name' => $notifiable->name]))
            ->line(__('mail.organizer_invitation.invited'))
            ->line(trans_choice('mail.organizer_invitation.complete', $this->expiresInHours))
            ->action(__('mail.organizer_invitation.action'), $activationUrl)
            ->line(__('mail.organizer_invitation.ignore'))
            ->salutation(__('mail.salutation'));
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'activation_token' => $this->activationToken,
            'expires_in_hours' => $this->expiresInHours,
        ];
    }
}

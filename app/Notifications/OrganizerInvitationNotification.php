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
        public readonly int $expiresInHours = 48
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
            ->subject('Invitation to Gamified University Engagement Platform')
            ->greeting("Hello {$notifiable->name},")
            ->line('You have been invited to join the Gamified University Engagement Platform as an Event Organizer.')
            ->line("To complete your registration and set up your password, please click the button below within {$this->expiresInHours} hours:")
            ->action('Activate Account & Set Password', $activationUrl)
            ->line('If you were not expecting this invitation, you can safely ignore this email.')
            ->salutation('Best regards, University Engagement Team');
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

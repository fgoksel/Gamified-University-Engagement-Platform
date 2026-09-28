<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class StudentInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $activationToken,
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
        $activationUrl = url('/auth/activate/'.$this->activationToken);

        return (new MailMessage)
            ->subject('Student Invitation to Gamified University Engagement Platform')
            ->greeting('Hello '.$notifiable->name.'!')
            ->line('You have been registered as a student in the Gamified University Engagement Platform.')
            ->line('Your Neptun code is: '.($notifiable->neptun_code ?? 'N/A'))
            ->line('Please activate your account and set your permanent password using the button below.')
            ->action('Activate Student Account', $activationUrl)
            ->line("This activation link is valid for {$this->expiresInHours} hours.")
            ->line('If you did not expect this invitation, no action is required.');
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

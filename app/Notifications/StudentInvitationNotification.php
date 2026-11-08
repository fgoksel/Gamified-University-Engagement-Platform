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
            ->subject(__('mail.student_invitation.subject'))
            ->greeting(__('mail.greeting', ['name' => $notifiable->name]))
            ->line(__('mail.student_invitation.registered'))
            ->line(__('mail.student_invitation.neptun_code', ['code' => $notifiable->neptun_code ?? __('common.not_available')]))
            ->line(__('mail.student_invitation.activate_prompt'))
            ->action(__('mail.student_invitation.action'), $activationUrl)
            ->line(trans_choice('mail.student_invitation.valid', $this->expiresInHours))
            ->line(__('mail.student_invitation.ignore'));
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

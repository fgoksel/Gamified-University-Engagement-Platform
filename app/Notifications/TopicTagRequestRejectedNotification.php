<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TopicTagRequestRejectedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $proposedName,
        public readonly ?string $reason = null
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Topic Tag Request Status Update')
            ->greeting("Hello {$notifiable->name},")
            ->line("Your topic tag request for \"{$this->proposedName}\" was not approved.");

        if ($this->reason) {
            $mail->line("Reason: {$this->reason}");
        }

        $mail->line('Please choose an existing topic tag from the available taxonomy for your event.')
            ->salutation('Best regards, University Engagement Team');

        return $mail;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'proposed_name' => $this->proposedName,
            'reason' => $this->reason,
        ];
    }
}

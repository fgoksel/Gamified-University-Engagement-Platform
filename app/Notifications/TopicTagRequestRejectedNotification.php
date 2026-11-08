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
            ->subject(__('mail.tag_rejected.topic_tag_request_status_update'))
            ->greeting(__('mail.greeting_comma', ['name' => $notifiable->name]))
            ->line(__('mail.tag_rejected.not_approved_text', ['name' => $this->proposedName]));

        if ($this->reason) {
            $mail->line(__('mail.reason', ['reason' => $this->reason]));
        }

        $mail->line(__('mail.tag_rejected.please_choose_an_existing_topic'))
            ->salutation(__('mail.salutation'));

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

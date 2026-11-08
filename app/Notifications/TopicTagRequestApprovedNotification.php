<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TopicTagRequestApprovedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $proposedName,
        public readonly string $category
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
        return (new MailMessage)
            ->subject(__('mail.tag_approved.topic_tag_request_approved'))
            ->greeting(__('mail.greeting_comma', ['name' => $notifiable->name]))
            ->line(__('mail.tag_approved.approved_text', ['name' => $this->proposedName]))
            ->line(__('mail.tag_approved.the_tag_is_now_active'))
            ->line(__('mail.tag_approved.thank_you_for_contributing'))
            ->salutation(__('mail.salutation'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'proposed_name' => $this->proposedName,
            'category' => $this->category,
        ];
    }
}

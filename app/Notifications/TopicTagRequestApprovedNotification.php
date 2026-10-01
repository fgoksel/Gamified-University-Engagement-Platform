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
            ->subject('Topic Tag Request Approved')
            ->greeting("Hello {$notifiable->name},")
            ->line("Your topic tag request for \"{$this->proposedName}\" has been approved.")
            ->line('The tag is now active and immediately selectable in the Topic Tag dropdown when creating events.')
            ->line('Thank you for contributing to the university engagement taxonomy.')
            ->salutation('Best regards, University Engagement Team');
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

<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a student that the organizer accepted or rejected their application
 * (Task #41, UC-1.6). Queued; sent by mail and stored in-app.
 */
class EventApplicationDecisionNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const ACCEPTED = 'accepted';

    public const REJECTED = 'rejected';

    public function __construct(
        public readonly string $decision,
        public readonly int $eventId,
        public readonly string $eventTitle,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->greeting("Hello {$notifiable->name},");

        if ($this->decision === self::ACCEPTED) {
            return $mail
                ->subject('You were selected for an event')
                ->line("You have been selected for \"{$this->eventTitle}\".")
                ->line('You can follow it under My events.')
                ->salutation('Best regards, University Engagement Team');
        }

        return $mail
            ->subject('Your event application was not accepted')
            ->line("Your application for \"{$this->eventTitle}\" was not accepted by the organizer.")
            ->salutation('Best regards, University Engagement Team');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'decision' => $this->decision,
            'event_id' => $this->eventId,
            'event_title' => $this->eventTitle,
        ];
    }
}

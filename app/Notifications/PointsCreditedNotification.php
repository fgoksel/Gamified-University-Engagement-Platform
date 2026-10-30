<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Tells a student that an organiser gave them an event's points from an imported list
 * (Task #85). For a surprise event this is how the student finds out about it (team
 * decision of 27 Oct). Queued; in-app only, shown on the Notifications page, so a class
 * list of a few hundred students sends no burst of emails.
 */
class PointsCreditedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $eventId,
        public readonly string $eventTitle,
        public readonly int $points,
        public readonly string $category,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'event_id' => $this->eventId,
            'event_title' => $this->eventTitle,
            'points' => $this->points,
            'category' => $this->category,
        ];
    }
}

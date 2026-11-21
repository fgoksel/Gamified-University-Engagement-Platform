<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the people an event concerns that a system admin held it, cancelled it or removed it
 * (Task #58, UC-3.5): the students who applied, the students whose points were taken back, and the
 * organiser who made the event. Only the organiser is told the admin's reason; the students learn
 * what happened to the event and to their points, not why. Queued; sent by mail and stored in-app
 * (shown on the Notifications page), like the messages for what an organiser does
 * (EventStatusChangedNotification).
 */
class EventModeratedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** Frozen and may come back; applications are kept. */
    public const HELD = 'held';

    /** Not happening, ever. */
    public const CANCELLED = 'cancelled';

    /** Taken off the platform for everybody; the points it gave were taken back. */
    public const REMOVED = 'removed';

    public function __construct(
        public readonly string $change,
        public readonly int $eventId,
        public readonly string $eventTitle,
        // The admin's reason. Given to the organiser only.
        public readonly ?string $reason = null,
        // The points taken back from this student by a removal; 0 for everybody else.
        public readonly int $points = 0,
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
        [$title, $lines] = self::words($this->change, $this->eventTitle, $this->reason, $this->points);

        $mail = (new MailMessage)->subject($title)->greeting(__('mail.greeting_comma', ['name' => $notifiable->name]));

        foreach ($lines as $line) {
            $mail->line($line);
        }

        return $mail->salutation(__('mail.salutation'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'change' => $this->change,
            'event_id' => $this->eventId,
            'event_title' => $this->eventTitle,
            'reason' => $this->reason,
            'points' => $this->points,
        ];
    }

    /**
     * What the message says: its title and its lines. The mail and the Notifications page both
     * read it from here (NotificationFeed), so they cannot drift apart.
     *
     * @return array{0: string, 1: list<string>}
     */
    public static function words(string $change, string $event, ?string $reason = null, int $points = 0): array
    {
        [$title, $sentence] = match ($change) {
            self::HELD => [
                __('notifications.feed.change.on_hold.title', ['event' => $event]),
                __('notifications.moderated.held', ['event' => $event]),
            ],
            self::CANCELLED => [
                __('notifications.feed.change.cancelled.title', ['event' => $event]),
                __('notifications.moderated.cancelled', ['event' => $event]),
            ],
            default => [
                __('notifications.moderated.removed_title', ['event' => $event]),
                __('notifications.moderated.removed', ['event' => $event]),
            ],
        };

        return [$title, [
            $sentence,
            ...(filled($reason) ? [__('mail.reason', ['reason' => $reason])] : []),
            ...($points > 0 ? [trans_choice('notifications.moderated.points', $points)] : []),
        ]];
    }
}

<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the students who applied to an event that the organizer cancelled it,
 * put it on hold, restored it (Task #53, D09) or changed its date or time (Task #68). Queued; sent by mail and
 * stored in-app (shown on the Notifications page).
 */
class EventStatusChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** Not happening, ever; carries the organizer's reason. */
    public const CANCELLED = 'cancelled';

    /** Delayed and may come back; applications are kept. */
    public const ON_HOLD = 'on_hold';

    /** The organizer changed the date or time; applications are kept (Task #68). */
    public const RESCHEDULED = 'rescheduled';

    /** Back from hold; applicants keep their place and may withdraw freely. */
    public const RESTORED = 'restored';

    public function __construct(
        public readonly string $change,
        public readonly int $eventId,
        public readonly string $eventTitle,
        public readonly ?string $reason = null,
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
        [$subject, $lines] = match ($this->change) {
            self::CANCELLED => ['An event you applied to is cancelled', [
                "The organizer cancelled \"{$this->eventTitle}\". It will not take place.",
                ...($this->reason ? ["Reason: {$this->reason}"] : []),
            ]],
            self::ON_HOLD => ['An event you applied to is on hold', [
                "The organizer put \"{$this->eventTitle}\" on hold. It may come back later.",
                'Your application is kept. We will tell you if the event is restored or cancelled.',
            ]],
            self::RESCHEDULED => ['The time of an event you applied to changed', [
                "The organizer changed the date or time of \"{$this->eventTitle}\".",
                'You keep your place. Check the new time under My events. If it no longer suits you, you can withdraw.',
            ]],
            default => ['An event you applied to is back', [
                "\"{$this->eventTitle}\" is back on. You keep your place.",
                'Check the date under My events. If it no longer suits you, you can withdraw.',
            ]],
        };

        $mail = (new MailMessage)->subject($subject)->greeting("Hello {$notifiable->name},");

        foreach ($lines as $line) {
            $mail->line($line);
        }

        return $mail->salutation('Best regards, University Engagement Team');
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
        ];
    }
}

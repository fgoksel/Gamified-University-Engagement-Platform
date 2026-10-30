<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the students who applied to an event that the organizer cancelled it,
 * put it on hold, restored it (Task #53, D09), changed its date or time (Task #68) or its location (Task #77).
 * Queued; sent by mail and stored in-app (shown on the Notifications page).
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

    /** The organizer set, changed or removed the location; carries the new one (Task #77). */
    public const RELOCATED = 'relocated';

    /** The time and the location changed in the same save: one message instead of two (Task #77). */
    public const RESCHEDULED_AND_RELOCATED = 'rescheduled_relocated';

    public function __construct(
        public readonly string $change,
        public readonly int $eventId,
        public readonly string $eventTitle,
        public readonly ?string $reason = null,
        // The location after the change; null when it was removed.
        public readonly ?string $location = null,
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
            self::RELOCATED => ['The location of an event you applied to changed', [
                "The organizer changed the location of \"{$this->eventTitle}\".",
                $this->newLocation(),
                'You keep your place. If the new location no longer suits you, you can withdraw under My events.',
            ]],
            self::RESCHEDULED_AND_RELOCATED => ['The time and location of an event you applied to changed', [
                "The organizer changed the date or time and the location of \"{$this->eventTitle}\".",
                $this->newLocation(),
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

    private function newLocation(): string
    {
        return $this->location !== null ? "New location: {$this->location}" : 'The location was removed.';
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
            'location' => $this->location,
        ];
    }
}

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
            self::CANCELLED => [__('mail.event_status.an_event_you_applied'), [
                __('mail.event_status.cancelled_text', ['event' => $this->eventTitle]),
                ...($this->reason ? [__('mail.reason', ['reason' => $this->reason])] : []),
            ]],
            self::ON_HOLD => [__('mail.event_status.an_event_you_applied_2'), [
                __('mail.event_status.on_hold_text', ['event' => $this->eventTitle]),
                __('mail.event_status.your_application_is_kept_we'),
            ]],
            self::RESCHEDULED => [__('mail.event_status.the_time_of_an_event'), [
                __('mail.event_status.rescheduled_text', ['event' => $this->eventTitle]),
                __('mail.event_status.you_keep_your_place_check'),
            ]],
            self::RELOCATED => [__('mail.event_status.the_location_of_an_event'), [
                __('mail.event_status.relocated_text', ['event' => $this->eventTitle]),
                $this->newLocation(),
                __('mail.event_status.you_keep_your_place_if'),
            ]],
            self::RESCHEDULED_AND_RELOCATED => [__('mail.event_status.the_time_and_location'), [
                __('mail.event_status.both_text', ['event' => $this->eventTitle]),
                $this->newLocation(),
                __('mail.event_status.you_keep_your_place_check'),
            ]],
            default => [__('mail.event_status.an_event_you_applied_3'), [
                __('mail.event_status.restored_text', ['event' => $this->eventTitle]),
                __('mail.event_status.check_the_date_under_my'),
            ]],
        };

        $mail = (new MailMessage)->subject($subject)->greeting(__('mail.greeting_comma', ['name' => $notifiable->name]));

        foreach ($lines as $line) {
            $mail->line($line);
        }

        return $mail->salutation(__('mail.salutation'));
    }

    private function newLocation(): string
    {
        return $this->location !== null ? __('mail.event_status.new_location', ['location' => $this->location]) : __('mail.event_status.the_location_was_removed');
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

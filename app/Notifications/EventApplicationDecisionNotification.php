<?php

namespace App\Notifications;

use App\Models\EventApplication;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a student what happened to their application (Tasks #41 and #53,
 * UC-1.6 / UC-2.3): selected, rejected or taken back out of a selection by the
 * organizer; or, for first come first served events, given a place, put on the
 * waitlist or promoted from it. Queued; sent by mail and stored in-app (shown
 * on the Notifications page).
 */
class EventApplicationDecisionNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** Selected by the organizer (selection mode). */
    public const ACCEPTED = 'accepted';

    public const REJECTED = 'rejected';

    /** Taken out of a selection: the application is pending again (UC-1.6). */
    public const REMOVED = 'removed';

    /** First come first served: a place was free, so the application was accepted at once. */
    public const CONFIRMED = 'confirmed';

    /** First come first served: the event was full, so the application waits for a place. */
    public const WAITLISTED = 'waitlisted';

    /** First come first served: someone withdrew and the student moved up from the waitlist. */
    public const PROMOTED = 'promoted';

    public function __construct(
        public readonly string $decision,
        public readonly int $eventId,
        public readonly string $eventTitle,
        // Set when the message waits until the undo minute is over (Task #94)
        public readonly ?int $applicationId = null,
        // The application time it belongs to: applying again after an undo sends its own message
        public readonly ?string $appliedAt = null,
    ) {}

    /**
     * A "you have a place" or "you are on the waitlist" message is sent only after the
     * student could still undo (Task #94). By then the application may have changed:
     * undone, left, or promoted from the waitlist (which sends its own message).
     * Send it only if the application still says what the message says, and was not
     * sent again in the meantime (that new application has its own message).
     */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        $expected = match ($this->decision) {
            self::CONFIRMED => EventApplication::ACCEPTED,
            self::WAITLISTED => EventApplication::WAITLISTED,
            default => null,
        };

        if ($this->applicationId === null || $expected === null) {
            return true;
        }

        $application = EventApplication::query()->find($this->applicationId);

        return $application !== null
            && $application->status === $expected
            && ($this->appliedAt === null || $application->applied_at?->toIso8601String() === $this->appliedAt);
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        [$subject, $lines] = match ($this->decision) {
            self::ACCEPTED => [__('mail.event_decision.you_were_selected'), [
                __('mail.event_decision.selected_for', ['event' => $this->eventTitle]),
                __('mail.event_decision.you_can_follow_it_under'),
            ]],
            self::REMOVED => [__('mail.event_decision.you_were_removed'), [
                __('mail.event_decision.removed_from', ['event' => $this->eventTitle]),
                __('mail.event_decision.your_application_is_pending_again'),
            ]],
            self::CONFIRMED => [__('mail.event_decision.your_place_at_an_event'), [
                __('mail.event_decision.place_confirmed', ['event' => $this->eventTitle]),
                __('mail.event_decision.you_can_follow_it_under'),
            ]],
            self::WAITLISTED => [__('mail.event_decision.you_are_on_the_waitlist'), [
                __('mail.event_decision.waitlisted', ['event' => $this->eventTitle]),
                __('mail.event_decision.if_a_place_becomes_free'),
            ]],
            self::PROMOTED => [__('mail.event_decision.a_place_opened_up'), [
                __('mail.event_decision.promoted', ['event' => $this->eventTitle]),
                __('mail.event_decision.your_application_is_now_accepted'),
            ]],
            default => [__('mail.event_decision.your_event_application_was'), [
                __('mail.event_decision.not_accepted', ['event' => $this->eventTitle]),
            ]],
        };

        $mail = (new MailMessage)->subject($subject)->greeting(__('mail.greeting_comma', ['name' => $notifiable->name]));

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
            'decision' => $this->decision,
            'event_id' => $this->eventId,
            'event_title' => $this->eventTitle,
        ];
    }
}

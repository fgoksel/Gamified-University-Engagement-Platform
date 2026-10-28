<?php

namespace App\Services;

use App\Notifications\EventApplicationDecisionNotification;
use App\Notifications\EventStatusChangedNotification;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Turns a stored notification into the text and link shown on the Notifications
 * page (Task #53). Only the person's own notifications ever reach this class.
 */
class NotificationFeed
{
    /**
     * @return array{id: string, title: string, body: string, link: ?string, read: bool, created_at: string, created_human: string}
     */
    public function present(DatabaseNotification $notification): array
    {
        [$title, $body, $link] = $this->describe($notification);

        return [
            'id' => $notification->id,
            'title' => $title,
            'body' => $body,
            'link' => $link,
            'read' => $notification->read_at !== null,
            'created_at' => $notification->created_at->toIso8601String(),
            'created_human' => $notification->created_at->diffForHumans(),
        ];
    }

    /**
     * @return array{0: string, 1: string, 2: ?string}
     */
    private function describe(DatabaseNotification $notification): array
    {
        $data = $notification->data;

        if ($notification->type === EventApplicationDecisionNotification::class) {
            $event = $data['event_title'] ?? 'an event';

            return match ($data['decision'] ?? null) {
                EventApplicationDecisionNotification::ACCEPTED => [
                    "You were selected for {$event}",
                    'The organizer accepted your application. You can follow it under My events.',
                    '/my-events',
                ],
                EventApplicationDecisionNotification::REJECTED => [
                    'Your application was not accepted',
                    "The organizer did not accept your application for {$event}.",
                    '/my-events',
                ],
                EventApplicationDecisionNotification::REMOVED => [
                    "You were removed from the selection for {$event}",
                    'Your application is pending again, so the organizer may select you later.',
                    '/my-events',
                ],
                EventApplicationDecisionNotification::CONFIRMED => [
                    "Your place at {$event} is confirmed",
                    'Your application was accepted. You can follow it under My events.',
                    '/my-events',
                ],
                EventApplicationDecisionNotification::WAITLISTED => [
                    "You are on the waitlist for {$event}",
                    'The event is full. If a place becomes free you move up automatically.',
                    '/my-events',
                ],
                EventApplicationDecisionNotification::PROMOTED => [
                    "A place opened up: you are in for {$event}",
                    'You moved up from the waitlist and your application is now accepted.',
                    '/my-events',
                ],
                default => ['Update on your application', $event, '/my-events'],
            };
        }

        if ($notification->type === EventStatusChangedNotification::class) {
            $event = $data['event_title'] ?? 'an event';
            $reason = filled($data['reason'] ?? null) ? " Reason: {$data['reason']}" : '';

            return match ($data['change'] ?? null) {
                EventStatusChangedNotification::CANCELLED => [
                    "{$event} is cancelled",
                    "The organizer cancelled the event. It will not take place.{$reason}",
                    '/my-events',
                ],
                EventStatusChangedNotification::ON_HOLD => [
                    "{$event} is on hold",
                    'The event may come back later. Your application is kept.',
                    '/my-events',
                ],
                EventStatusChangedNotification::RESTORED => [
                    "{$event} is back",
                    'You keep your place. Check the date; if it no longer suits you, you can withdraw.',
                    '/my-events',
                ],
                EventStatusChangedNotification::RESCHEDULED => [
                    "The time of {$event} changed",
                    'You keep your place. Check the new date; if it no longer suits you, you can withdraw.',
                    '/my-events',
                ],
                default => ['Update on an event you applied to', $event, '/my-events'],
            };
        }

        // Import results are Filament notifications, which keep their text in "body" (Task #72).
        return [$data['title'] ?? 'Notification', $data['message'] ?? $data['body'] ?? '', null];
    }
}

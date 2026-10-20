<?php

namespace App\Services;

use App\Notifications\EventApplicationDecisionNotification;
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
                default => ['Update on your application', $event, '/my-events'],
            };
        }

        return [$data['title'] ?? 'Notification', $data['message'] ?? '', null];
    }
}

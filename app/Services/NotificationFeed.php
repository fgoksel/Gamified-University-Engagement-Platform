<?php

namespace App\Services;

use App\Models\Event;
use App\Notifications\EventApplicationDecisionNotification;
use App\Notifications\EventStatusChangedNotification;
use App\Notifications\PointsCreditedNotification;
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
            $event = $data['event_title'] ?? __('notifications.feed.an_event');

            return match ($data['decision'] ?? null) {
                EventApplicationDecisionNotification::ACCEPTED => [
                    __('notifications.feed.decision.accepted.title', ['event' => $event]),
                    __('notifications.feed.decision.accepted.body'),
                    '/my-events',
                ],
                EventApplicationDecisionNotification::REJECTED => [
                    __('notifications.feed.decision.rejected.title'),
                    __('notifications.feed.decision.rejected.body', ['event' => $event]),
                    '/my-events',
                ],
                EventApplicationDecisionNotification::REMOVED => [
                    __('notifications.feed.decision.removed.title', ['event' => $event]),
                    __('notifications.feed.decision.removed.body'),
                    '/my-events',
                ],
                EventApplicationDecisionNotification::CONFIRMED => [
                    __('notifications.feed.decision.confirmed.title', ['event' => $event]),
                    __('notifications.feed.decision.confirmed.body'),
                    '/my-events',
                ],
                EventApplicationDecisionNotification::WAITLISTED => [
                    __('notifications.feed.decision.waitlisted.title', ['event' => $event]),
                    __('notifications.feed.decision.waitlisted.body'),
                    '/my-events',
                ],
                EventApplicationDecisionNotification::PROMOTED => [
                    __('notifications.feed.decision.promoted.title', ['event' => $event]),
                    __('notifications.feed.decision.promoted.body'),
                    '/my-events',
                ],
                default => [__('notifications.feed.decision.default'), $event, '/my-events'],
            };
        }

        if ($notification->type === EventStatusChangedNotification::class) {
            $event = $data['event_title'] ?? __('notifications.feed.an_event');
            // Task #77: where the event is after the change; null when the location was removed.
            $where = filled($data['location'] ?? null)
                ? __('notifications.feed.location_now', ['location' => $data['location']])
                : __('notifications.feed.location_removed');

            return match ($data['change'] ?? null) {
                EventStatusChangedNotification::CANCELLED => [
                    __('notifications.feed.change.cancelled.title', ['event' => $event]),
                    filled($data['reason'] ?? null)
                        ? __('notifications.feed.change.cancelled.body_with_reason', ['reason' => $data['reason']])
                        : __('notifications.feed.change.cancelled.body'),
                    '/my-events',
                ],
                EventStatusChangedNotification::ON_HOLD => [
                    __('notifications.feed.change.on_hold.title', ['event' => $event]),
                    __('notifications.feed.change.on_hold.body'),
                    '/my-events',
                ],
                EventStatusChangedNotification::RESTORED => [
                    __('notifications.feed.change.restored.title', ['event' => $event]),
                    __('notifications.feed.change.restored.body'),
                    '/my-events',
                ],
                EventStatusChangedNotification::RESCHEDULED => [
                    __('notifications.feed.change.rescheduled.title', ['event' => $event]),
                    __('notifications.feed.change.rescheduled.body'),
                    '/my-events',
                ],
                EventStatusChangedNotification::RELOCATED => [
                    __('notifications.feed.change.relocated.title', ['event' => $event]),
                    __('notifications.feed.change.relocated.body', ['where' => $where]),
                    '/my-events',
                ],
                EventStatusChangedNotification::RESCHEDULED_AND_RELOCATED => [
                    __('notifications.feed.change.rescheduled_and_relocated.title', ['event' => $event]),
                    __('notifications.feed.change.rescheduled_and_relocated.body', ['where' => $where]),
                    '/my-events',
                ],
                default => [__('notifications.feed.change.default'), $event, '/my-events'],
            };
        }

        if ($notification->type === PointsCreditedNotification::class) {
            $points = (int) ($data['points'] ?? 0);
            // The name the category has now (Task #116); none for an event without a category.
            $category = app(CategoryList::class)->name(is_string($data['category'] ?? null) ? $data['category'] : null);
            $event = Event::find($data['event_id'] ?? null);
            $eventTitle = $data['event_title'] ?? __('notifications.feed.an_event');

            return [
                trans_choice('notifications.feed.points.title', $points),
                $category
                    ? __('notifications.feed.points.body_with_category', ['event' => $eventTitle, 'category' => $category])
                    : __('notifications.feed.points.body', ['event' => $eventTitle]),
                // Linked only while the student may open the event: a surprise event can stay hidden from them.
                $event !== null && $notification->notifiable?->can('view', $event) ? "/events/{$event->id}" : null,
            ];
        }

        // Import results are Filament notifications, which keep their text in "body" (Task #72).
        return [$data['title'] ?? __('notifications.feed.default_title'), $data['message'] ?? $data['body'] ?? '', null];
    }
}

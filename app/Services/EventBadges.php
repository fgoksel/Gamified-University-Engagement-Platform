<?php

namespace App\Services;

use App\Models\Badge;
use App\Models\BadgeAward;
use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The badge of an event (Task #103, N10): whoever may edit the event makes, changes or removes it.
 * Who may edit is the EventPolicy; this class decides what happens to the students.
 *
 * - A new badge goes at once to the students the event already gave points to: they met its
 *   condition before it existed.
 * - Changing the name or the text changes it for everybody who holds it.
 * - Removing it takes it from everybody who holds it.
 *
 * The event row is locked first, as PointLedger::write locks it, so a credit written at the same
 * moment either shows up here or finds the badge there.
 */
class EventBadges
{
    public function __construct(
        private BadgeAwards $awards,
        private EventEditor $editor,
    ) {}

    /**
     * Make the event's badge, or change it.
     *
     * @return array{badge: Badge, created: bool, awarded: int} awarded: students who got a new badge now
     *
     * @throws ValidationException when the event can no longer be changed
     */
    public function save(User $actor, Event $event, string $name, ?string $description): array
    {
        return DB::transaction(function () use ($actor, $event, $name, $description) {
            $locked = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

            if (! $this->editor->isEditable($locked)) {
                throw ValidationException::withMessages([
                    'badge' => __('events.badge.the_event_cannot_change'),
                ]);
            }

            $attributes = [
                'name' => trim($name),
                'description' => filled($description) ? trim($description) : null,
            ];

            $badge = Badge::query()->where('event_id', $locked->id)->first();

            if ($badge === null) {
                $badge = Badge::create([...$attributes, 'event_id' => $locked->id, 'created_by_id' => $actor->id]);

                return ['badge' => $badge, 'created' => true, 'awarded' => $this->awards->awardToCredited($badge)];
            }

            $badge->update($attributes);

            return ['badge' => $badge, 'created' => false, 'awarded' => 0];
        });
    }

    /**
     * Take the badge away, and with it every award of it.
     *
     * @return int How many students lost it
     */
    public function remove(Event $event): int
    {
        return DB::transaction(function () use ($event) {
            $locked = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            $badge = Badge::query()->where('event_id', $locked->id)->first();

            if ($badge === null) {
                return 0;
            }

            $lost = BadgeAward::query()->where('badge_id', $badge->id)->delete();
            $badge->delete();

            return $lost;
        });
    }
}

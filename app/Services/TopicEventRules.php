<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Topic;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * What changes to the topic tree mean for the events attached to it (Task #66).
 *
 *   archive  : events run in the branch are frozen (not seen, applied to,
 *              credited or managed) until the topic is restored. Nothing about
 *              the events themselves is changed, so restoring brings them back
 *              exactly as they were. The freezing itself lives in EventPolicy,
 *              EventCatalog, EventApplicationService and PointLedger.
 *   move     : refused while an event in the branch is announced to a topic
 *              that would no longer be above it (the audience must stay at or
 *              above the owning topic, Task #64).
 *   delete   : refused while events are run from or announced to the topic;
 *              the database would refuse it anyway, this gives the reason.
 */
class TopicEventRules
{
    /** Statuses of events that are still "live" and so are frozen by an archive. */
    public const LIVE = [Event::STATUS_DRAFT, Event::STATUS_PUBLISHED, Event::STATUS_ON_HOLD];

    /**
     * Events run from this topic or anywhere below it.
     *
     * @return Builder<Event>
     */
    public function eventsRunIn(Topic $topic): Builder
    {
        return Event::query()->whereIn('owning_topic_id', $this->subtreeIds($topic));
    }

    /**
     * How many live events an archive of this topic freezes.
     */
    public function liveEventsIn(Topic $topic): int
    {
        return $this->eventsRunIn($topic)->whereIn('status', self::LIVE)->count();
    }

    /**
     * Why moving $topic under $destination would break an event's audience, if it would.
     *
     * @return list<string>
     */
    public function moveProblems(Topic $topic, ?Topic $destination): array
    {
        $inside = $this->subtreeIds($topic);
        $newAbove = $destination === null ? [] : $destination->lineageIds();
        $stillAbove = array_merge($inside, $newAbove);

        $stranded = $this->eventsRunIn($topic)
            ->whereNotNull('audience_topic_id')
            ->whereNotIn('audience_topic_id', $stillAbove)
            ->whereNotIn('status', [Event::STATUS_CANCELLED, Event::STATUS_COMPLETED])
            ->with('audienceTopic:id,title')
            ->orderBy('event_starts_at')
            ->get(['id', 'title', 'audience_topic_id']);

        if ($stranded->isEmpty()) {
            return [];
        }

        $examples = $stranded->take(3)
            ->map(fn (Event $event) => "\"{$event->title}\" (open to {$event->audienceTopic?->title})")
            ->implode(', ');
        $more = $stranded->count() > 3 ? ' and '.($stranded->count() - 3).' more' : '';

        return [
            "{$stranded->count()} event(s) in this branch are announced to a topic that would no longer be above them: {$examples}{$more}. "
            .'Change their audience, or cancel them, before moving.',
        ];
    }

    /**
     * Why the topic cannot be deleted because of events, if it cannot.
     *
     * @return list<string>
     */
    public function deleteBlockers(Topic $topic): array
    {
        $count = Event::query()
            ->where('owning_topic_id', $topic->id)
            ->orWhere('audience_topic_id', $topic->id)
            ->count();

        return $count > 0
            ? ["{$count} event(s) are run from or announced to it (cancelled and past ones count too)."]
            : [];
    }

    /**
     * @return list<int>
     */
    private function subtreeIds(Topic $topic): array
    {
        return DB::table('topic_closure')->where('ancestor_id', $topic->id)->pluck('descendant_id')->all();
    }
}

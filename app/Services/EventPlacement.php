<?php

namespace App\Services;

use App\Enums\Capability;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Where a person may put an event (Task #38).
 *
 * Owning topic: a topic where the person may create events. Until the event
 * capabilities of Task #65 exist, "may add topics here" (topic.create) is
 * used for this; swap the capability below when #65 is merged.
 *
 * Audience topic: the owning topic itself or any topic above it (Task #64:
 * an event is announced at or above the place it is managed from).
 */
class EventPlacement
{
    /** The capability that allows creating an event at a topic. Replace with the #65 one. */
    public const CREATE_CAPABILITY = Capability::Create;

    public function __construct(private TopicAccess $access) {}

    /**
     * Topics the person may own an event at, in tree order.
     *
     * @return Collection<int, Topic>
     */
    public function owningTopics(User $user): Collection
    {
        return $this->access->visibleTopics($user)
            ->notArchived()
            ->orderBy('depth')
            ->orderBy('title')
            ->get()
            ->filter(fn (Topic $topic) => $this->access->can($user, self::CREATE_CAPABILITY, $topic))
            ->values();
    }

    public function mayOwn(User $user, Topic $topic): bool
    {
        return ! $this->access->isHidden($topic)
            && $this->access->can($user, self::CREATE_CAPABILITY, $topic);
    }

    /**
     * Ids of the topics an event owned at $owning may be announced to:
     * the owning topic first, then each topic above it.
     *
     * @return list<int>
     */
    public function audienceIds(Topic $owning): array
    {
        return $this->access->lineage($owning);
    }

    /**
     * For the form: every owning option with the audiences it allows.
     *
     * @return list<array{id: int, title: string, audiences: list<array{id: int, title: string}>}>
     */
    public function formOptions(User $user): array
    {
        $owning = $this->owningTopics($user);

        $lineages = $owning->mapWithKeys(fn (Topic $topic) => [$topic->id => $this->audienceIds($topic)]);
        $titles = Topic::query()
            ->whereIn('id', $lineages->flatten()->unique()->all())
            ->pluck('title', 'id');

        return $owning->map(fn (Topic $topic) => [
            'id' => $topic->id,
            'title' => $topic->title,
            'audiences' => collect($lineages[$topic->id])
                ->map(fn (int $id) => ['id' => $id, 'title' => (string) $titles[$id]])
                ->all(),
        ])->all();
    }
}

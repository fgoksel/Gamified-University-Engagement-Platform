<?php

namespace App\Services;

use App\Enums\Capability;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Where a person may put an event (Task #38).
 *
 * Owning topic: a topic where the person holds the events.create capability (Task #65).
 *
 * Audience topic: the owning topic itself or any topic above it (Task #64:
 * an event is announced at or above the place it is managed from).
 */
class EventPlacement
{
    /** The capability that allows creating an event at a topic. */
    public const CREATE_CAPABILITY = Capability::EventsCreate;

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

    /**
     * Publishing straight from the form needs events.manage at the owning
     * topic (Task #65); with events.create only, a draft is saved.
     */
    public function mayPublish(User $user, Topic $topic): bool
    {
        return ! $this->access->isHidden($topic)
            && $this->access->can($user, Capability::EventsManage, $topic);
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
     * @return list<array{id: int, title: string, can_publish: bool, audiences: list<array{id: int, title: string}>}>
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
            'can_publish' => $this->mayPublish($user, $topic),
            'audiences' => collect($lineages[$topic->id])
                ->map(fn (int $id) => ['id' => $id, 'title' => (string) $titles[$id]])
                ->all(),
        ])->all();
    }
}

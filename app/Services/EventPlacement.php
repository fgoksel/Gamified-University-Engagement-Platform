<?php

namespace App\Services;

use App\Enums\Capability;
use App\Enums\UserRole;
use App\Models\RoleAssignment;
use App\Models\RoleDefinition;
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
 *
 * The form shows each topic by its full path in the tree, and each audience with the
 * number of students who will see the event (Task #74). Students see an event only when
 * they are assigned to its audience topic itself, with the right to view events there
 * (Task #71), so that is exactly what is counted.
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
     * @return list<array{id: int, title: string, path: list<string>, can_publish: bool, audiences: list<array{id: int, title: string, path: list<string>, students: int}>}>
     */
    public function formOptions(User $user): array
    {
        $owning = $this->owningTopics($user);

        return $this->options($owning, fn (Topic $topic) => $this->mayPublish($user, $topic));
    }

    /**
     * One owning option, for a page that must show a topic the person may not pick
     * (the edit page of a published event keeps its topic).
     *
     * @return array{id: int, title: string, path: list<string>, can_publish: bool, audiences: list<array{id: int, title: string, path: list<string>, students: int}>}
     */
    public function option(Topic $topic, bool $canPublish): array
    {
        return $this->options(collect([$topic]), fn () => $canPublish)[0];
    }

    /**
     * Owning options with their paths and audiences, in a fixed number of queries
     * whatever the number of topics: one for the titles, one for the student counts.
     *
     * @param  Collection<int, Topic>  $owning
     * @param  callable(Topic): bool  $canPublish
     * @return list<array<string, mixed>>
     */
    private function options(Collection $owning, callable $canPublish): array
    {
        $lineages = $owning->mapWithKeys(fn (Topic $topic) => [$topic->id => $this->audienceIds($topic)]);
        $ids = $lineages->flatten()->unique()->values()->all();
        $titles = Topic::query()->whereIn('id', $ids)->pluck('title', 'id');
        $students = $this->studentCounts($ids);

        // Lineages are nearest first; a path reads from the root down.
        $path = fn (array $lineage) => array_map(fn (int $id) => (string) $titles[$id], array_reverse($lineage));

        return $owning
            ->map(fn (Topic $topic) => [
                'id' => $topic->id,
                'title' => $topic->title,
                'path' => $path($lineages[$topic->id]),
                'can_publish' => $canPublish($topic),
                'audiences' => collect($lineages[$topic->id])
                    ->map(fn (int $id, int $i) => [
                        'id' => $id,
                        'title' => (string) $titles[$id],
                        'path' => $path(array_slice($lineages[$topic->id], $i)),
                        'students' => $students[$id] ?? 0,
                    ])
                    ->all(),
            ])
            // Sorted by path, so the list reads like the tree.
            ->sortBy(fn (array $option) => implode("\u{0}", $option['path']), SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    /**
     * Per topic: how many active students would see an event announced there, i.e. students
     * assigned to that topic itself with a role that allows viewing events (Task #71).
     *
     * @param  list<int>  $topicIds
     * @return array<int, int>
     */
    public function studentCounts(array $topicIds): array
    {
        if ($topicIds === []) {
            return [];
        }

        // Capabilities are a JSON list; there are few role definitions, so they are read once.
        $roles = RoleDefinition::query()->get()
            ->filter(fn (RoleDefinition $role) => $role->has(Capability::EventsView))
            ->modelKeys();

        if ($roles === []) {
            return [];
        }

        return RoleAssignment::query()
            ->active()
            ->whereIn('topic_id', $topicIds)
            ->whereIn('role_definition_id', $roles)
            ->whereHas('user', fn ($q) => $q->where('status', 'active')->role(UserRole::Student->value))
            ->selectRaw('topic_id, count(distinct user_id) as students')
            ->groupBy('topic_id')
            ->pluck('students', 'topic_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }
}

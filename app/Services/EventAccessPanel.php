<?php

namespace App\Services;

use App\Enums\Capability;
use App\Models\Event;
use App\Models\RoleAssignment;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * The path and the "Who has access" panel of an event page (Task #75, 26 October, Q2).
 *
 * Nobody gets access to one event: a right is given on a node of the topic tree and covers the
 * events there and below. So the people who can manage an event are those who hold "Manage
 * events" on its owning node or on a node above it, and each is shown with the node the right
 * comes from. A role that has ended is not listed, and neither is a deactivated account. System
 * administrators can manage every event but hold no node, so they are not listed.
 */
class EventAccessPanel
{
    public function __construct(private TopicAccess $access) {}

    /**
     * Where the event sits in the tree, from the top down to its owning node.
     *
     * @return list<array{id: int, title: string}>
     */
    public function path(Event $event): array
    {
        $lineage = $this->access->lineage($event->owningTopic);
        $titles = Topic::query()->whereIn('id', $lineage)->pluck('title', 'id');

        return array_map(
            fn (int $id) => ['id' => $id, 'title' => (string) $titles[$id]],
            array_reverse($lineage),
        );
    }

    /**
     * Everyone who can manage the event, each with the node (and role) the right comes from, by
     * name. Null for a viewer who runs no part of the event and may not see who has access at its
     * node: they get the path only.
     *
     * @return list<array{id: int, name: string, is_you: bool, sources: list<array{role: string, node: array{id: int|null, title: string}}>}>|null
     */
    public function managers(User $viewer, Event $event): ?array
    {
        if (! $this->mayList($viewer, $event)) {
            return null;
        }

        $topic = $event->owningTopic;
        $lineage = $this->access->lineage($topic);
        $titles = Topic::query()->whereIn('id', $lineage)->pluck('title', 'id');
        // The nodes of the path the viewer may open: a node above that is not named.
        $open = $this->access->breadcrumbs($viewer, $topic)->pluck('id')->map(fn ($id) => (int) $id)->all();

        return RoleAssignment::query()
            ->active()
            ->whereIn('topic_id', $lineage)
            ->whereHas('user', fn ($users) => $users->where('status', 'active'))
            ->with(['user:id,name', 'role'])
            ->get()
            ->filter(fn (RoleAssignment $assignment) => in_array(Capability::EventsManage->value, Capability::normalise($assignment->role->capabilities ?? []), true))
            ->groupBy('user_id')
            ->map(fn ($assignments) => [
                'id' => $assignments->first()->user->id,
                'name' => $assignments->first()->user->name,
                'is_you' => $assignments->first()->user_id === $viewer->id,
                // Nearest node first
                'sources' => $assignments
                    ->sortBy(fn (RoleAssignment $assignment) => array_search($assignment->topic_id, $lineage, true))
                    ->map(fn (RoleAssignment $assignment) => [
                        'role' => $assignment->role->name,
                        'node' => in_array($assignment->topic_id, $open, true)
                            ? ['id' => $assignment->topic_id, 'title' => (string) $titles[$assignment->topic_id]]
                            : ['id' => null, 'title' => __('events.access_panel.a_branch_above_your_access')],
                    ])
                    ->values()
                    ->all(),
            ])
            ->sortBy(fn (array $person) => [mb_strtolower($person['name']), $person['id']])
            ->values()
            ->all();
    }

    /**
     * Whoever runs some part of the event, or may see who has access at its node (the same people
     * who see the Access panel in the explorer). A student who only applies sees the path.
     */
    private function mayList(User $viewer, Event $event): bool
    {
        return Gate::forUser($viewer)->allows('staff', $event)
            || $this->access->can($viewer, Capability::AccessView, $event->owningTopic);
    }
}

<?php

namespace App\Services;

use App\Enums\Capability;
use App\Models\RoleAssignment;
use App\Models\RoleDefinition;
use App\Models\Topic;
use App\Models\User;
use App\Policies\RoleAssignmentPolicy;
use App\Support\Like;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Read side of the explorer: everything shown to a person is built here from
 * TopicAccess, so the page, the lazy tree, search, counts and the access
 * panel can never show more than opening the topic would.
 */
class TopicBrowser
{
    public function __construct(
        private TopicAccess $access,
        private RoleAssignmentPolicy $assignments,
    ) {}

    /**
     * One node of the left-hand tree.
     *
     * @return array{id: int, title: string, code: ?string, children_count: int}
     */
    public function node(Topic $topic): array
    {
        return [
            'id' => $topic->id,
            'title' => $topic->title,
            'code' => $topic->code,
            'children_count' => $topic->children_count ?? $topic->children()->whereNull('archived_at')->count(),
        ];
    }

    /**
     * Children of a topic the person may see (all of them: what is below a
     * visible topic is visible).
     *
     * @return list<array{id: int, title: string, code: ?string, children_count: int}>
     */
    public function children(Topic $topic): array
    {
        return $topic->children()
            ->whereNull('archived_at')
            ->withCount(['children' => fn ($query) => $query->whereNull('archived_at')])
            ->orderBy('title')
            ->orderBy('id')
            ->get()
            ->map(fn (Topic $child) => $this->node($child))
            ->all();
    }

    /**
     * The starting nodes of the tree.
     *
     * @return list<array{id: int, title: string, code: ?string, children_count: int}>
     */
    public function entries(User $user): array
    {
        $topics = $this->access->entryTopics($user);
        $counts = Topic::query()->whereIn('id', $topics->pluck('id'))->withCount(['children' => fn ($query) => $query->whereNull('archived_at')])->pluck('children_count', 'id');

        return $topics->map(fn (Topic $topic) => $this->node($topic->setAttribute('children_count', $counts[$topic->id] ?? 0)))->all();
    }

    /**
     * Children already loaded for the chain from the entry point down to the
     * selected topic, so a deep link opens with the tree expanded.
     *
     * @return array<int, list<array{id: int, title: string, code: ?string, children_count: int}>>
     */
    public function expandedFor(User $user, Topic $selected): array
    {
        $loaded = [];

        foreach ($this->access->breadcrumbs($user, $selected) as $topic) {
            $loaded[$topic->id] = $this->children($topic);
        }

        return $loaded;
    }

    /**
     * What the person may do at this topic, for showing or hiding controls.
     * The server checks every action again; this never grants anything.
     *
     * @return array<string, bool>
     */
    public function abilities(User $user, Topic $topic): array
    {
        $has = fn (Capability $capability) => $this->access->can($user, $capability, $topic);

        return [
            'create' => $has(Capability::Create),
            'edit' => $has(Capability::Edit) || ($topic->created_by_id === $user->id && $has(Capability::Create)),
            'organise' => $has(Capability::Organise),
            'archive' => $has(Capability::Archive),
            // Only System Admins may try a permanent delete; the server then refuses used topics.
            'delete' => $this->access->isSystemAdmin($user),
            'view_access' => $has(Capability::AccessView),
            'assign' => $has(Capability::AccessAssign),
            'end' => $has(Capability::AccessEnd),
            'define_roles' => $has(Capability::DefineRoles),
        ];
    }

    /**
     * Archived topics the person may restore: the top of each archived
     * branch (topics inside it are archived along with it), with the
     * ancestors the person can see.
     *
     * @return list<array<string, mixed>>
     */
    public function archived(User $user): array
    {
        $candidates = Topic::query()
            ->whereNotNull('archived_at')
            ->whereNotIn('topics.id', DB::table('topic_closure as c')
                ->join('topics as a', 'a.id', '=', 'c.ancestor_id')
                ->where('c.distance', '>', 0)
                ->whereNotNull('a.archived_at')
                ->select('c.descendant_id'))
            ->with('archivedBy:id,name')
            ->orderByDesc('archived_at')
            ->limit(500)
            ->get();

        return $candidates
            ->filter(fn (Topic $topic) => $this->access->can($user, Capability::Archive, $topic))
            ->take(100)
            ->map(fn (Topic $topic) => [
                'id' => $topic->id,
                'title' => $topic->title,
                'code' => $topic->code,
                'trail' => $this->access->breadcrumbs($user, $topic)->slice(0, -1)->pluck('title')->values()->all(),
                'archived_at' => $topic->archived_at?->toIso8601String(),
                'archived_by' => $topic->archivedBy?->name,
                'topics_inside' => DB::table('topic_closure')->where('ancestor_id', $topic->id)->where('distance', '>', 0)->count(),
                'can_delete' => $this->access->isSystemAdmin($user),
            ])
            ->values()
            ->all();
    }

    /**
     * Whole-branch listing: every topic below, with the path from the
     * selected topic, paginated.
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, last_page: int, per_page: int}
     */
    public function branch(User $user, Topic $topic, ?string $search, int $page): array
    {
        $query = $this->access->visibleTopics($user)->below($topic)
            ->when($search !== null && $search !== '', fn ($q) => Like::contains($q, ['title', 'code'], $search))
            ->orderBy('depth')->orderBy('title')->orderBy('id');

        /** @var LengthAwarePaginator<int, Topic> $paginator */
        $paginator = $query->paginate((int) config('topics.page_size'), ['*'], 'page', $page);

        $trails = $this->trails($topic, $paginator->getCollection());

        return [
            'items' => $paginator->getCollection()->map(fn (Topic $item) => [
                'id' => $item->id,
                'title' => $item->title,
                'code' => $item->code,
                'trail' => $trails[$item->id] ?? [],
            ])->values()->all(),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
        ];
    }

    /**
     * Search across everything the person may see. Results carry only the
     * ancestors the person may see.
     *
     * @return list<array{id: int, title: string, code: ?string, trail: list<string>}>
     */
    public function search(User $user, string $term): array
    {
        $term = trim($term);

        if (mb_strlen($term) < 2) {
            return [];
        }

        $query = $this->access->visibleTopics($user);
        Like::contains($query, ['title', 'code'], $term);

        return $query
            ->orderBy('depth')->orderBy('title')->orderBy('id')
            ->limit(20)
            ->get()
            ->map(fn (Topic $topic) => [
                'id' => $topic->id,
                'title' => $topic->title,
                'code' => $topic->code,
                'trail' => $this->access->breadcrumbs($user, $topic)->slice(0, -1)->pluck('title')->values()->all(),
            ])
            ->all();
    }

    /**
     * Counts for a topic. People and assignments are counted separately: one
     * person with two roles is one person and two assignments. Only for
     * people who may see who has access.
     *
     * @return array<string, int>
     */
    public function counts(User $user, Topic $topic): array
    {
        $counts = [
            'topics_below' => Topic::query()->notArchived()->below($topic)->count(),
        ];

        if (! $this->access->can($user, Capability::AccessView, $topic)) {
            return $counts;
        }

        $here = RoleAssignment::query()->active()->where('topic_id', $topic->id);
        $branch = RoleAssignment::query()->active()->whereIn('topic_id', Topic::query()->notArchived()->withinBranch($topic)->select('topics.id'));
        $effective = RoleAssignment::query()->active()->whereIn('topic_id', $this->visibleLineage($user, $topic));

        return [
            ...$counts,
            'assignments_here' => (clone $here)->count(),
            'people_here' => (clone $here)->distinct()->count('user_id'),
            'assignments_effective' => (clone $effective)->count(),
            'people_effective' => (clone $effective)->distinct()->count('user_id'),
            'assignments_branch' => (clone $branch)->count(),
            'people_branch' => (clone $branch)->distinct()->count('user_id'),
        ];
    }

    /**
     * The Access panel: assignments made here, assignments inherited from
     * ancestors the person may see, recent history, and the roles the person
     * could give here.
     *
     * @return array<string, mixed>
     */
    public function access(User $user, Topic $topic): array
    {
        $visibleIds = $this->visibleLineage($user, $topic);
        $titles = Topic::query()->whereIn('id', $visibleIds)->pluck('title', 'id');

        $rows = RoleAssignment::query()
            ->active()
            ->whereIn('topic_id', $visibleIds)
            ->with(['user:id,name,email,status', 'role', 'grantedBy:id,name'])
            ->orderBy('topic_id')
            ->orderBy('id')
            ->get();

        $format = fn (RoleAssignment $a) => $this->assignmentRow($user, $a, $titles[$a->topic_id] ?? null);

        $history = RoleAssignment::query()
            ->where('topic_id', $topic->id)
            ->whereNotNull('ended_at')
            ->with(['user:id,name,email,status', 'role', 'grantedBy:id,name', 'endedBy:id,name'])
            ->latest('ended_at')->latest('id')
            ->limit(20)
            ->get()
            ->map(fn (RoleAssignment $a) => [
                ...$this->assignmentRow($user, $a, $titles[$a->topic_id] ?? null),
                'ended_at' => $a->ended_at?->toIso8601String(),
                'ended_reason' => $a->ended_reason,
                'ended_by' => $a->endedBy?->name,
                'replaced_by_assignment_id' => $a->replaced_by_assignment_id,
            ])->values()->all();

        return [
            'here' => $rows->where('topic_id', $topic->id)->map($format)->values()->all(),
            'inherited' => $rows->where('topic_id', '!=', $topic->id)->map($format)->values()->all(),
            'history' => $history,
            'roles' => $this->access->can($user, Capability::AccessAssign, $topic) ? $this->assignableRoles($user, $topic) : [],
        ];
    }

    /**
     * Roles the person could give at the topic: not archived, usable at this
     * place, and within what they may pass on there.
     *
     * @return list<array<string, mixed>>
     */
    public function assignableRoles(User $user, Topic $topic): array
    {
        $lineage = $this->access->lineage($topic);

        return RoleDefinition::query()
            ->usable()
            ->where(fn ($q) => $q->whereNull('topic_id')->orWhereIn('topic_id', $lineage))
            ->orderBy('name')
            ->get()
            ->filter(fn (RoleDefinition $role) => $this->assignments->giveRole($user, $topic, $role)->allowed())
            ->map(fn (RoleDefinition $role) => $this->roleSummary($role))
            ->values()
            ->all();
    }

    /**
     * Roles shown on the roles page: global roles, and branch roles whose
     * branch the person can see or that apply inside their branches. The
     * branch name of a role above the person's entry point is not revealed.
     *
     * @return list<array<string, mixed>>
     */
    public function roleList(User $user): array
    {
        $isAdmin = $this->access->isSystemAdmin($user);
        $granted = $this->access->grantedTopicIds($user);

        $scopeFilter = null;
        if (! $isAdmin) {
            $inside = DB::table('topic_closure')->whereIn('ancestor_id', $granted)->pluck('descendant_id')->all();
            $above = DB::table('topic_closure')->whereIn('descendant_id', $granted)->pluck('ancestor_id')->all();
            $scopeFilter = array_values(array_unique([...$inside, ...$above]));
        }

        $roles = RoleDefinition::query()
            ->when(! $isAdmin, fn ($q) => $q->where(fn ($w) => $w->whereNull('topic_id')->orWhereIn('topic_id', $scopeFilter)))
            ->with('topic:id,title')
            ->withCount(['assignments as active_assignments' => fn ($q) => $q->whereNull('ended_at')])
            ->orderBy('name')->orderBy('id')
            ->get();

        $visible = $isAdmin ? null : $this->access->visibleTopics($user)->whereIn('id', $roles->pluck('topic_id')->filter())->pluck('id')->all();

        return $roles->map(function (RoleDefinition $role) use ($user, $isAdmin, $visible) {
            $scopeVisible = $role->topic_id === null || $isAdmin || in_array($role->topic_id, $visible ?? [], true);

            return [
                ...$this->roleSummary($role),
                'scope' => $role->topic_id === null ? null : [
                    'id' => $scopeVisible ? $role->topic_id : null,
                    'title' => $scopeVisible ? $role->topic?->title : 'a branch above your access',
                ],
                'active_assignments' => $role->active_assignments,
                'editable' => $this->canEditRole($user, $role),
            ];
        })->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function roleSummary(RoleDefinition $role): array
    {
        return [
            'id' => $role->id,
            'name' => $role->name,
            'description' => $role->description,
            'capabilities' => $role->capabilityList(),
            'delegable' => $role->delegableList(),
            'archived' => $role->isArchived(),
            'global' => $role->isGlobal(),
        ];
    }

    /**
     * Whether the person may open the editor for a role. The server decides
     * again on save, including the delegation ceiling.
     */
    public function canEditRole(User $user, RoleDefinition $role): bool
    {
        if ($role->isGlobal()) {
            return $this->access->isSystemAdmin($user);
        }

        return $this->access->can($user, Capability::DefineRoles, $role->topic);
    }

    /**
     * Topics where the person may define a role, for the scope picker.
     *
     * @return list<array{id: int, title: string, trail: list<string>}>
     */
    public function definableScopes(User $user): array
    {
        $topics = $this->access->isSystemAdmin($user)
            ? Topic::query()->orderBy('depth')->orderBy('title')->limit(200)->get()
            : $this->access->visibleTopics($user)->orderBy('depth')->orderBy('title')->limit(500)->get()
                ->filter(fn (Topic $topic) => $this->access->can($user, Capability::DefineRoles, $topic));

        return $topics->take(200)->map(fn (Topic $topic) => [
            'id' => $topic->id,
            'title' => $topic->title,
            'trail' => $this->access->breadcrumbs($user, $topic)->slice(0, -1)->pluck('title')->values()->all(),
        ])->values()->all();
    }

    /**
     * Active accounts matching a search, for choosing a recipient. Name and
     * email only, and only for people who may give roles here.
     *
     * @return list<array{id: int, name: string, email: string}>
     */
    public function candidates(User $actor, Topic $topic, string $term): array
    {
        $term = trim($term);

        if (mb_strlen($term) < 2 || ! $this->access->can($actor, Capability::AccessAssign, $topic)) {
            return [];
        }

        $query = User::query()->where('status', 'active')->whereKeyNot($actor->id);
        Like::contains($query, ['name', 'email'], $term);

        return $query
            ->orderBy('name')
            ->limit(10)
            ->get(['id', 'name', 'email'])
            ->map(fn (User $candidate) => ['id' => $candidate->id, 'name' => $candidate->name, 'email' => $candidate->email])
            ->all();
    }

    /**
     * Ids of the topic and those ancestors that the person may see.
     *
     * @return list<int>
     */
    private function visibleLineage(User $user, Topic $topic): array
    {
        return $this->access->breadcrumbs($user, $topic)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function assignmentRow(User $viewer, RoleAssignment $assignment, ?string $topicTitle): array
    {
        $canEnd = $assignment->isActive() && Gate::forUser($viewer)->allows('end', $assignment);

        return [
            'id' => $assignment->id,
            'version' => $assignment->version,
            'topic' => ['id' => $assignment->topic_id, 'title' => $topicTitle],
            'user' => [
                'id' => $assignment->user->id,
                'name' => $assignment->user->name,
                'email' => $assignment->user->email,
                'active' => $assignment->user->status === 'active',
            ],
            'role' => ['id' => $assignment->role->id, 'name' => $assignment->role->name, 'capabilities' => $assignment->role->capabilityList()],
            'granted_by' => $assignment->grantedBy?->name,
            'started_at' => $assignment->started_at?->toIso8601String(),
            'is_you' => $assignment->user_id === $viewer->id,
            'can_end' => $canEnd,
            'can_replace' => $canEnd && $this->assignments->giveRole($viewer, $assignment->topic ?? Topic::find($assignment->topic_id), $assignment->role)->allowed(),
        ];
    }

    /**
     * Context for each listed topic: titles of the topics between the
     * selected one (exclusive) and the item (exclusive).
     *
     * @param  Collection<int, Topic>  $items
     * @return array<int, list<string>>
     */
    private function trails(Topic $root, Collection $items): array
    {
        if ($items->isEmpty()) {
            return [];
        }

        $rows = DB::table('topic_closure as up')
            ->join('topic_closure as inside', 'inside.descendant_id', '=', 'up.ancestor_id')
            ->join('topics', 'topics.id', '=', 'up.ancestor_id')
            ->where('inside.ancestor_id', $root->id)
            ->whereIn('up.descendant_id', $items->pluck('id'))
            ->where('up.distance', '>', 0)
            ->where('inside.distance', '>', 0)
            ->orderByDesc('up.distance')
            ->get(['up.descendant_id', 'topics.title']);

        $trails = [];
        foreach ($rows as $row) {
            $trails[$row->descendant_id][] = $row->title;
        }

        return $trails;
    }
}

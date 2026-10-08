<?php

namespace App\Services;

use App\Enums\Capability;
use App\Enums\UserRole;
use App\Models\RoleAssignment;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The one place that answers "what may this person do at this topic?".
 *
 * For a topic T the answer is the union of the capabilities of every active
 * assignment the person holds on T or on any ancestor of T. Roles never
 * subtract. A System Admin (the global "admin" account role of an active
 * account) may do everything; a topic role, whatever its name, never grants
 * that account role.
 *
 * Results are remembered for the current request only and dropped whenever an
 * assignment, role or topic changes (see flush()).
 */
class TopicAccess
{
    /** @var array<int, Collection<int, RoleAssignment>> */
    private array $assignments = [];

    /** @var array<int, list<int>> */
    private array $lineages = [];

    /** @var array<int, bool> */
    private array $hidden = [];

    public function flush(): void
    {
        $this->assignments = [];
        $this->lineages = [];
        $this->hidden = [];
    }

    /**
     * True when the topic, or any topic above it, is archived: it is then
     * left out of normal browsing for everybody, System Admins included.
     */
    public function isHidden(Topic $topic): bool
    {
        return $this->hidden[$topic->id] ??= DB::table('topic_closure as c')
            ->join('topics as a', 'a.id', '=', 'c.ancestor_id')
            ->where('c.descendant_id', $topic->id)
            ->whereNotNull('a.archived_at')
            ->exists();
    }

    public function isActive(User $user): bool
    {
        return $user->status === 'active';
    }

    public function isSystemAdmin(User $user): bool
    {
        return $this->isActive($user) && $user->hasRole(UserRole::Admin);
    }

    /**
     * The active assignments of an active account, with their roles.
     *
     * @return Collection<int, RoleAssignment>
     */
    public function assignmentsOf(User $user): Collection
    {
        if (! $this->isActive($user)) {
            return collect();
        }

        return $this->assignments[$user->id] ??= RoleAssignment::query()
            ->active()
            ->where('user_id', $user->id)
            ->with('role')
            ->get();
    }

    /**
     * Ids of the topic and its ancestors, nearest first.
     *
     * @return list<int>
     */
    public function lineage(Topic $topic): array
    {
        return $this->lineages[$topic->id] ??= $topic->lineageIds();
    }

    /**
     * Assignments covering a topic: held on it or on an ancestor.
     *
     * @return Collection<int, RoleAssignment>
     */
    public function coveringAssignments(User $user, Topic $topic): Collection
    {
        $lineage = $this->lineage($topic);

        return $this->assignmentsOf($user)
            ->filter(fn (RoleAssignment $assignment) => in_array($assignment->topic_id, $lineage, true))
            ->values();
    }

    /**
     * @return list<string>
     */
    public function capabilities(User $user, Topic $topic): array
    {
        if ($this->isSystemAdmin($user)) {
            return Capability::values();
        }

        return Capability::normalise(
            $this->coveringAssignments($user, $topic)->flatMap(fn (RoleAssignment $a) => $a->role->capabilities ?? [])
        );
    }

    /**
     * What the person may pass on to others at this topic (the delegation ceiling).
     *
     * @return list<string>
     */
    public function delegable(User $user, Topic $topic): array
    {
        if ($this->isSystemAdmin($user)) {
            return Capability::values();
        }

        $held = $this->capabilities($user, $topic);

        return Capability::normalise(
            $this->coveringAssignments($user, $topic)
                ->flatMap(fn (RoleAssignment $a) => $a->role->delegable ?? [])
                ->filter(fn ($value) => in_array($value, $held, true))
        );
    }

    public function can(User $user, Capability $capability, Topic $topic): bool
    {
        return in_array($capability->value, $this->capabilities($user, $topic), true);
    }

    public function canView(User $user, Topic $topic): bool
    {
        return $this->can($user, Capability::View, $topic) && ! $this->isHidden($topic);
    }

    /**
     * Whether the person holds the capability anywhere at all.
     */
    public function canAnywhere(User $user, Capability $capability): bool
    {
        if ($this->isSystemAdmin($user)) {
            return true;
        }

        return $this->assignmentsOf($user)->contains(fn (RoleAssignment $a) => $a->role->has($capability));
    }

    /**
     * Topics where the person has an active assignment that allows viewing.
     * Everything below these is visible; nothing above or beside them is.
     *
     * @return list<int>
     */
    public function grantedTopicIds(User $user): array
    {
        return $this->assignmentsOf($user)
            ->filter(fn (RoleAssignment $a) => $a->role->has(Capability::View))
            ->pluck('topic_id')
            ->unique()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Every topic the person may see. Use this for lists, search and counts so
     * they apply the same rule as opening a topic.
     *
     * @return Builder<Topic>
     */
    public function visibleTopics(User $user): Builder
    {
        $query = Topic::query()->notArchived();

        if ($this->isSystemAdmin($user)) {
            return $query;
        }

        $granted = $this->grantedTopicIds($user);

        if ($granted === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('topics.id', DB::table('topic_closure')
            ->select('descendant_id')
            ->whereIn('ancestor_id', $granted));
    }

    /**
     * Where the explorer starts: the System Admin's roots, or the person's
     * assigned topics that are not already inside another assigned topic.
     *
     * @return Collection<int, Topic>
     */
    public function entryTopics(User $user): Collection
    {
        if ($this->isSystemAdmin($user)) {
            return Topic::query()->notArchived()->whereNull('parent_id')->orderBy('title')->orderBy('id')->get();
        }

        $granted = $this->grantedTopicIds($user);

        if ($granted === []) {
            return collect();
        }

        $covered = DB::table('topic_closure')
            ->whereIn('descendant_id', $granted)
            ->whereIn('ancestor_id', $granted)
            ->where('distance', '>', 0)
            ->pluck('descendant_id')
            ->all();

        return Topic::query()
            ->notArchived()
            ->whereIn('id', array_values(array_diff($granted, $covered)))
            ->orderBy('title')
            ->orderBy('id')
            ->get();
    }

    /**
     * The ancestors the person may see, root-most first, the topic itself last.
     * Ancestors above the person's entry point are left out.
     *
     * @return Collection<int, Topic>
     */
    public function breadcrumbs(User $user, Topic $topic): Collection
    {
        $lineage = $this->lineage($topic);

        if (! $this->isSystemAdmin($user)) {
            $granted = $this->grantedTopicIds($user);
            $top = null;
            foreach ($lineage as $index => $id) {
                if (in_array($id, $granted, true)) {
                    $top = $index;
                }
            }
            $lineage = $top === null ? [] : array_slice($lineage, 0, $top + 1);
        }

        $topics = Topic::query()->whereIn('id', $lineage)->get()->keyBy('id');

        return collect(array_reverse($lineage))->map(fn (int $id) => $topics[$id])->values();
    }

    /**
     * The nearest visible ancestor-or-self where the person's access starts,
     * i.e. the first breadcrumb. Used to decide which inherited assignments
     * may be shown to them.
     */
    public function entryOf(User $user, Topic $topic): ?Topic
    {
        return $this->breadcrumbs($user, $topic)->first();
    }
}

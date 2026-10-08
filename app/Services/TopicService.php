<?php

namespace App\Services;

use App\Models\RoleAssignment;
use App\Models\RoleDefinition;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The only place that writes to the topic tree.
 *
 * Every method asks the policy first (AuthorizationException), then checks the
 * data rules (ValidationException). Topics are not deleted by anything here.
 */
class TopicService
{
    public function __construct(private TopicAccess $access) {}

    /**
     * Add a topic under $parent, or a new root when $parent is null.
     *
     * @param  array{title?: mixed, code?: mixed, description?: mixed}  $data
     *
     * @throws AuthorizationException|ValidationException
     */
    public function create(User $actor, ?Topic $parent, array $data): Topic
    {
        Gate::forUser($actor)->authorize('create', [Topic::class, $parent]);

        $fields = $this->validated($data);

        return DB::transaction(function () use ($actor, $parent, $fields) {
            if ($parent !== null) {
                // Lock the parent so a concurrent move cannot change its depth under us.
                $parent = Topic::query()->whereKey($parent->id)->lockForUpdate()->firstOrFail();
            }

            $depth = $parent === null ? 0 : $parent->depth + 1;

            if ($depth >= config('topics.max_depth')) {
                throw ValidationException::withMessages([
                    'title' => 'Topics can be nested at most '.config('topics.max_depth').' levels deep.',
                ]);
            }

            $topic = Topic::create([
                ...$fields,
                'parent_id' => $parent?->id,
                'depth' => $depth,
                'created_by_id' => $actor->id,
            ]);

            $rows = [['ancestor_id' => $topic->id, 'descendant_id' => $topic->id, 'distance' => 0]];
            if ($parent !== null) {
                foreach (DB::table('topic_closure')->where('descendant_id', $parent->id)->get() as $row) {
                    $rows[] = ['ancestor_id' => $row->ancestor_id, 'descendant_id' => $topic->id, 'distance' => $row->distance + 1];
                }
            }
            DB::table('topic_closure')->insert($rows);

            TopicAudit::record($actor, 'topic.created', $topic->id, 'topic', $topic->id, null, $this->snapshot($topic));
            $this->access->flush();

            return $topic;
        });
    }

    /**
     * @param  array{title?: mixed, code?: mixed, description?: mixed}  $data
     *
     * @throws AuthorizationException|ValidationException
     */
    public function update(User $actor, Topic $topic, array $data): Topic
    {
        Gate::forUser($actor)->authorize('update', $topic);

        $fields = $this->validated($data);

        return DB::transaction(function () use ($actor, $topic, $fields) {
            $topic = Topic::query()->whereKey($topic->id)->lockForUpdate()->firstOrFail();
            $before = $this->snapshot($topic);

            $topic->update($fields);

            TopicAudit::record($actor, 'topic.updated', $topic->id, 'topic', $topic->id, $before, $this->snapshot($topic));

            return $topic;
        });
    }

    /**
     * What a move would change, for the confirmation step. Counts only: no
     * names of people outside the mover's own reach are revealed.
     *
     * @return array{blocked: list<string>, gain_people: int, lose_people: int, topics_moved: int, new_depth: int}
     *
     * @throws AuthorizationException
     */
    public function movePreview(User $actor, Topic $topic, ?Topic $destination): array
    {
        Gate::forUser($actor)->authorize('move', [$topic, $destination]);

        $blocked = $this->moveProblems($topic, $destination);

        $oldAncestors = array_slice($this->access->lineage($topic), 1);
        $newAncestors = $destination === null ? [] : $destination->lineageIds();

        $holders = fn (array $topicIds) => RoleAssignment::query()
            ->active()
            ->whereIn('topic_id', $topicIds)
            ->whereHas('user', fn ($query) => $query->where('status', 'active'))
            ->pluck('user_id')
            ->unique();

        $before = $holders($oldAncestors);
        $after = $holders($newAncestors);

        return [
            'blocked' => $blocked,
            'gain_people' => $after->diff($before)->count(),
            'lose_people' => $before->diff($after)->count(),
            'topics_moved' => DB::table('topic_closure')->where('ancestor_id', $topic->id)->count(),
            'new_depth' => $destination === null ? 0 : $destination->depth + 1,
        ];
    }

    /**
     * Move a topic, with everything below it, to another parent (null = top level).
     * One transaction: either the whole subtree moves or nothing changes.
     *
     * @throws AuthorizationException|ValidationException
     */
    public function move(User $actor, Topic $topic, ?Topic $destination): Topic
    {
        Gate::forUser($actor)->authorize('move', [$topic, $destination]);

        return DB::transaction(function () use ($actor, $topic, $destination) {
            $ids = array_filter([$topic->id, $destination?->id]);
            $locked = Topic::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $topic = $locked[$topic->id];
            $destination = $destination === null ? null : $locked[$destination->id];

            // Authority is checked again on the locked, current data.
            $this->access->flush();
            Gate::forUser($actor)->authorize('move', [$topic, $destination]);

            $problems = $this->moveProblems($topic, $destination);
            if ($problems !== []) {
                throw ValidationException::withMessages(['destination' => $problems]);
            }

            $before = $this->snapshot($topic);
            $subtree = DB::table('topic_closure')->where('ancestor_id', $topic->id)->get(['descendant_id', 'distance']);
            $subtreeIds = $subtree->pluck('descendant_id')->all();
            $newDepth = $destination === null ? 0 : $destination->depth + 1;
            $delta = $newDepth - $topic->depth;

            // Cut the subtree from its old ancestors, then hang it under the new ones.
            DB::table('topic_closure')
                ->whereIn('descendant_id', $subtreeIds)
                ->whereNotIn('ancestor_id', $subtreeIds)
                ->delete();

            if ($destination !== null) {
                $rows = [];
                foreach (DB::table('topic_closure')->where('descendant_id', $destination->id)->get() as $ancestor) {
                    foreach ($subtree as $node) {
                        $rows[] = [
                            'ancestor_id' => $ancestor->ancestor_id,
                            'descendant_id' => $node->descendant_id,
                            'distance' => $ancestor->distance + 1 + $node->distance,
                        ];
                    }
                }
                foreach (array_chunk($rows, 500) as $chunk) {
                    DB::table('topic_closure')->insert($chunk);
                }
            }

            DB::table('topics')->whereIn('id', $subtreeIds)->increment('depth', $delta);
            $topic->parent_id = $destination?->id;
            $topic->save();

            TopicAudit::record($actor, 'topic.moved', $topic->id, 'topic', $topic->id, $before, $this->snapshot($topic->refresh()));
            $this->access->flush();

            return $topic;
        });
    }

    /**
     * Hide a topic and everything below it from normal browsing. Nothing is
     * deleted or ended: roles, history and descendants stay as they are, and
     * the topic can be restored. Roles inside cannot be changed while it is
     * archived, because nobody can open it.
     *
     * @throws AuthorizationException|ValidationException
     */
    public function archive(User $actor, Topic $topic): Topic
    {
        Gate::forUser($actor)->authorize('archive', $topic);

        return DB::transaction(function () use ($actor, $topic) {
            $topic = Topic::query()->whereKey($topic->id)->lockForUpdate()->firstOrFail();
            $this->access->flush();
            Gate::forUser($actor)->authorize('archive', $topic);

            if ($topic->isArchived() || $this->access->isHidden($topic)) {
                throw ValidationException::withMessages(['topic' => 'This topic is already archived.']);
            }

            $below = DB::table('topic_closure')->where('ancestor_id', $topic->id)->where('distance', '>', 0)->count();
            $before = $this->snapshot($topic);

            $topic->update(['archived_at' => now(), 'archived_by_id' => $actor->id]);

            TopicAudit::record($actor, 'topic.archived', $topic->id, 'topic', $topic->id, $before, [...$this->snapshot($topic), 'topics_below' => $below]);

            return $topic;
        });
    }

    /**
     * Bring an archived topic back. It is refused while it still sits inside
     * another archived topic: restoring never puts a branch into normal
     * browsing underneath something that is hidden.
     *
     * @throws AuthorizationException|ValidationException
     */
    public function restore(User $actor, Topic $topic): Topic
    {
        Gate::forUser($actor)->authorize('restore', $topic);

        return DB::transaction(function () use ($actor, $topic) {
            $topic = Topic::query()->whereKey($topic->id)->lockForUpdate()->firstOrFail();
            $this->access->flush();
            Gate::forUser($actor)->authorize('restore', $topic);

            if (! $topic->isArchived()) {
                throw ValidationException::withMessages(['topic' => 'This topic is not archived.']);
            }

            $insideArchived = DB::table('topic_closure as c')
                ->join('topics as a', 'a.id', '=', 'c.ancestor_id')
                ->where('c.descendant_id', $topic->id)
                ->where('c.distance', '>', 0)
                ->whereNotNull('a.archived_at')
                ->exists();

            if ($insideArchived) {
                throw ValidationException::withMessages(['topic' => 'This topic sits inside another archived topic. Restore that one first.']);
            }

            $before = $this->snapshot($topic);
            $topic->update(['archived_at' => null, 'archived_by_id' => null]);

            TopicAudit::record($actor, 'topic.restored', $topic->id, 'topic', $topic->id, $before, $this->snapshot($topic));

            return $topic;
        });
    }

    /**
     * Why a topic may not be permanently deleted. Empty only for a topic that
     * was never used: nothing inside it, no role ever given on it, no role
     * defined for it and no course linked to it.
     *
     * @return list<string>
     */
    public function deleteBlockers(Topic $topic): array
    {
        $blockers = [];

        $children = Topic::query()->where('parent_id', $topic->id)->count();
        if ($children > 0) {
            $blockers[] = "It has {$children} topic(s) inside it (archived ones count too).";
        }

        $assignments = RoleAssignment::query()->where('topic_id', $topic->id)->count();
        if ($assignments > 0) {
            $blockers[] = "{$assignments} role assignment(s), current or ended, are recorded on it.";
        }

        $roles = RoleDefinition::query()->where('topic_id', $topic->id)->count();
        if ($roles > 0) {
            $blockers[] = "{$roles} role(s) are defined for this branch.";
        }

        if ($topic->course_id !== null) {
            $blockers[] = 'It is linked to a course that the enrolment import uses.';
        }

        if ($blockers !== []) {
            $blockers[] = 'Archive it instead: nothing is lost and it can be restored.';
        }

        return $blockers;
    }

    /**
     * First step of a permanent deletion: checks everything and returns what
     * the confirmations show, plus a signed token for the second step.
     *
     * @return array{token: string, summary: array<string, mixed>}
     *
     * @throws AuthorizationException|ValidationException
     */
    public function prepareDelete(User $actor, Topic $topic): array
    {
        Gate::forUser($actor)->authorize('delete', $topic);

        $topic = Topic::query()->findOrFail($topic->id);
        $blockers = $this->deleteBlockers($topic);

        if ($blockers !== []) {
            throw ValidationException::withMessages(['topic' => $blockers]);
        }

        return [
            'token' => Crypt::encryptString(json_encode([
                'actor' => $actor->id,
                'topic' => $topic->id,
                'stamp' => $topic->updated_at?->getTimestamp(),
                'expires' => now()->addMinutes((int) config('topics.confirmation_minutes'))->timestamp,
            ])),
            'summary' => ['id' => $topic->id, 'title' => $topic->title, 'archived' => $topic->isArchived()],
        ];
    }

    /**
     * Second step: delete for good. The person must also type the topic's
     * name. Everything is checked again on the locked row.
     *
     * @throws AuthorizationException|ValidationException
     */
    public function executeDelete(User $actor, string $token, string $typedTitle): void
    {
        try {
            $payload = json_decode(Crypt::decryptString($token), true, 4, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            throw ValidationException::withMessages(['confirmation' => 'The confirmation is not valid. Nothing was deleted.']);
        }

        if (($payload['actor'] ?? null) !== $actor->id || ($payload['expires'] ?? 0) < now()->timestamp) {
            throw ValidationException::withMessages(['confirmation' => 'The confirmation expired. Nothing was deleted; please start again.']);
        }

        DB::transaction(function () use ($actor, $payload, $typedTitle) {
            $topic = Topic::query()->whereKey($payload['topic'])->lockForUpdate()->first();

            if ($topic === null) {
                throw ValidationException::withMessages(['confirmation' => 'This topic is already gone. Nothing was changed.']);
            }

            $this->access->flush();
            Gate::forUser($actor)->authorize('delete', $topic);

            if ($topic->updated_at?->getTimestamp() !== $payload['stamp']) {
                throw ValidationException::withMessages(['confirmation' => 'This topic changed after you opened it. Nothing was deleted; please start again.']);
            }

            $blockers = $this->deleteBlockers($topic);
            if ($blockers !== []) {
                throw ValidationException::withMessages(['topic' => $blockers]);
            }

            if (trim($typedTitle) !== $topic->title) {
                throw ValidationException::withMessages(['confirm_title' => 'Type the topic name exactly to confirm.']);
            }

            $before = $this->snapshot($topic);
            DB::table('topic_closure')->where('descendant_id', $topic->id)->orWhere('ancestor_id', $topic->id)->delete();
            $topic->delete();

            TopicAudit::record($actor, 'topic.deleted', $topic->id, 'topic', $topic->id, $before, null);
            $this->access->flush();
        });
    }

    /**
     * Reasons a move cannot happen, empty when it can.
     *
     * @return list<string>
     */
    private function moveProblems(Topic $topic, ?Topic $destination): array
    {
        if ($destination !== null && $destination->id === $topic->id) {
            return ['A topic cannot be moved into itself.'];
        }

        if ($destination !== null && $destination->isWithin($topic)) {
            return ['A topic cannot be moved into one of its own descendants.'];
        }

        if (($destination?->id) === $topic->parent_id) {
            return ['The topic is already there.'];
        }

        $problems = [];
        $newDepth = $destination === null ? 0 : $destination->depth + 1;
        $height = (int) DB::table('topic_closure')->where('ancestor_id', $topic->id)->max('distance');

        if ($newDepth + $height >= config('topics.max_depth')) {
            $problems[] = 'The topic and everything below it would be nested deeper than '.config('topics.max_depth').' levels.';
        }

        // A branch-only role must still sit inside its own branch after the move.
        $newOuter = $destination === null ? [] : $destination->lineageIds();
        $inside = DB::table('topic_closure')->where('ancestor_id', $topic->id)->pluck('descendant_id')->all();

        $scoped = RoleAssignment::query()
            ->active()
            ->whereIn('topic_id', $inside)
            ->whereHas('role', fn ($query) => $query->whereNotNull('topic_id'))
            ->with('role')
            ->get();

        foreach ($scoped as $assignment) {
            $stillCovered = in_array($assignment->role->topic_id, $newOuter, true)
                || DB::table('topic_closure')
                    ->where('ancestor_id', $assignment->role->topic_id)
                    ->where('descendant_id', $assignment->topic_id)
                    ->whereIn('ancestor_id', $inside)
                    ->exists();

            if (! $stillCovered) {
                $problems[] = "\"{$assignment->role->name}\" is held at a topic that would end up outside the branch this role belongs to. End or replace that role first.";
            }
        }

        return array_values(array_unique($problems));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{title: string, code: ?string, description: ?string}
     *
     * @throws ValidationException
     */
    private function validated(array $data): array
    {
        $clean = Validator::make([
            'title' => is_string($data['title'] ?? null) ? trim($data['title']) : ($data['title'] ?? null),
            'code' => is_string($data['code'] ?? null) ? trim($data['code']) : ($data['code'] ?? null),
            'description' => is_string($data['description'] ?? null) ? trim($data['description']) : ($data['description'] ?? null),
        ], [
            'title' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string', 'max:5000'],
        ], [
            'title.required' => 'Give the topic a name.',
        ])->validate();

        return [
            'title' => $clean['title'],
            'code' => ($clean['code'] ?? '') === '' ? null : $clean['code'],
            'description' => ($clean['description'] ?? '') === '' ? null : $clean['description'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Topic $topic): array
    {
        return $topic->only(['title', 'code', 'description', 'parent_id', 'depth', 'archived_at']);
    }
}

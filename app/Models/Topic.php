<?php

namespace App\Models;

use App\Services\TopicAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * A named topic. Every topic is the same kind of thing at every depth: a root
 * is simply a topic without a parent. Write only through
 * App\Services\TopicService; reads for a user go through App\Services\TopicAccess.
 */
class Topic extends Model
{
    protected $fillable = [
        'parent_id',
        'title',
        'code',
        'description',
        'depth',
        'created_by_id',
        'legacy_tree_unit_id',
        'course_id',
        'subject_area_id',
        'archived_at',
        'archived_by_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['archived_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::saved(fn () => app(TopicAccess::class)->flush());

        // Defence in depth: whatever code path changes a parent, a topic can
        // never become its own ancestor.
        static::saving(function (Topic $topic) {
            if ($topic->exists && $topic->isDirty('parent_id') && $topic->parent_id !== null) {
                $isSelfOrDescendant = DB::table('topic_closure')
                    ->where('ancestor_id', $topic->id)
                    ->where('descendant_id', $topic->parent_id)
                    ->exists();

                if ($isSelfOrDescendant) {
                    throw new LogicException('A topic cannot be placed inside itself or one of its descendants.');
                }
            }
        });
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function archivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(RoleAssignment::class);
    }

    /**
     * Ids of this topic and all its ancestors, nearest first.
     *
     * @return list<int>
     */
    public function lineageIds(): array
    {
        return DB::table('topic_closure')
            ->where('descendant_id', $this->id)
            ->orderBy('distance')
            ->pluck('ancestor_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * This topic and everything below it.
     *
     * @param  Builder<Topic>  $query
     */
    public function scopeWithinBranch(Builder $query, Topic $topic): void
    {
        $query->whereIn('topics.id', DB::table('topic_closure')
            ->select('descendant_id')
            ->where('ancestor_id', $topic->id));
    }

    /**
     * Strictly below this topic.
     *
     * @param  Builder<Topic>  $query
     */
    public function scopeBelow(Builder $query, Topic $topic): void
    {
        $query->whereIn('topics.id', DB::table('topic_closure')
            ->select('descendant_id')
            ->where('ancestor_id', $topic->id)
            ->where('distance', '>', 0));
    }

    /**
     * Topics that are shown in normal browsing: neither archived themselves
     * nor below an archived topic.
     *
     * @param  Builder<Topic>  $query
     */
    public function scopeNotArchived(Builder $query): void
    {
        $query->whereNotIn('topics.id', DB::table('topic_closure as hidden_closure')
            ->join('topics as hidden_ancestor', 'hidden_ancestor.id', '=', 'hidden_closure.ancestor_id')
            ->whereNotNull('hidden_ancestor.archived_at')
            ->select('hidden_closure.descendant_id'));
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function isWithin(Topic $other): bool
    {
        return DB::table('topic_closure')
            ->where('ancestor_id', $other->id)
            ->where('descendant_id', $this->id)
            ->exists();
    }
}

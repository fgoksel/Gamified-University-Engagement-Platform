<?php

namespace App\Models;

use App\Enums\TreeUnitKind;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A unit of the role tree: a dean's root, a course or a subtopic.
 *
 * "path" holds the ids from the root down to this unit, e.g. "/1/4/9/".
 * It is filled in automatically when the unit is created. Write to the
 * tree only through App\Services\TreeService.
 */
class TreeUnit extends Model
{
    protected $fillable = [
        'parent_id',
        'kind',
        'title',
        'course_id',
        'subject_area_id',
        'created_by_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => TreeUnitKind::class,
        ];
    }

    protected static function booted(): void
    {
        // The path needs the new id, so it is set right after the insert.
        static::created(function (TreeUnit $unit) {
            $parentPath = $unit->parent_id === null ? '/' : $unit->parent->path;
            $unit->path = $parentPath.$unit->id.'/';
            $unit->saveQuietly();
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

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function subjectArea(): BelongsTo
    {
        return $this->belongsTo(SubjectArea::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * Every membership on this unit, active and ended.
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(UnitMembership::class, 'unit_id');
    }

    /**
     * Limit a query to this unit and every unit below it.
     *
     * @param  Builder<TreeUnit>  $query
     */
    public function scopeWithin(Builder $query, TreeUnit $unit): void
    {
        $query->where('path', 'like', $unit->path.'%');
    }

    /**
     * Ids from the root down to this unit, this unit included.
     *
     * @return list<int>
     */
    public function pathIds(): array
    {
        return array_map('intval', array_values(array_filter(explode('/', $this->path))));
    }

    /**
     * True when this unit is $unit itself or somewhere below it.
     */
    public function isWithin(TreeUnit $unit): bool
    {
        return str_starts_with($this->path, $unit->path);
    }

    /**
     * The course this unit belongs to: itself for a course, the nearest
     * course above it for a subtopic, and null for a root.
     */
    public function courseUnit(): ?TreeUnit
    {
        if ($this->kind === TreeUnitKind::Course) {
            return $this;
        }

        if ($this->kind === TreeUnitKind::Root) {
            return null;
        }

        return self::whereIn('id', $this->pathIds())
            ->where('kind', TreeUnitKind::Course)
            ->first();
    }

    /**
     * The active memberships $user holds on this unit or any unit above it.
     * These are the roles that give $user rights here.
     *
     * @return Builder<UnitMembership>
     */
    public function coveringMemberships(User $user): Builder
    {
        return UnitMembership::query()
            ->active()
            ->where('user_id', $user->id)
            ->whereIn('unit_id', $this->pathIds());
    }
}

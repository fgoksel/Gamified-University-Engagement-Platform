<?php

namespace App\Models;

use App\Enums\TreeRole;
use App\Enums\TutorPermission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person in a role on a tree unit.
 *
 * Never deleted: a role is ended with ended_at and ended_reason.
 * Write memberships only through App\Services\TreeService.
 */
class UnitMembership extends Model
{
    protected $fillable = [
        'unit_id',
        'user_id',
        'role',
        'added_by_id',
        'permissions',
        'semester_id',
        'manual',
        'started_at',
        'ended_at',
        'ended_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => TreeRole::class,
            'permissions' => 'array',
            'manual' => 'boolean',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(TreeUnit::class, 'unit_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by_id');
    }

    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class);
    }

    /**
     * Only roles that have not been ended.
     *
     * @param  Builder<UnitMembership>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('ended_at');
    }

    public function isActive(): bool
    {
        return $this->ended_at === null;
    }

    /**
     * Whether this tutor has been given $permission by their teacher.
     */
    public function hasPermission(TutorPermission $permission): bool
    {
        return $this->role === TreeRole::Tutor
            && in_array($permission->value, $this->permissions ?? [], true);
    }
}

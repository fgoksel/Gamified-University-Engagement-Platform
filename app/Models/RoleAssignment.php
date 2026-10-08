<?php

namespace App\Models;

use App\Services\TopicAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person holding a role definition at a topic (and below it).
 *
 * Never deleted: ended with ended_at and a reason. The grantor columns are
 * history only; an assignment stays valid if its grantor leaves.
 */
class RoleAssignment extends Model
{
    protected static function booted(): void
    {
        // Access answers depend on these rows: never serve a remembered answer after a change.
        static::saved(fn () => app(TopicAccess::class)->flush());
        static::deleted(fn () => app(TopicAccess::class)->flush());
    }

    protected $fillable = [
        'topic_id',
        'user_id',
        'role_definition_id',
        'granted_by_id',
        'granted_by_assignment_id',
        'authority_snapshot',
        'started_at',
        'ended_at',
        'ended_reason',
        'ended_by_id',
        'replaces_assignment_id',
        'replaced_by_assignment_id',
        'version',
        'semester_id',
        'manual',
        'legacy_permissions',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'authority_snapshot' => 'array',
            'legacy_permissions' => 'array',
            'manual' => 'boolean',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(RoleDefinition::class, 'role_definition_id');
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by_id');
    }

    public function endedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ended_by_id');
    }

    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class);
    }

    /**
     * @param  Builder<RoleAssignment>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('role_assignments.ended_at');
    }

    public function isActive(): bool
    {
        return $this->ended_at === null;
    }
}

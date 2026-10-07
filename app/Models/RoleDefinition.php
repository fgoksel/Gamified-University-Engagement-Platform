<?php

namespace App\Models;

use App\Enums\Capability;
use App\Services\TopicAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A reusable role: a name and a list of registry capabilities. Global
 * (topic_id null, System Admin only) or limited to one topic and below.
 * The name never decides anything; only "capabilities" and "delegable" do.
 */
class RoleDefinition extends Model
{
    protected static function booted(): void
    {
        // Access answers depend on these rows: never serve a remembered answer after a change.
        static::saved(fn () => app(TopicAccess::class)->flush());
        static::deleted(fn () => app(TopicAccess::class)->flush());
    }

    protected $fillable = [
        'name',
        'description',
        'topic_id',
        'capabilities',
        'delegable',
        'created_by_id',
        'archived_at',
        'legacy_key',
        'version',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
            'delegable' => 'array',
            'archived_at' => 'datetime',
        ];
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(RoleAssignment::class);
    }

    /**
     * @param  Builder<RoleDefinition>  $query
     */
    public function scopeUsable(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    public function isGlobal(): bool
    {
        return $this->topic_id === null;
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function has(Capability $capability): bool
    {
        return in_array($capability->value, $this->capabilities ?? [], true);
    }

    /**
     * @return list<string>
     */
    public function capabilityList(): array
    {
        return Capability::normalise($this->capabilities ?? []);
    }

    /**
     * @return list<string>
     */
    public function delegableList(): array
    {
        return Capability::normalise($this->delegable ?? []);
    }
}

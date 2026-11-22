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

    /**
     * The powers a plain student role may have (Task #133): see the area and its events, and apply
     * to events. What "Event student" has; a general area may give nothing more.
     *
     * @return list<Capability>
     */
    public static function plainStudentCapabilities(): array
    {
        return [Capability::View, Capability::EventsView, Capability::EventsApply];
    }

    /**
     * A plain student role: it has at least one capability, and every one of them is a plain student
     * power. The only kind of role a general area gives (Task #133).
     */
    public function isPlainStudentRole(): bool
    {
        $allowed = array_map(fn (Capability $capability) => $capability->value, self::plainStudentCapabilities());
        $has = $this->capabilityList();

        return $has !== [] && array_diff($has, $allowed) === [];
    }

    /**
     * The plain student roles (isPlainStudentRole()). Capabilities are stored as a list, so the
     * check runs on the (short) list of roles, not in SQL.
     *
     * @param  Builder<RoleDefinition>  $query
     */
    public function scopePlainStudent(Builder $query): void
    {
        $query->whereKey(
            static::query()->get(['id', 'capabilities'])
                ->filter(fn (RoleDefinition $role) => $role->isPlainStudentRole())
                ->modelKeys()
        );
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

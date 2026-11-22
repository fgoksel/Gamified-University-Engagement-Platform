<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * An event managed from one owning topic and announced to one audience topic.
 */
class Event extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_ON_HOLD = 'on_hold';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Taken off by a system admin for good reason (Task #58): gone for everybody, its points reversed.
     * The row, its applications and its ledger rows stay. Nothing leaves this status.
     */
    public const STATUS_REMOVED = 'removed';

    protected $fillable = [
        'title',
        'description',
        'location',
        'category',
        'topic_tag_id',
        'created_by_id',
        'owning_topic_id',
        'audience_topic_id',
        'semester_id',
        'capacity',
        'application_type',
        'points_value',
        'event_starts_at',
        'event_ends_at',
        'applications_open_at',
        'application_deadline',
        'completion_deadline',
        'status',
        'published_at',
        'cancelled_at',
        'cancellation_reason',
        'removed_at',
        'removed_by_id',
        'removal_reason',
        'is_hidden',
        // No longer used (Task #86): a surprise event is never revealed to students.
        'reveal_at',
        'qr_enabled',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_starts_at' => 'datetime',
            'event_ends_at' => 'datetime',
            'applications_open_at' => 'datetime',
            'application_deadline' => 'datetime',
            'completion_deadline' => 'datetime',
            'published_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'removed_at' => 'datetime',
            'reveal_at' => 'datetime',
            'is_hidden' => 'boolean',
            'qr_enabled' => 'boolean',
            'capacity' => 'integer',
            'points_value' => 'integer',
        ];
    }

    public function owningTopic(): BelongsTo
    {
        return $this->belongsTo(Topic::class, 'owning_topic_id');
    }

    public function audienceTopic(): BelongsTo
    {
        return $this->belongsTo(Topic::class, 'audience_topic_id');
    }

    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class);
    }

    public function tag(): BelongsTo
    {
        return $this->belongsTo(TopicTag::class, 'topic_tag_id');
    }

    public function topicTag(): BelongsTo
    {
        return $this->tag();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /** The system admin who removed the event (Task #58). */
    public function removedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'removed_by_id');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(EventApplication::class);
    }

    public function pointTransactions(): HasMany
    {
        return $this->hasMany(PointTransaction::class);
    }

    public function qrTokens(): HasMany
    {
        return $this->hasMany(QrToken::class);
    }

    /** The badge the students get who are credited for this event (Task #103), if it has one. */
    public function badge(): HasOne
    {
        return $this->hasOne(Badge::class);
    }

    /** Who changed the event, when and what (Task #76), newest first. */
    public function edits(): HasMany
    {
        return $this->hasMany(EventEdit::class)->latest('created_at')->latest('id');
    }

    /**
     * When applications open: the date the organiser chose, or the moment of publishing for an
     * event without one (Task #78).
     */
    public function applicationsOpenFrom(): ?CarbonInterface
    {
        return $this->applications_open_at ?? $this->published_at;
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    /**
     * On hold: kept and restorable, but frozen.
     */
    public function isOnHold(): bool
    {
        return $this->status === self::STATUS_ON_HOLD;
    }

    /**
     * Removed by a system admin (Task #58): nobody sees, runs or applies to it any more.
     */
    public function isRemoved(): bool
    {
        return $this->status === self::STATUS_REMOVED;
    }
}

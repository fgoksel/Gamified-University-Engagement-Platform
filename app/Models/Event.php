<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    protected $fillable = [
        'title',
        'description',
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
        'is_hidden',
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
     * Whether the event is announced right now: a surprise event is not until revealed.
     */
    public function isRevealed(): bool
    {
        if (! $this->is_hidden) {
            return true;
        }

        return $this->reveal_at !== null && $this->reveal_at->isPast();
    }

    /**
     * On hold: kept and restorable, but frozen.
     */
    public function isOnHold(): bool
    {
        return $this->status === self::STATUS_ON_HOLD;
    }
}

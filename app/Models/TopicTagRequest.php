<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Teacher-submitted Topic Tag request (UC-1.9, UC-3.4, Technical Specification 6.4).
 */
class TopicTagRequest extends Model
{
    protected $fillable = [
        'proposed_name',
        'category',
        'justification',
        'status',
        'requested_by_id',
        'resolved_by_id',
        'rejection_reason',
        'resolved_topic_tag_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * The teacher who submitted the topic tag request.
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }

    /**
     * The administrator who approved or rejected the request.
     */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_id');
    }

    /**
     * The live topic tag created upon approval.
     */
    public function resolvedTopicTag(): BelongsTo
    {
        return $this->belongsTo(TopicTag::class, 'resolved_topic_tag_id');
    }
}

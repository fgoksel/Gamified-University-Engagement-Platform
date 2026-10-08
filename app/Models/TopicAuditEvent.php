<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of the audit trail: who changed what, with before and after.
 */
class TopicAuditEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'actor_id',
        'action',
        'topic_id',
        'subject_type',
        'subject_id',
        'before',
        'after',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}

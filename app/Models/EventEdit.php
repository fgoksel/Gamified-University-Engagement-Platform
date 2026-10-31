<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One saved edit of an event (Task #76): who, when, and each changed field's old and new value.
 * Written by EventEditor only; never changed afterwards.
 */
class EventEdit extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'event_id',
        'actor_id',
        'changes',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}

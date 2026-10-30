<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The time a live attendance code is being shown for an event (Task #80).
 */
class EventCheckinSession extends Model
{
    protected $fillable = [
        'event_id',
        'issuer_id',
        'secret',
        'opened_at',
        'paused_at',
        'closed_at',
    ];

    protected $hidden = ['secret'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'paused_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function isClosed(): bool
    {
        return $this->closed_at !== null;
    }

    public function isPaused(): bool
    {
        return $this->paused_at !== null;
    }

    /** Showing the code and accepting scans. */
    public function isOpen(): bool
    {
        return ! $this->isClosed() && ! $this->isPaused();
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issuer_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventApplication extends Model
{
    public const PENDING = 'pending';

    public const ACCEPTED = 'accepted';

    public const WAITLISTED = 'waitlisted';

    public const REJECTED = 'rejected';

    public const WITHDRAWN = 'withdrawn';

    protected $fillable = [
        'event_id',
        'student_id',
        'status',
        'applied_at',
        'decided_by_id',
        'decided_at',
        'withdrawn_at',
        'completed_at',
        'scored_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'applied_at' => 'datetime',
            'decided_at' => 'datetime',
            'withdrawn_at' => 'datetime',
            'completed_at' => 'datetime',
            'scored_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function isAccepted(): bool
    {
        return $this->status === self::ACCEPTED;
    }

    public function isCredited(): bool
    {
        return $this->scored_at !== null;
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QrToken extends Model
{
    public const POSTER = 'poster';

    public const SLIP = 'slip';

    protected $fillable = [
        'event_id',
        'issuer_id',
        'token_code',
        'type',
        'step_number',
        'total_steps',
        'expires_at',
        'revoked_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'step_number' => 'integer',
            'total_steps' => 'integer',
        ];
    }

    /**
     * A code that is one part of a task of several parts (HW 1, 2, 3).
     */
    public function isMilestone(): bool
    {
        return $this->total_steps !== null;
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issuer_id');
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(QrRedemption::class);
    }
}

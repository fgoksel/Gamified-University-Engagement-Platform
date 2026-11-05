<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QrToken extends Model
{
    /** A code every student could scan once. No longer made or accepted (Task #84). */
    public const POSTER = 'poster';

    public const SLIP = 'slip';

    protected $fillable = [
        'event_id',
        'issuer_id',
        'token_code',
        'type',
        'batch',
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
     * A code that was one part of a task of several parts (HW 1, 2, 3). No longer made or
     * accepted: each part is an event of its own now (27 Oct).
     */
    public function isMilestone(): bool
    {
        return $this->total_steps !== null;
    }

    /**
     * A printed single-use slip (Task #84), the only kind of printed code that still works.
     */
    public function isSlip(): bool
    {
        return $this->type === self::SLIP && ! $this->isMilestone();
    }

    /**
     * Printed single-use slips (isSlip()).
     *
     * @param  Builder<QrToken>  $query
     */
    public function scopeSlips(Builder $query): void
    {
        $query->where('type', self::SLIP)->whereNull('total_steps');
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

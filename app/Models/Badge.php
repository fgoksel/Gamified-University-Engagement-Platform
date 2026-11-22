<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A badge (Task #103, N10): an achievement that hangs on an event. Every student the event gives
 * points to gets it, once. It is only a mark: it carries no points, changes no rank and has no
 * leaderboard of its own.
 */
class Badge extends Model
{
    public const NAME_MAX = 60;

    public const DESCRIPTION_MAX = 200;

    protected $fillable = [
        'event_id',
        'name',
        'description',
        // For the badge icons (Task #125): a short key such as "star".
        'icon',
        'created_by_id',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function awards(): HasMany
    {
        return $this->hasMany(BadgeAward::class);
    }
}

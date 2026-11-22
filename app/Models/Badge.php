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

    /**
     * The icons an organiser may choose (Task #125), in the order the badge card offers them. One
     * list for the validation and the page; resources/js/badgeIcons.js maps each key to its icon.
     */
    public const ICONS = [
        'award', 'trophy', 'medal', 'star', 'flame', 'rocket', 'heart', 'book-open', 'code',
        'flask-conical', 'dumbbell', 'users', 'lightbulb', 'music', 'palette',
    ];

    /** A badge made before Task #125, with no icon, shows this one. */
    public const DEFAULT_ICON = 'award';

    protected $fillable = [
        'event_id',
        'name',
        'description',
        // One of self::ICONS (Task #125); null on badges made before, shown as DEFAULT_ICON.
        'icon',
        'created_by_id',
    ];

    /**
     * The key to show: the chosen one, or the default for a badge without one.
     */
    public static function iconOf(?string $icon): string
    {
        return in_array($icon, self::ICONS, true) ? $icon : self::DEFAULT_ICON;
    }

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

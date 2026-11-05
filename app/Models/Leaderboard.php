<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A leaderboard of an area (Task #89). It covers the events owned in its topic and every
 * topic below it; nothing narrower can be picked (L1, 27 October). The ranking itself comes
 * from App\Services\LeaderboardStandings, and writes go through App\Services\AreaLeaderboards.
 */
class Leaderboard extends Model
{
    /** Only people who can open the topic see it. */
    public const AREA = 'area';

    /** Published: seen without login and on the hall screen. */
    public const PUBLIC = 'public';

    protected $fillable = [
        'topic_id',
        'name',
        'visibility',
        'show_neptun_code',
        'show_first_name',
        'show_last_name',
        'created_by_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'show_neptun_code' => 'boolean',
            'show_first_name' => 'boolean',
            'show_last_name' => 'boolean',
        ];
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function isPublic(): bool
    {
        return $this->visibility === self::PUBLIC;
    }

    /**
     * The columns that identify a student on this leaderboard, in display order.
     *
     * @return list<string>
     */
    public function columns(): array
    {
        return array_keys(array_filter([
            'neptun_code' => $this->show_neptun_code,
            'first_name' => $this->show_first_name,
            'last_name' => $this->show_last_name,
        ]));
    }

    /**
     * Leaderboards whose topic is in normal browsing: not archived and not below an archived topic.
     *
     * @param  Builder<Leaderboard>  $query
     */
    public function scopeInNormalBrowsing(Builder $query): void
    {
        $query->whereIn('topic_id', Topic::query()->notArchived()->select('topics.id'));
    }

    /**
     * What the hall screen shows (#56): published leaderboards of topics in normal browsing.
     *
     * @param  Builder<Leaderboard>  $query
     */
    public function scopeOnHallScreen(Builder $query): void
    {
        $query->where('visibility', self::PUBLIC)->inNormalBrowsing();
    }
}

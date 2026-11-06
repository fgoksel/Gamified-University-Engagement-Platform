<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * System-wide values a super admin changes (Task #56). The table holds one row at most;
 * without one, the defaults below apply.
 */
class Setting extends Model
{
    protected $fillable = ['hall_seconds'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['hall_seconds' => 'integer'];
    }

    /** How long a leaderboard without its own time stays on the hall screen. */
    public static function hallSeconds(): int
    {
        return static::query()->value('hall_seconds') ?? Leaderboard::HALL_SECONDS;
    }

    public static function setHallSeconds(int $seconds): void
    {
        $row = static::query()->first() ?? new static;
        $row->hall_seconds = $seconds;
        $row->save();
    }
}

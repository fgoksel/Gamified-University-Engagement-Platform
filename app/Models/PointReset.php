<?php

namespace App\Models;

use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One "Reset all points" by the system admin (Task #102, UC-6.4). On 27 October the professor
 * asked for a button, never anything automatic (L3, S4): there are no semester periods, and
 * whether to reset each semester is for the professors to decide.
 *
 * The ledger is never edited (#59). A reset records the last ledger row that no longer counts,
 * so every total and every leaderboard starts from zero while the earlier rows stay as they
 * were. Like a ledger row, a reset is never changed or deleted.
 */
class PointReset extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['reset_by_id', 'last_transaction_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['last_transaction_id' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('A points reset cannot be changed.'));
        static::deleting(fn () => throw new LogicException('A points reset cannot be deleted.'));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function resetBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reset_by_id');
    }

    /** The id of the last ledger row that no longer counts: 0 while the points were never reset. */
    public static function lastUncountedId(): int
    {
        return (int) static::query()->max('last_transaction_id');
    }

    /**
     * Keeps a query of the ledger to the rows that count: the ones written after the last reset.
     * A correction counts when the credit it corrects counts, so taking back a credit from before
     * the reset changes no total. $table is the ledger's name or alias in the query.
     */
    public static function onlyCounting(Builder $query, string $table): void
    {
        $after = static::lastUncountedId();

        if ($after > 0) {
            // $table comes from the code, never from a request.
            $query->whereRaw(sprintf('coalesce(%1$s.reversal_of_id,%1$s.id)>?', $table), [$after]);
        }
    }
}

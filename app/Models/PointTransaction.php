<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One row of the points ledger. Rows are only ever added: a correction is a
 * new row with negative points that points back at the row it reverses.
 */
class PointTransaction extends Model
{
    public const UPDATED_AT = null;

    public const CREDIT = 'credit';

    public const REVERSAL = 'reversal';

    protected $fillable = [
        'student_id',
        'event_id',
        'semester_id',
        'credited_by_id',
        'points',
        'type',
        'source',
        'category',
        'reversal_of_id',
        'reversal_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['points' => 'integer'];
    }

    protected static function booted(): void
    {
        // The ledger only grows: a mistake is corrected with a reversal row, never an edit.
        static::updating(fn () => throw new LogicException('Ledger rows cannot be changed. Add a reversal instead.'));
        static::deleting(fn () => throw new LogicException('Ledger rows cannot be deleted. Add a reversal instead.'));
        static::saving(function (PointTransaction $row) {
            if ($row->type === self::CREDIT && $row->points <= 0) {
                throw new LogicException('A credit must be a positive number of points.');
            }
            if ($row->type === self::REVERSAL && $row->points >= 0) {
                throw new LogicException('A reversal must be a negative number of points.');
            }
        });
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function creditedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'credited_by_id');
    }
}

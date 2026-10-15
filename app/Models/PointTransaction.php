<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

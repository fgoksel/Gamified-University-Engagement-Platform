<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QrRedemption extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'qr_token_id',
        'student_id',
        'step_number',
        'redeemed_at',
        'point_transaction_id',
        'slip_claim_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['redeemed_at' => 'datetime'];
    }

    public function token(): BelongsTo
    {
        return $this->belongsTo(QrToken::class, 'qr_token_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A student holds a badge (Task #103). One row per student and badge: the database refuses a
 * second one.
 */
class BadgeAward extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'badge_id',
        'student_id',
        'awarded_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['awarded_at' => 'datetime'];
    }

    public function badge(): BelongsTo
    {
        return $this->belongsTo(Badge::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }
}

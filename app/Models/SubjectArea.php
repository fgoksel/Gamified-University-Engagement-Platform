<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubjectArea extends Model
{
    protected $fillable = [
        'title',
        'code',
        'description',
        'category',
        'faculty_id',
        'is_active',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Get the faculty that owns the subject area.
     */
    public function faculty(): BelongsTo
    {
        return $this->belongsTo(Faculty::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TopicTag extends Model
{
    protected $fillable = [
        'name',
        'category',
        'description',
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
}

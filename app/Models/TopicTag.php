<?php

namespace App\Models;

use App\Services\CategoryList;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A tag sits under one category of the category list (Task #116). A deactivated tag, or a tag
 * whose category was retired, stays on the events that have it but is not offered for others.
 */
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

    /**
     * The tags an organiser may pick for an event.
     *
     * @param  Builder<TopicTag>  $query
     */
    public function scopePickable(Builder $query): void
    {
        $query->where('is_active', true)
            ->whereIn('category', Category::query()->inUse()->select('slug'));
    }

    public function isPickable(): bool
    {
        return $this->is_active && app(CategoryList::class)->isInUse($this->category);
    }
}

<?php

namespace App\Models;

use App\Enums\CategoryColor;
use App\Services\CategoryList;
use App\Services\PointCalculationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * One category of the list (Task #116): what tags sit under, and what an event and its points
 * may count as. Tags, events and ledger rows store the slug, which never changes, so renaming
 * a category changes nothing they hold. A category is retired, never deleted: the points
 * already given in it stay in it.
 */
class Category extends Model
{
    protected $fillable = [
        'slug',
        'name',
        'color',
        'retired_at',
    ];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'color' => CategoryColor::class,
            'retired_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (Category $category) {
            if ($category->isDirty('slug')) {
                throw new LogicException('A category keeps its slug: tags, events and ledger rows store it.');
            }
        });
        static::deleting(fn () => throw new LogicException('Categories are retired, never deleted, so the points given in them keep them.'));

        // Every screen reads the list through CategoryList, and the standings carry a total per
        // category (Task #55): neither may keep the old list after a change.
        static::saved(function () {
            app(CategoryList::class)->flush();
            app(PointCalculationService::class)->forgetAllAreas();
        });
    }

    /**
     * @param  Builder<Category>  $query
     */
    public function scopeInUse(Builder $query): void
    {
        $query->whereNull('retired_at');
    }

    public function isRetired(): bool
    {
        return $this->retired_at !== null;
    }
}

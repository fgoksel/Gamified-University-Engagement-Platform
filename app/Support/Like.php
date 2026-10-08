<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * "Contains" search that treats % and _ in the search text literally, with the
 * same SQL on MySQL and SQLite (both honour an explicit ESCAPE character).
 */
class Like
{
    /**
     * Limit $query to rows where any of $columns contains $term.
     *
     * @param  Builder<*>  $query
     * @param  list<string>  $columns
     */
    public static function contains(Builder $query, array $columns, string $term): void
    {
        $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';

        $query->where(function (Builder $group) use ($columns, $pattern) {
            foreach ($columns as $column) {
                $group->orWhereRaw("{$column} like ? escape '!'", [$pattern]);
            }
        });
    }
}

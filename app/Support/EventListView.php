<?php

namespace App\Support;

/**
 * What a person typed into the search box and chose in the sort list of "My events" (Task #121),
 * cleaned. It comes from the address bar, so anything can be in it: the search is cut down to a
 * sensible length and a sort that is not offered is dropped, never an error.
 */
final class EventListView
{
    /** The longest search that is used; the rest is ignored. */
    public const MAX_SEARCH = 100;

    /** The orders both lists offer. Each list has one more that is its own default. */
    public const SORTS = ['date_asc', 'date_desc', 'title_asc', 'title_desc'];

    /**
     * The words to look for in a title, or an empty text for no search.
     */
    public static function search(mixed $input): string
    {
        return is_string($input) ? trim(mb_substr(trim($input), 0, self::MAX_SEARCH)) : '';
    }

    /**
     * The order that was asked for, if it is one of $allowed; otherwise null, and the list keeps its own.
     *
     * @param  list<string>  $allowed
     */
    public static function sort(mixed $input, array $allowed): ?string
    {
        return is_string($input) && in_array($input, $allowed, true) ? $input : null;
    }
}

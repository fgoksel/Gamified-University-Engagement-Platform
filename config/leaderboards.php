<?php

/*
|--------------------------------------------------------------------------
| Leaderboard totals (Task #55)
|--------------------------------------------------------------------------
|
| Points are always added up from the ledger. The sums of a leaderboard and of a student are
| kept in the cache for a short time, tagged, and cleared as soon as points are credited or
| reversed (App\Services\PointCalculationService), so a new credit shows at once and, whatever
| happens, within the time to live below.
|
| The store must support tags: Redis does, the database and file stores do not. Without one the
| totals are simply worked out on every request.
|
*/

return [

    /*
    | The cache store for the totals. Redis is part of the Docker setup (REDIS_HOST=redis).
    */
    'cache_store' => env('LEADERBOARD_CACHE_STORE', 'redis'),

    /*
    | How long the totals stay in the cache, in seconds (the technical specification says 30).
    | 0 switches the cache off.
    */
    'ttl' => (int) env('LEADERBOARD_CACHE_TTL', 30),

];

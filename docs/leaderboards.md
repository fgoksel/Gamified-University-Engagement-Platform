# Leaderboards: point calculation and cache (Task #55)

`App\Services\PointCalculationService` is the one place that adds points up from the ledger:

| Method | What it gives |
|---|---|
| `areaStandings($topicId)` | every student with points in the area (the topic and all below it), best first, with the points per category, the position and the rank (equal points share a rank: 1, 2, 2, 4) |
| `studentTotals($studentId, $semesterId = null)` | one student's points in total and per category |

`LeaderboardStandings::ranked()` (area leaderboards, the hall screen feed) and `PointLedger::totals()`
(the home page) both read from it.

**The cache** keeps those sums for **30 seconds** (`LEADERBOARD_CACHE_TTL`) in Redis
(`LEADERBOARD_CACHE_STORE=redis`; Redis is part of the Docker setup). It is tagged:

| Tag | Holds | Cleared when |
|---|---|---|
| `student-points:{id}` | the totals of that student | a ledger row is added for that student |
| `leaderboard-area:{topicId}` | the standings of that area | a ledger row is added for an event of that area or of any area below it |
| `leaderboards` | every standing | a topic is moved (the areas above it change) |

A credit, an import and a reversal all add a ledger row, and `PointTransaction` clears the tags
after the commit, so a new credit shows on the next visit, not after 30 seconds. The 30 seconds are
the limit for changes that do not pass the ledger (an account that was deactivated, a role that
changed).

The cache never decides anything:

- with the time to live at 0, or a store without tags (`database`, `file`), the sums are worked out
  on each request;
- if the store cannot be reached, a warning is logged and the sums are worked out; the cache is left
  alone for 30 seconds, then tried again.

The tests switch the cache off (`LEADERBOARD_CACHE_TTL=0` in `phpunit.xml`); the tests of the cache
switch it on.

To see what is cached in the Docker setup: `docker compose exec redis redis-cli -n 1 --scan` (database 1 is the cache database).

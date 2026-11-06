# Leaderboards: my leaderboard, the hall screen and the point calculation (Tasks #54, #55 and #56)

## My leaderboard (Task #54)

Everyone who is signed in sees their own leaderboard, in the menu under **Leaderboards**
(`/leaderboards`). Teachers also start there (`/`); students start on their home page.

- **A student** sees *Your points*: the total and the four categories, added up from the whole
  ledger with no period (L3). Below that, every leaderboard open to them, each with their rank
  ("You are number 2 of 4"), their points there per category, and how many students are listed.
  A student with no points in the area of a board is told they are not on it yet.
- **A teacher** earns no points. They see the leaderboards open to them and how many students
  each lists.
- **Which leaderboards:** the published ones, and the area-only ones of the topics the person can
  open (`AreaLeaderboards::openTo()`, L4). Nobody sees a leaderboard they may not see. On this
  page a student only gets their own row; the other students appear on the full leaderboard, as
  that leaderboard's settings allow (#89).
- Each board opens the full leaderboard (`/leaderboards/{id}`): the top 10 and the places around
  the student (L5).

The data comes from `App\Services\OwnLeaderboards`; the page is `Components/Leaderboards/OwnLeaderboards.vue`.

## The hall screen (Task #56)

`/hall` shows every published leaderboard on a public display, one at a time, without login. Open
it in the browser on the hall computer and press F11. It reads the feed `/hall/leaderboards`.

- **What it shows:** the name, the area, the top 10 with the columns the leaderboard allows, how
  many students are listed, and which board this is ("2 of 4"). Students with equal points share a
  rank. Pause stops the rotation (WCAG 2.2.2) and Resume starts it again.
- **Rotation:** each leaderboard stays for its own seconds. The feed is read again after a full
  round, at most once a minute, so a change shows at the next round.
- **When the server cannot be reached:** the last data stays on screen with no message, and the
  next round tries again. Until the first data arrives the screen says "Leaderboards will appear
  here shortly."

- **Staying awake:** the page asks the browser to keep the display on (Screen Wake Lock), also
  while paused. Where the browser does not allow it, the display's own settings apply.

Publishing a leaderboard asks first, and says that published leaderboards are shown on the hall
screen and to anyone without signing in. The words of the hall screen, the time on it and *Where am
I?* are in `resources/js/leaderboardWords.js`, ready for the translations of #95.

### Time on the hall screen

| Who | Where | What |
|---|---|---|
| Area admins (*Manage leaderboards*) | Leaderboard dialog, *Time on the hall screen* | 5 to 120 seconds, or empty for the default |
| The super admin | Admin panel, *Hall screen* | The default, and the time of any leaderboard |

The feed sends `seconds` for each board: `leaderboards.hall_seconds`, or else the default. The
default is the one row of the `settings` table (`App\Models\Setting`); without a row it is 15.

## Where am I? (Task #56)

On `/leaderboards`, under *Where am I?* (for teachers: *Rankings*), a person picks an area they can
open and, if they like, a category and a tag. The choice is in the address
(`?area=&category=&tag=`), so it can be shared and reloaded. An area they may not open gives "not
found", and a category or tag that does not fit is dropped. Tags are narrowed to the chosen category.

- **Counting:** the same as an area leaderboard, plus the filters: `ledger.category` and
  `events.topic_tag_id` (`PointCalculationService::standings($topicId, $category, $tagId)`). It is
  cached under the area's tags, so a credit clears it like the area standings.
- **A student** gets only their rank, how many students are listed, their points, and the points
  to draw level with the next place up ("15 points to reach number 3", none in first place). No
  other student's name, Neptun code or row leaves the server. With no points there: "You have no
  points here yet."
- **The person's area of control:** someone who holds *Manage events*, *Manage leaderboards* or
  *See who has access* at that area or above it gets the full list: rank, name, Neptun code and
  points, 50 per page.
- **Everyone else**, such as a teacher with a view-only role, gets the count alone.

The data comes from `App\Services\RankingExplorer`; the section is `Components/Leaderboards/WhereAmI.vue`.

## Point calculation and cache (Task #55)

`App\Services\PointCalculationService` is the one place that adds points up from the ledger:

| Method | What it gives |
|---|---|
| `areaStandings($topicId)` | every student with points in the area (the topic and all below it), best first, with the points per category, the position and the rank (equal points share a rank: 1, 2, 2, 4) |
| `standings($topicId, $category, $tagId)` | the same, narrowed to one category and one tag (*Where am I?*, Task #56) |
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

# Leaderboards: my leaderboard, the hall screen and the point calculation (Tasks #54, #55 and #56)

## My leaderboard (Task #54)

Everyone who is signed in sees their own leaderboard, in the menu under **Leaderboards**
(`/leaderboards`). Teachers also start there (`/`); students start on their home page.

The page is the same for everyone (D3, 7 November): the same leaderboards, and on each one the same
list. There is no lighter version for students and no separate one for teachers. A person's own
numbers are added where they exist:

- **A student** also sees *Your points*: the total and the four categories, added up from the whole
  ledger with no period (L3). On every leaderboard open to them they see their rank ("You are
  number 2 of 4") and their points there per category, next to how many students are listed. A
  student with no points in the area of a board is told they are not on it yet.
- **A teacher** earns no points, so there is no rank to show. They see the same leaderboards and
  how many students each lists, and open the same list.
- **Which leaderboards (D4, 7 November):** the ones of the areas the person can open: the areas
  they are assigned to and every area below them (`AreaLeaderboards::openTo()`). A leaderboard of
  an area above, beside or elsewhere does not show, and its address says "not found". Being
  on the hall screen (below) changes nothing here. On this
  page a student only gets their own row; the other students appear on the full leaderboard, as
  that leaderboard's settings allow (#89).
- Each board opens the full leaderboard (`/leaderboards/{id}`): the top 10 and the places around
  the student (L5).

### Highlighted leaderboards (D6, 7 November)

A star in front of each leaderboard, and a *Highlight* button on its page, highlights it for the
person who clicks. The highlighted ones are listed first, under *Highlighted*; the rest follow under
*Other leaderboards*. Each group stays in name order, and without a highlight there is one plain
list.

- **Limit:** five leaderboards for each person (`Leaderboard::HIGHLIGHT_LIMIT`). At the limit the
  server says "You can highlight up to 5 leaderboards. Remove a highlight first." and changes
  nothing. A highlight of a leaderboard the person can no longer open (their area changed, or it
  was archived) stays in the table but neither shows nor counts.
- **Personal:** the table `leaderboard_user` holds one row for each person and leaderboard, and
  nobody else sees it. It goes away with the account and with the leaderboard.
- **Who:** everyone who can open the leaderboard, students and teachers. `POST` and `DELETE`
  `/leaderboards/{id}/highlight` add and remove the star; a leaderboard the person cannot open is
  "not found". Highlighting twice, or removing a star that is not there, changes nothing.

The data comes from `App\Services\OwnLeaderboards`; the page is `Components/Leaderboards/OwnLeaderboards.vue`.

## The hall screen (Task #56)

`/hall` shows the leaderboards the system admin switched on, on a public display, one at a time, without login. Open
it in the browser on the hall computer and press F11. It reads the feed `/hall/leaderboards`.
Only the system admin puts a leaderboard there, with the *On the hall screen* switch on the admin
panel's *Hall screen* page (D7, Task #110). Being there does not open the leaderboard to other areas
on the signed-in pages (D4).

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

The words of the hall screen, the time on it and *Where am
I?* are in `resources/js/leaderboardWords.js`, which reads them from the dictionary
(`docs/translations.md`).

### Time on the hall screen

| Who | Where | What |
|---|---|---|
| Area admins (*Manage leaderboards*) | Leaderboard dialog, *Time on the hall screen* | 5 to 120 seconds, or empty for the default |
| The super admin | Admin panel, *Hall screen* | The default, and the time of any leaderboard |

The feed sends `seconds` for each board: `leaderboards.hall_seconds`, or else the default. The
default is the one row of the `settings` table (`App\Models\Setting`); without a row it is 15.

## Where am I? (Task #56)

On `/leaderboards`, under *Where am I?*, a student picks an area they can
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
- **Only students** get this section (Task #112). A ranked list exists only on a leaderboard that an
  admin created (D1 and D3, 7 November), so teachers and staff see no *Where am I?* and their
  `rankings` prop is `null`; an `?area=` in their address is ignored.

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

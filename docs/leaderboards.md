# Leaderboards: the Leaderboards page, the hall screen and the point calculation (Tasks #54, #55 and #56)

## The Leaderboards page (Task #54, redesigned on 9 November)

Everyone who is signed in finds **Leaderboards** in the menu, as a group with two links:
**Highlighted** (`/leaderboards`) and **All leaderboards** (`/leaderboards?show=all`). It is one
place to see rankings: a box per leaderboard at the top, and the selected board's ranking right
below, for students and staff alike. The separate board page is gone; old links such as
`/leaderboards/4` open `/leaderboards?board=4`, so nothing breaks.

- **A student's box** holds their own place in large type ("#2"), "of 4 · 10 pts" and the points
  to the next place ("20 pts to #1"), or "You are first". Without points in that area it says "You
  are not on it yet". The *Your points* box with the four categories is gone, here and on Home.
- **A staff box** describes the board, because staff are not on it: who leads ("1st: BENC07,
  30 pts", with only the board's columns), how many students are listed, and *On the hall screen*.
- **The ranking below** has a podium for the top 3 (second, first, third from left to right), then
  the list: the top 10 and the places around the student (L5), with "Show all" for every place
  (`?places=all`). Equal points share a place (1, 2, 2, 4). Only the board's columns are shown
  (L6), and the student's own row says "you".
- **Switching:** clicking another box reloads only the ranking (an Inertia partial reload of
  `selected`), keeps the choice in the address (`?board=`) and moves the focus to the ranking's
  heading. The first box is selected on opening, which is the first highlighted one.
- **Which leaderboards:** staff see the ones of the areas they can open, which are the areas they
  are assigned to and every area below them (D4). A student also sees the boards of every area
  above their own, such as their faculty and *University total* (`AreaLeaderboards::openTo()` and
  `canSee()`); boards of sibling areas stay hidden and their address says "not found". An
  archived area opens nothing above it. Being on the hall screen changes nothing here.
- **Home:** a student's Home shows the same boxes, starred first, then the rest; a teacher starts
  on the same boxes (`/`). A box there opens the Leaderboards page with that board selected.
- *Where am I?* stays below the ranking, for students only.

### Highlighted leaderboards (D6, 7 November)

A star on each box, and a *Highlight* button on the ranking, highlights a leaderboard for the
person who clicks. *Highlighted* lists the starred boards; while none is starred it lists every
board with the hint "Star up to 5 leaderboards to keep them here.", never an empty page. *All
leaderboards* lists every board, the starred ones first, each group in name order.

- **Limit:** five leaderboards for each person (`Leaderboard::HIGHLIGHT_LIMIT`). At the limit the
  server says "You can highlight up to 5 leaderboards. Remove a highlight first." and changes
  nothing. A highlight of a leaderboard the person can no longer open (their area changed, or it
  was archived) stays in the table but neither shows nor counts.
- **Personal:** the table `leaderboard_user` holds one row for each person and leaderboard, and
  nobody else sees it. It goes away with the account and with the leaderboard.
- **Who:** everyone who can open the leaderboard, students and teachers. `POST` and `DELETE`
  `/leaderboards/{id}/highlight` add and remove the star; a leaderboard the person cannot open is
  "not found". Highlighting twice, or removing a star that is not there, changes nothing.

The boxes come from `App\Services\OwnLeaderboards`, the ranking from `LeaderboardStandings::view()`.
The page is `Pages/Leaderboards/Index.vue`, with `Components/Leaderboards/BoardBoxes.vue`,
`BoardCard.vue` and `BoardRanking.vue`.

## The hall screen (Task #56)

`/hall` shows the leaderboards the system admin switched on, on a public display, one at a time, without login. Open
it in the browser on the hall computer and press F11. It reads the feed `/hall/leaderboards`.
Only the system admin puts a leaderboard there, with the *On the hall screen* switch on the admin
panel's *Hall screen* page (D7, Task #110). Being there does not open the leaderboard to other areas
on the signed-in pages (D4). On that page, the name of a leaderboard opens it in a new tab.

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

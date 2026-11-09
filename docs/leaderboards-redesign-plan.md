# Leaderboards redesign: plan and UI recommendations

Written 9 November 2026 by Vivian, for the next sprint. Nothing here is in the 9 November demo build.

The Leaderboards page becomes one place to see rankings: boxes at the top, and the selected board's ranking right below, for students and staff alike. This replaces the list of rows, the separate board page and the "Your points" box. The four fixed categories become a list the system admin edits.

## What changes

| # | Change | Who sees it | Today |
| --- | --- | --- | --- |
| 1 | *Leaderboards* in the menu opens into **Highlighted** and **All leaderboards** | Everyone | One menu link |
| 2 | **Your rankings** replaces the "Your points" box at the top of the Leaderboards page; the box also leaves Home | Students | Category points box |
| 3 | Boards are **boxes**; the selected box's ranking (podium and full list) shows right below, on the same page | Students and staff | A list of rows; clicking opens a separate page |
| 4 | Clicking another box swaps the ranking in place; the first starred board is selected on opening | Students and staff | Back and forth between pages |
| 5 | Up to **5 stars**; a student can drop one and star another | Everyone | Built in #101 |
| 6 | **Home** shows the starred boxes first, then the rest | Students | Category points only |
| 7 | A student sees boards on their own areas **and every area above them** (faculty, *University total*) | Students | Only their areas and below (D4) |
| 8 | The four fixed categories become a list the system admin edits | Everyone | Hard-coded enum |

The separate board page goes away. Old links such as `/leaderboards/4` open `/leaderboards?board=4`, so nothing breaks. The hall screen and the Areas → Leaderboards tab, where boards are created, keep their own views.

## Student view

1. **Menu.** *Leaderboards* is a group like *Events*: **Highlighted** (`/leaderboards`) and **All leaderboards** (`/leaderboards?show=all`).
2. **Your rankings.** The top section of the page. Under *Highlighted* it holds the starred boards; under *All leaderboards*, every board that concerns the student, each with a star.
3. **A box per board.** Name, area, the student's place ("#2 of 4"), their points, and the points to the next place ("20 pts to #1"). The selected box has a blue outline.
4. **The ranking below.** The selected board's podium (top 3) and then the full list, with the student's own row marked "you". Neptun codes only, unless the board shows names (D2).
5. **Switching.** Clicking another box swaps the ranking below without a page load. The address keeps the choice (`?board=4`), so reload and links work.
6. **No stars yet.** *Highlighted* shows all the student's boards with the hint "Star up to 5 leaderboards to keep them here."
7. **Home.** The same boxes, starred first, then the rest. A click opens the Leaderboards page with that board selected. The "Your points" box is removed from Home too, so the rankings lead.

*Where am I?* stays, below the ranking on the Leaderboards page, for students only. It answers a different question: a student's place in any area, category or tag, even where no leaderboard exists.

## Organiser and system admin view

Staff get the same page and the same menu, with boxes that describe the board instead of a place, because staff are not on the boards.

| In the box | Example |
| --- | --- |
| Name and area | *Informatics total*, Faculty of Informatics |
| The leader | 1st: BENC07, 30 pts |
| Students listed | 4 students |
| Status | *On the hall screen* chip, star |

Clicking a box shows that board's podium and full list below, as for students. The first starred board is selected on opening, or the first board if none is starred. The Areas → Leaderboards tab, where boards are created, edited and deleted, keeps its list for now; boxes there can follow later.

## Which boards a student sees

A student should see the boards of their own areas and of every area above them. Today (D4, PR #101) they see only their own areas and the areas below, so a student on a booth or a course sees no faculty or school board.

| Student | Assigned to | Sees today | Sees after the change |
| --- | --- | --- | --- |
| Anna | Databases | Boards on Databases | Databases, Faculty of Informatics, *University total* |
| Imre | Robotics booth | Nothing (no board on the booth) | Booth, fair and school boards |
| Erik | Athletics | Boards on Athletics | Athletics, Faculty of Sport, *University total* |

Still hidden: boards on sibling areas, such as Web Development for Anna or anything in Sport for her. Staff keep today's rule: the areas they can open and everything below.

This reverses part of D4 as recorded on 7 November, so it goes to the professor first (question 1 below).

## UI recommendations

One idea runs through all of them: the student's own number is the biggest thing on the screen, and everything else supports it. They use the existing design tokens from Task #91 and the calm look agreed on 26 October (no emojis, no playful wording).

**Boxes**

- Grid of boxes: 3 across on a laptop, 2 on a tablet, 1 on a phone. Gap 12 px, corner radius 12 px, a thin border, white background.
- Inside each box, top to bottom: name (16 px, medium), area (13 px, muted), the place in large type (28 px: "#2"), "of 4 · 10 pts" beside it, then the gap to the next place in green ("20 pts to #1") or "You are first".
- The star sits top right, 44 × 44 px touch target. The *On the hall screen* chip sits under the name for staff only; students do not need it.
- Selected box: 2 px blue border (the accent token), not a filled background, so the text stays readable.
- The whole box is one button with `aria-pressed`, so a screen reader says "Databases ranking, place 2 of 4, selected".
- A small colour bar on the left edge of each box could show its area later, once categories are an editable list. Leave it out until then.

**Ranking below the boxes**

- Header: board name, area, and "Neptun codes only" or the columns it shows.
- Podium for the top 3: second, first, third from left to right, first tallest and in the accent tint. Heights about 70, 50 and 36 px. Neptun code above each step, points on it. Fewer than 3 students: show only the steps that exist.
- Full list under the podium: Place, Neptun code (and names if switched on), Points. The student's own row has the accent tint and "you" after the code.
- Long lists: show places 1 to 10, then the student's row with the two rows around it, as #101 already does, with "Show all" to expand.
- Ties: same place number and the same points ("2, 2, 4"), as the standings already compute.
- Switching boards: replace the ranking without a page load and move keyboard focus to its heading, so screen-reader users hear the change. Keep the transition short (150 ms fade) and off for people who reduce motion.

**Empty and loading states**

- No boards at all: "No leaderboards for you yet. When an area you belong to makes a leaderboard, it appears here."
- A board with no points yet: show the box with "No points yet" and, below, "Points appear here after the first event."
- No stars: all boards plus the hint line, never an empty page.
- While loading: grey placeholder boxes of the same size, so the page does not jump.

**Phone**

- Boxes stack. After a tap, scroll smoothly to the ranking heading.
- Optionally, on phones the boxes become a horizontal strip that scrolls sideways, so the ranking stays on screen. Try both in the usability check.

**Accessibility (WCAG 2.2 AA, as in #61)**

- Headings in order: page title h1, *Your rankings* h2, board name h3. This also fixes the heading-order issue found in the 8 November check.
- Contrast 4.5:1 for all text, including the green gap line and the tinted "you" row.
- Never colour alone: the selected box also has its border, the own row also says "you", the podium also shows numbers.
- Every new text goes through `lang/en.json` (D9).

## Categories

Academic, Scientific, Sports and Community are a fixed list in the code (`app/Enums/TaxonomyCategory.php`, `resources/js/categoryColors.js`) and appear in 30 files. The proposal: a categories table the system admin edits in the admin panel, starting with the same four, and category and tag both optional on events (D10, #116 and #117).

| Where the four are used | What changes |
| --- | --- |
| Event form: a tag is required and gives the event its category | Tag and category optional; the category is picked from the table |
| Topic tags and tag requests (admin panel) | A tag points to a category row instead of an enum value |
| `PointCalculationService`: points per category | Totals per category row; points with no category count only in the total |
| Home: the four coloured boxes | Nothing: the points box is removed from Home in this plan |
| Event cards: colour from the category | Each category row stores its colour, picked from a fixed set of accessible colours |
| *Where am I?* category filter | Lists the table |
| `OwnLeaderboards`, `RankingExplorer`, `EventCatalog`, `NotificationFeed` | Read the table |
| Seeders, tests, `lang/en.json` | The four become seeded rows; their names stay translatable |

Points already earned are safe: the ledger stores them per event, not per category. A migration fills the new table with the four and links existing tags to them, so nothing changes on screen until an admin edits the list. Deleting a category that tags still use is refused; it can be renamed or hidden instead.

This is the largest item and waits for the professor's answer to question 4.

## Build plan

Seven tasks, in build order. Tasks 1 to 6 can be one sprint; task 7 follows the professor's answer on categories. Sizes: S under half a day, M about a day, L several days.

| # | Task | Main files | Size | Waits for | Tests |
| --- | --- | --- | --- | --- | --- |
| 1 | Students also see boards on the areas above them | `AreaLeaderboards::openTo()`, `canSee()` | S | Professor, question 1 | Anna sees *University total*, not Web Development; Erik never sees Informatics |
| 2 | Menu group: Highlighted and All leaderboards | `navigation.js`, `lang/en.json` | S | Nothing | Both links render for students and staff |
| 3 | One endpoint for a board's ranking, used by the page without reloading | `AreaLeaderboardController`, `LeaderboardStandings` | S | Nothing | Same rows and the same 404 rule as today's board page |
| 4 | *Your rankings*: boxes, selected board, ranking below, `?board=` and `?show=all`; remove "Your points"; `/leaderboards/{id}` redirects | `Leaderboards/Index.vue`, `OwnLeaderboards.vue`, new `BoardCard.vue` and `BoardRanking.vue` (podium and list), `OwnLeaderboards.php` | M | 3 | First starred board selected; switching changes the list; redirect keeps the board; no other student's details for students beyond the board's columns |
| 5 | Staff boxes: leader, count, hall-screen chip | `BoardCard.vue` (staff variant), `OwnLeaderboards.php` | S | 4 | Staff see the leader and count; no "your place" |
| 6 | Home: starred boxes first, then the rest; remove the points box | `Home.vue`, `StudentHome.php` | M | 4 | Order on Home matches stars; click opens the right board |
| 7 | Categories as an editable list | New `categories` table and Filament page; `TaxonomyCategory` users listed above; `categoryColors.js` | L | Professor, question 4 | Migration keeps every existing tag's category; deleting a used category is refused |

After tasks 4 and 6, rerun the accessibility check (#61) and a short usability round with three students: "Find your place on the Databases board" and "Star the Informatics board".

## Questions for the professor

| # | Question | Team proposal |
| --- | --- | --- |
| 1 | May a student see the boards of the areas above them (faculty, school), not only their own and below? | Yes. Siblings stay hidden. |
| 2 | Is one page with boxes and the ranking below the right shape, instead of a separate page per board? | Yes, as in this plan. |
| 3 | Five highlighted boards, changeable at any time: still right? | Yes. |
| 4 | What replaces the four fixed categories? | A list the system admin edits, starting with the same four; category and tag optional on events. |
| 5 | Should each faculty get its own hall screen, chosen by its area admin, alongside the school screen? | Yes, after this sprint; raised by Vivian on 8 November. |

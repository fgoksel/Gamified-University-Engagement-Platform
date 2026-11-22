# Badges (Task #103)

A badge is an achievement. It works like an event with no registering: a student receives it when its condition is met, and nobody applies for it (decisions of 27 October, L8, and 9 November, N10). This page says what the first version does and what it leaves for later.

## What a badge is

- A badge hangs on **one event**. An event has at most one badge. It has a name (60 characters), an optional line of text (200 characters) and an icon (Task #125, below).
- **The condition is the event's points.** A student who is credited for the event gets its badge, by whichever route the credit came: a scanned passport, a QR code (poster, slip or live) or an imported list ("Give points by import"). A surprise event works the same way: its list credits, and the badge follows.
- **Once.** The database keeps one row per student and badge (`badge_awards`). A second credit is refused by the ledger anyway.
- **A badge never changes points or rank.** It writes no ledger row and changes none, so every total, "Your points" and every leaderboard stays as it was. There is no leaderboard of badges.

## Who makes it

Whoever may edit the event (the `events.manage` capability at its owning area, or `events.create` for a draft). The **Badge** card on the event's edit page has a name, a text, **Add badge** or **Save badge**, and **Remove badge**. It works in every state the event can be edited in (draft, published, on hold, completed). A cancelled event takes no badge, and a removed event has no edit page at all.

A badge made after some students were credited goes to them at once: they met its condition before it existed. A credit that was taken back does not count. Changing the name or the text changes it for everybody who holds it.

## What takes a badge away

| What happens | What happens to the badge |
| --- | --- |
| A system admin reverses a student's credit (`PointLedger::reverse`) | That student loses the event's badge, nobody else. |
| A system admin removes the event (Task #58) | Every credit is reversed, so every student loses it. |
| The organiser removes the badge | Everybody who holds it loses it. The points stay. |
| "Reset all points" (Task #102) | Nothing. Badges are not points. |
| A draft event is deleted | Its badge goes with it. |

## Where a student sees it

- **Home**: the box "Your badges" with the latest six, newest first, and a link to the profile when there are more. A student with no badge sees no box.
- **Profile**: the section "Badges" lists all of them, newest first, with an empty text when there are none. Only students have the section.
- **Leaderboards and the hall screen** (Task #125, N10): the badge's icon beside the student's row. See "Badges on leaderboards" below.

## The icon (Task #125)

The organiser picks the icon on the **Badge** card, from a fixed list shown as buttons with the icon
and its name (a radio group: Tab reaches it, the arrow keys move): award, trophy, medal, star,
flame, rocket, heart, book, code, flask, dumbbell, team, light bulb, music and palette. The list is
`Badge::ICONS`; the request accepts only those keys (`Rule::in`), and the page gets the same list.
`resources/js/badgeIcons.js` maps each key to its icon from `@lucide/vue`. A badge without an icon,
such as one made before Task #125, shows the award.

## Badges on leaderboards (Task #125)

- **Which badges a row shows: the badges of the events whose points count on that board.** A
  board counts the events owned in its area and every area below it (`PointCalculationService::
  areaStandings`), and its rows show the badges of exactly those events. A badge the student
  holds from an event elsewhere does not show there.
- Up to three icons after the name or Neptun code, newest first, then "+N". On the Leaderboards
  page they are 16 px; on the hall screen they grow with its text. Each icon has the badge's name
  as a tooltip, and screen readers hear one sentence naming every badge ("Badges: SQL Night,
  Hackathon finisher"), the ones behind "+N" too.
- They show whatever the board's name columns are: with names off on the hall screen (N13) the
  Neptun code and the badges still show.
- **Nothing about the ranking changes.** `LeaderboardStandings::ranked()` is untouched; the badges
  are added to the rows of `view()` and `top()` afterwards, with one query per board. "Where am I?"
  (`place()`) shows no badges.
- The icon colour is `medal-gold-fg`, at least 5.4:1 on every row background in both themes.

## Left for later

- Conditions across several events ("N10: conditions across events can follow").
- A badge without an event, given to an imported list of students.
- "Remove all badges", the other button of Task #102: it is one `delete` of `badge_awards`, now that the table exists.
- A notification when a badge is received.

## Where it is

- `app/Services/LeaderboardStandings.php` (`badgesFor`): the badges on leaderboard rows.
- `resources/js/badgeIcons.js`, `resources/js/Components/Badges/BadgeIcons.vue`: the icons.
- `app/Services/BadgeAwards.php`: who holds what. `PointLedger::write()` and `PointLedger::reverse()` call it.
- `app/Services/EventBadges.php`, `app/Http/Controllers/EventBadgeController.php`, `app/Http/Requests/UpdateEventBadgeRequest.php`: the badge card.
- `app/Models/Badge.php`, `app/Models/BadgeAward.php`, `database/migrations/2026_11_22_100000_create_badges_tables.php`.
- `app/Services/StudentHome.php` and `app/Http/Controllers/ProfileController.php` hand the badges to the pages.
- `resources/js/Components/Events/EventBadgeCard.vue`, `resources/js/Components/Badges/BadgeList.vue`, `resources/js/Components/Profile/BadgesSection.vue`.
- `tests/Feature/Badges/`.

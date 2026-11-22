# Badges (Task #103)

A badge is an achievement. It works like an event with no registering: a student receives it when its condition is met, and nobody applies for it (decisions of 27 October, L8, and 9 November, N10). This page says what the first version does and what it leaves for later.

## What a badge is

- A badge hangs on **one event**. An event has at most one badge. It has a name (60 characters), an optional line of text (200 characters) and a column `icon` that nothing sets yet: the badge icons (Task #125) will use it.
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
- A badge is shown only to the student who holds it. The icons next to names on leaderboards (N10) are Task #125.

## Left for later

- Conditions across several events ("N10: conditions across events can follow").
- A badge without an event, given to an imported list of students.
- The icons next to names on leaderboards (Task #125).
- "Remove all badges", the other button of Task #102: it is one `delete` of `badge_awards`, now that the table exists.
- A notification when a badge is received.

## Where it is

- `app/Services/BadgeAwards.php`: who holds what. `PointLedger::write()` and `PointLedger::reverse()` call it.
- `app/Services/EventBadges.php`, `app/Http/Controllers/EventBadgeController.php`, `app/Http/Requests/UpdateEventBadgeRequest.php`: the badge card.
- `app/Models/Badge.php`, `app/Models/BadgeAward.php`, `database/migrations/2026_11_22_100000_create_badges_tables.php`.
- `app/Services/StudentHome.php` and `app/Http/Controllers/ProfileController.php` hand the badges to the pages.
- `resources/js/Components/Events/EventBadgeCard.vue`, `resources/js/Components/Badges/BadgeList.vue`, `resources/js/Components/Profile/BadgesSection.vue`.
- `tests/Feature/Badges/`.

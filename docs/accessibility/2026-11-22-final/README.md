# Final accessibility check (Task #131)

The finished interface, including the colourful student pages (#126), measured on 22 November 2026
in the light and the dark theme. Same tools and versions as on 30 October and 8 November (pinned
in `tests/accessibility/package.json`), so the numbers compare like with like.

- Light: [2026-11-22-final-light/summary.md](../2026-11-22-final-light/summary.md)
- Dark: [2026-11-22-final-dark/summary.md](../2026-11-22-final-dark/summary.md)

Measured: dev `6c35da9` with #126 merged and the #131 changes, before they were committed
(`serve-copy.sh --worktree`). Demo data: Sprint 2, plus two small additions in the throwaway copy
only (see "Demo data" below).

## Result

| Run | Pages | Lighthouse average | Lighthouse lowest | axe critical | axe serious | axe moderate | axe minor |
|---|---|---|---|---|---|---|---|
| 30 Oct, before | 20 | 97 | 85 | 0 | 28 | 12 | 0 |
| 8 Nov, after | 20 | 100 | 100 | 0 | 0 | 0 | 0 |
| 22 Nov, final, light | 27 | 100 | 100 | 0 | 0 | 0 | 0 |
| 22 Nov, final, dark | 27 | 100 | 100 | 0 | 0 | 0 | 0 |

axe counts are rules per page (a rule failing on several elements of one page counts once), at
both sizes (1280 x 800 and 390 x 844). Lighthouse runs on 25 of the 27 pages: the two dialogs are
measured by axe only, because Lighthouse loads a URL and cannot open a dialog first.

**Acceptance (#131): met.** axe reports no critical or serious issue on any measured page, in
either theme.

The scan pages without a camera were checked by hand (the run grants a fake camera): the headings were h3 after h1 (axe heading-order, moderate) and are now h2.

## What changed since 8 November

New pages in the check (the 20 pages of 8 November keep their names, so the tables line up):

| Who | Page |
|---|---|
| Guest | Hall screen |
| Student (Anna) | Leaderboards; Leaderboard, one board selected |
| Organiser (Ivan) | Areas, Access tab; Area leaderboards tab |
| Organiser (Ivan) | Organized by me, Restore (dialog open); Areas, Invite a person (dialog open) |

The interface itself gained the leaderboards redesign, the hall screen, adding people from an
area (#104), the event lifecycle dialogs (#122), the rotating passport (#100) and the colourful
student pages (#126). #126 had already removed one serious issue before this check: the
leaderboard boxes' `aria-label` did not contain their visible text (WCAG 2.5.3, label in name), so
the box is now named by its visible text.

Fixed in this task:

- **Dialogs had a second banner and footer landmark** (axe moderate, `landmark-no-duplicate-banner`
  and `landmark-unique`, phone layout, any dialog). The dialog is moved to the end of the page, where
  its `<header>` and `<footer>` counted as page landmarks next to the page's own header. They are
  plain `div`s now (`Components/Topics/Modal.vue`); nothing changes on screen.

The check itself (`tests/accessibility/`) can now measure the dark theme (`A11Y_THEME=dark`), open
a dialog before measuring, and measure uncommitted changes (`serve-copy.sh --worktree`).

## Controls under 44 x 44 px on the phone layout

Our own target is 44 x 44 px (#61). WCAG 2.2 AA (2.5.8) asks for 24 x 24 px, or enough space around
a smaller target, and axe's `target-size` rule found no failure: everything below passes AA. On
the 20 pages of 8 November the count is unchanged (75); the 7 new pages add 15 (90 in all).

| Group | Examples | Where | Worth fixing later? |
|---|---|---|---|
| The logo link | "Campus Engage, home", 177 x 36 | Every signed-in page | No: a second way home (the Home tab) is 44 px. |
| Text links in a line | "Forgot password?", "Back to log in", "All events", "Organized by me", event titles in lists, "Log out" (18-20 px high) | Log in, Home, lists, user menu | Only "Log out": it is a menu item, give it `min-h-11`. |
| Filter tabs and segment buttons | "Upcoming / Drafts / Past / On hold / Cancelled" (38 px high), "Upcoming / Past / All dates" (32 px) | Organized by me, All events | **Yes**: used often on the phone, simple to make `min-h-11`. |
| The dialogs' close button | "Close dialog", 28 x 28 | Every dialog | **Yes**: an icon-only button, our rule is `size-11`. |
| Form fields and buttons slightly under 44 px | date and search inputs (38-42), "Save as draft", "Publish event", "Save changes" (40-42), "Upload photo" (38), point chips "10 pts" (36) | Events, event form, profile, points import | Yes, low effort: `min-h-11` on the shared inputs and buttons. |
| Organiser page links styled as buttons | "Applicants", "Scan attendance", "Give points by import", "Edit", "Applied Students", "Point Crediting", "QR slips" (36-38 high) | Event page, organiser tabs | Yes, together with the filter tabs. |
| Narrow but 44 px high | "Show password" (40 x 44), "All" (43 x 44), "All (5)" (39 x 44), radio "First come, first served" (316 x 40 with its label) | Log in, profile, filters, event form | No: a pixel or two short in one direction. |
| "Request Topic Tag", "New event" (38 high) | | Organized by me | Yes, with the filter tabs on the same page. |

## Manual check (to fill in)

No tool sees whether the order makes sense or a label says the right thing. Keyboard only (Tab,
Shift+Tab, Enter, Space, Escape), and VoiceOver on macOS or iOS.

| What | How | Result |
|---|---|---|
| Home to an event, Apply, then Undo | Keyboard only: reach the event card, open it, Apply, Undo | |
| QR passport | Keyboard only: reach "Refresh now"; VoiceOver reads the name, the code's alt text and the tip that it changes every 7 seconds | |
| Scanner result | Organiser scans a passport: VoiceOver reads "Attendance Credited!" (or the refusal) and the student's details | |
| Restore dialog | Keyboard only: open it from "On hold", focus starts inside, Tab stays inside, Escape closes, focus returns to the button | |
| Delete dialog | The same; VoiceOver reads the title and the error when the typed title does not match | |
| Invite a person dialog | The same; VoiceOver reads the title, the required fields and each form error | |
| Focus visible | On every step above, the focused control has a visible outline, in light and dark | |
| Focus order | On every step above, the order follows the page from top to bottom | |
| VoiceOver: dialog titles | Each dialog is announced with its title when it opens | |
| VoiceOver: form errors | An error is read with its field | |

## Open issues

None from axe. The small controls above are below our own 44 px target but pass WCAG 2.2 AA.

## Demo data

Two additions, in the throwaway copy only (`tests/accessibility/seed-copy.php`, run by
`serve-copy.sh`), never in the real seeders:

- Ivan gets one more role on Faculty of Informatics that may import and give roles, so his Access
  tab shows "Invite a person". His demo role, Event organiser, cannot.
- One leaderboard, "Databases total", on Anna's own area. Since #119 a student sees only the boards
  of her own areas, and all Sprint 2 boards are above Anna's, so her Leaderboards page was empty.

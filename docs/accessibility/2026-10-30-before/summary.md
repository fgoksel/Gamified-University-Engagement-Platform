# Accessibility check: dev 9a2e918, before (Task #61)

Measured 2026-10-30 11:05 UTC at http://127.0.0.1:8099 with Lighthouse 13.6.0 (accessibility only, phone emulation), axe-core 4.14.0 (WCAG 2.2 A/AA and best practices, at desktop 1280x800 and phone 390x844) and Chrome/156.0.0.0.

Pages: 20. Average Lighthouse accessibility score: 97. Lowest: 85.

| Who | Page | Lighthouse | axe critical | axe serious | axe moderate | axe minor | Controls under 44 px (phone) |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Guest | Log in | 100 | 0 | 0 | 1 | 0 | 5 |
| Guest | Forgot password | 100 | 0 | 0 | 1 | 0 | 3 |
| Student (Anna) | Home | 100 | 0 | 1 | 1 | 0 | 2 |
| Student (Anna) | All events | 96 | 0 | 2 | 1 | 0 | 17 |
| Student (Anna) | Event page, open for applications | 85 | 0 | 4 | 1 | 0 | 3 |
| Student (Anna) | Event page, accepted and running | 85 | 0 | 4 | 1 | 0 | 3 |
| Student (Anna) | My events | 95 | 0 | 2 | 1 | 0 | 9 |
| Student (Anna) | QR passport | 100 | 0 | 1 | 1 | 0 | 3 |
| Student (Anna) | Scan | 100 | 0 | 1 | 1 | 0 | 3 |
| Student (Anna) | Notifications | 100 | 0 | 1 | 1 | 0 | 2 |
| Student (Anna) | Profile | 100 | 0 | 1 | 1 | 0 | 10 |
| Student (Anna) | Change password | 100 | 0 | 0 | 1 | 0 | 8 |
| Organiser (Ivan) | Organized by me | 85 | 0 | 4 | 0 | 0 | 38 |
| Organiser (Ivan) | Create event | 100 | 0 | 1 | 0 | 0 | 17 |
| Organiser (Ivan) | Edit event | 100 | 0 | 1 | 0 | 0 | 19 |
| Organiser (Ivan) | Applicants | 100 | 0 | 1 | 0 | 0 | 6 |
| Organiser (Ivan) | Scan attendance (passport) | 100 | 0 | 1 | 0 | 0 | 4 |
| Organiser (Ivan) | QR codes | 100 | 0 | 1 | 0 | 0 | 6 |
| Organiser (Ivan) | Points import | 100 | 0 | 1 | 0 | 0 | 3 |
| Organiser (Ivan) | Topics (ended at /topics/2) | 100 | 0 | 1 | 0 | 0 | 2 |

## axe issues by rule

| Impact | Rule | What it checks | WCAG | Pages | Elements | For example |
| --- | --- | --- | --- | --- | --- | --- |
| serious | [label-content-name-mismatch](https://dequeuniversity.com/rules/axe/4.14/label-content-name-mismatch?application=playwright) | Elements must have their visible text as part of their accessible name | 2.5.3 | 17 | 17 | `.px-5 > a[aria-label="Go to leaderboard"][href="/"]` |
| serious | [color-contrast](https://dequeuniversity.com/rules/axe/4.14/color-contrast?application=playwright) | Elements must meet minimum color contrast ratio thresholds | 1.4.3 | 5 | 15 | `article:nth-child(1) > .text-xs.flex-wrap.font-medium > .bg-primary\/1` |
| serious | [definition-list](https://dequeuniversity.com/rules/axe/4.14/definition-list?application=playwright) | <dl> elements must only directly contain properly-ordered <dt> and <dd> groups, <script>, <template> or <div> elements | 1.3.1 | 3 | 6 | `dl` |
| serious | [dlitem](https://dequeuniversity.com/rules/axe/4.14/dlitem?application=playwright) | <dt> and <dd> elements must be contained by a <dl> | 1.3.1 | 3 | 74 | `.items-start.gap-2:nth-child(1) > div > dt` |
| moderate | [region](https://dequeuniversity.com/rules/axe/4.14/region?application=playwright) | All page content should be contained by landmarks | best practice | 12 | 12 | `.text-lg` |

## Lighthouse audits that failed

| Audit | Weight | Pages |
| --- | --- | --- |
| Elements with visible text labels do not have matching accessible names. | 0 | 17 |
| Background and foreground colors do not have a sufficient contrast ratio. | 7 | 5 |
| `<dl>`'s do not contain only properly-ordered `<dt>` and `<dd>` groups, `<script>`, `<template>` or `<div>` elements. | 7 | 3 |
| Definition list items are not wrapped in `<dl>` elements | 7 | 3 |

## Elements per page (serious and critical)

### Student (Anna): Home

- serious, label-content-name-mismatch (desktop and phone): `.px-5 > a[aria-label="Go to leaderboard"][href="/"]`

### Student (Anna): All events

- serious, color-contrast (desktop and phone): `article:nth-child(1) > .text-xs.flex-wrap.font-medium > .bg-primary\/10.text-primary.px-2\.5` (#1a73db on #e8f1fb: 4.07:1, needs 4.5:1), `article:nth-child(2) > .text-xs.flex-wrap.font-medium > .bg-primary\/10.text-primary.px-2\.5` (#1a73db on #e8f1fb: 4.07:1, needs 4.5:1), `article:nth-child(3) > .text-xs.flex-wrap.font-medium > .bg-primary\/10.text-primary.px-2\.5` (#1a73db on #e8f1fb: 4.07:1, needs 4.5:1)
- serious, label-content-name-mismatch (desktop and phone): `.px-5 > a[aria-label="Go to leaderboard"][href="/"]`

### Student (Anna): Event page, open for applications

- serious, color-contrast (desktop and phone): `.bg-primary\/10` (#1a73db on #e8f1fb: 4.07:1, needs 4.5:1)
- serious, definition-list (desktop and phone): `dl`
- serious, dlitem (desktop and phone): `.items-start.gap-2:nth-child(1) > div > dt`, `.items-start.gap-2:nth-child(1) > div > dd`, `.items-start.gap-2:nth-child(2) > div > dt`, `.items-start.gap-2:nth-child(2) > div > dd`, `.items-start.gap-2:nth-child(3) > div > dt`, `.items-start.gap-2:nth-child(3) > div > dd`, `.items-start.gap-2:nth-child(4) > div > dt`, `.items-start.gap-2:nth-child(4) > div > dd:nth-child(2)`, and 7 more
- serious, label-content-name-mismatch (desktop and phone): `.px-5 > a[aria-label="Go to leaderboard"][href="/"]`

### Student (Anna): Event page, accepted and running

- serious, color-contrast (desktop and phone): `.bg-primary\/10` (#1a73db on #e8f1fb: 4.07:1, needs 4.5:1)
- serious, definition-list (desktop and phone): `dl`
- serious, dlitem (desktop and phone): `.items-start.gap-2:nth-child(1) > div > dt`, `.items-start.gap-2:nth-child(1) > div > dd`, `.items-start.gap-2:nth-child(2) > div > dt`, `.items-start.gap-2:nth-child(2) > div > dd`, `.items-start.gap-2:nth-child(3) > div > dt`, `.items-start.gap-2:nth-child(3) > div > dd`, `.items-start.gap-2:nth-child(4) > div > dt`, `.items-start.gap-2:nth-child(4) > div > dd:nth-child(2)`, and 7 more
- serious, label-content-name-mismatch (desktop and phone): `.px-5 > a[aria-label="Go to leaderboard"][href="/"]`

### Student (Anna): My events

- serious, color-contrast (desktop and phone): `.border-primary` (#1a73db on #f5f8fc: 4.36:1, needs 4.5:1), `.rounded-card.p-5.shadow-card:nth-child(1) > .items-start.justify-between.flex-wrap > .gap-2.flex-wrap.flex > .bg-emerald-500\/10.text-emerald-600.dark\:text-emerald-400` (#009966 on #e5f8f2: 3.31:1, needs 4.5:1), `.bg-amber-500\/10` (#e17100 on #fff5e6: 2.96:1, needs 4.5:1), `.rounded-card.p-5.shadow-card:nth-child(3) > .items-start.justify-between.flex-wrap > .gap-2.flex-wrap.flex > .bg-emerald-500\/10.text-emerald-600.dark\:text-emerald-400` (#009966 on #e5f8f2: 3.31:1, needs 4.5:1), `.rounded-card.p-5.shadow-card:nth-child(4) > .items-start.justify-between.flex-wrap > .gap-2.flex-wrap.flex > .bg-emerald-500\/10.text-emerald-600.dark\:text-emerald-400` (#009966 on #e5f8f2: 3.31:1, needs 4.5:1), `.rounded-card.p-5.shadow-card:nth-child(5) > .items-start.justify-between.flex-wrap > .gap-2.flex-wrap.flex > .bg-emerald-500\/10.text-emerald-600.dark\:text-emerald-400` (#009966 on #e5f8f2: 3.31:1, needs 4.5:1)
- serious, label-content-name-mismatch (desktop and phone): `.px-5 > a[aria-label="Go to leaderboard"][href="/"]`

### Student (Anna): QR passport

- serious, label-content-name-mismatch (desktop and phone): `.px-5 > a[aria-label="Go to leaderboard"][href="/"]`

### Student (Anna): Scan

- serious, label-content-name-mismatch (desktop and phone): `.px-5 > a[aria-label="Go to leaderboard"][href="/"]`

### Student (Anna): Notifications

- serious, label-content-name-mismatch (desktop and phone): `.px-5 > a[aria-label="Go to leaderboard"][href="/"]`

### Student (Anna): Profile

- serious, label-content-name-mismatch (desktop and phone): `.px-5 > a[aria-label="Go to leaderboard"][href="/"]`

### Organiser (Ivan): Organized by me

- serious, color-contrast (desktop and phone): `article:nth-child(1) > .justify-between.flex-wrap.gap-3 > .min-w-0 > .flex-wrap.gap-2.flex > .bg-primary\/10.text-primary.px-2\.5` (#1a73db on #e8f1fb: 4.07:1, needs 4.5:1), `article:nth-child(2) > .justify-between.flex-wrap.gap-3 > .min-w-0 > .flex-wrap.gap-2.flex > .bg-primary\/10.text-primary.px-2\.5` (#1a73db on #e8f1fb: 4.07:1, needs 4.5:1), `article:nth-child(3) > .justify-between.flex-wrap.gap-3 > .min-w-0 > .flex-wrap.gap-2.flex > .bg-primary\/10.text-primary.px-2\.5` (#1a73db on #e8f1fb: 4.07:1, needs 4.5:1), `article:nth-child(4) > .justify-between.flex-wrap.gap-3 > .min-w-0 > .flex-wrap.gap-2.flex > .bg-primary\/10.text-primary.px-2\.5` (#1a73db on #e8f1fb: 4.07:1, needs 4.5:1)
- serious, definition-list (desktop and phone): `article:nth-child(1) > dl`, `article:nth-child(2) > dl`, `article:nth-child(3) > dl`, `article:nth-child(4) > dl`
- serious, dlitem (desktop and phone): `article:nth-child(1) > dl > .items-start.gap-2.flex:nth-child(1) > div > dt`, `article:nth-child(1) > dl > .items-start.gap-2.flex:nth-child(1) > div > dd:nth-child(2)`, `article:nth-child(1) > dl > .items-start.gap-2.flex:nth-child(1) > div > dd:nth-child(3)`, `article:nth-child(1) > dl > .items-start.gap-2.flex:nth-child(2) > div > dt`, `article:nth-child(1) > dl > .items-start.gap-2.flex:nth-child(2) > div > dd`, `article:nth-child(1) > dl > .items-start.gap-2.flex:nth-child(3) > div > dt`, `article:nth-child(1) > dl > .items-start.gap-2.flex:nth-child(3) > div > dd:nth-child(2)`, `article:nth-child(1) > dl > .items-start.gap-2.flex:nth-child(3) > div > dd:nth-child(3)`, and 36 more
- serious, label-content-name-mismatch (desktop and phone): `.px-5 > a[aria-label="Go to leaderboard"][href="/"]`

### Organiser (Ivan): Create event

- serious, label-content-name-mismatch (desktop and phone): `.px-5 > a[aria-label="Go to leaderboard"][href="/"]`

### Organiser (Ivan): Edit event

- serious, label-content-name-mismatch (desktop and phone): `.px-5 > a[aria-label="Go to leaderboard"][href="/"]`

### Organiser (Ivan): Applicants

- serious, label-content-name-mismatch (desktop and phone): `.px-5 > a[aria-label="Go to leaderboard"][href="/"]`

### Organiser (Ivan): Scan attendance (passport)

- serious, label-content-name-mismatch (desktop and phone): `.px-5 > a[aria-label="Go to leaderboard"][href="/"]`

### Organiser (Ivan): QR codes

- serious, label-content-name-mismatch (desktop and phone): `.px-5 > a[aria-label="Go to leaderboard"][href="/"]`

### Organiser (Ivan): Points import

- serious, label-content-name-mismatch (desktop and phone): `.px-5 > a[aria-label="Go to leaderboard"][href="/"]`

### Organiser (Ivan): Topics

- serious, label-content-name-mismatch (desktop and phone): `.px-5 > a[aria-label="Go to leaderboard"][href="/"]`

## Controls under 44 x 44 px on the phone layout

- Guest: Log in: input[email] "" 308x42; input[password] "" 308x42; button "Show password" 40x42; a "Forgot password?" 117x18; button "Log in" 308x40
- Guest: Forgot password: input[email] "" 308x42; button "Send reset link" 308x40; a "Back to log in" 108x20
- Student (Anna): Home: a "Go to leaderboard" 177x36; button "Open menu" 40x40
- Student (Anna): All events: a "Go to leaderboard" 177x36; button "Open menu" 40x40; input[search] "" 358x42; button "Upcoming" 92x32; button "Past" 53x32; button "All dates" 80x32; select "All topics Databases" 358x38; input[date] "" 358x38 (x2); button "All" 43x30; button "Academic" 107x30; button "Scientific" 102x30; button "Sports" 84x30; button "Community" 119x30; a "Indexing Lab" 96x20; a "SQL Joins Workshop" 155x20; a "Database Design Hackathon" 213x20
- Student (Anna): Event page, open for applications: a "Go to leaderboard" 177x36; button "Open menu" 40x40; a "All events" 83x20
- Student (Anna): Event page, accepted and running: a "Go to leaderboard" 177x36; button "Open menu" 40x40; a "All events" 83x20
- Student (Anna): My events: a "Go to leaderboard" 177x36; button "Open menu" 40x40; a "Browse Events" 358x38; button "All (5)" 39x34; button "Active (4)" 62x34; button "History (1)" 65x34; button "Withdraw" 82x30 (x3)
- Student (Anna): QR passport: a "Go to leaderboard" 177x36; button "Open menu" 40x40; button "Refresh now" 113x28
- Student (Anna): Scan: a "Go to leaderboard" 177x36; button "Open menu" 40x40; input[text] "" 324x42
- Student (Anna): Notifications: a "Go to leaderboard" 177x36; button "Open menu" 40x40
- Student (Anna): Profile: a "Go to leaderboard" 177x36; button "Open menu" 40x40; button "Upload photo" 138x38; input[password] "" 316x42 (x3); button "Show password" 40x42 (x3); button "Change password" 316x40
- Student (Anna): Change password: input[password] "" 308x42 (x3); button "Show password" 40x42 (x3); button "Save" 308x40; button "Log out" 49x20
- Organiser (Ivan): Organized by me: a "Go to leaderboard" 177x36; button "Open menu" 40x40; button "Request Topic Tag" 169x38; a "New event" 117x38; a "Upcoming 4" 123x38; a "Drafts 1" 93x38; a "Past 1" 81x38; a "On hold 1" 104x38; a "Cancelled 1" 118x38; a "Applicants" 96x36 (x4); a "Scan attendance" 136x36 (x4); a "QR codes" 88x36 (x4); a "Give points by import" 165x36 (x4); a "Edit" 52x36 (x4); button "Put on hold" 100x36 (x4); button "Cancel event" 111x36 (x4); button "Delete" 67x36
- Organiser (Ivan): Create event: a "Go to leaderboard" 177x36; button "Open menu" 40x40; input[text] "" 316x42 (x2); select "Choose a tag… Exam Preparation (academic" 316x40; select "Choose a topic… Faculty of Informatics D" 316x40; select "Invited students only" 316x40; input[number] "30" 316x42; input[number] "10" 316x42; input[radio] "fcfs" 316x40; input[datetime-local] "" 316x42 (x4); input[checkbox] "on" 316x40; button "Save as draft" 358x42; button "Publish event" 358x40
- Organiser (Ivan): Edit event: a "Go to leaderboard" 177x36; button "Open menu" 40x40; a "Organized by me" 129x20; a "Applied Students" 137x38; a "Point Crediting" 123x38; a "QR codes" 86x38; input[text] "SQL Joins Workshop" 316x42; select "Choose a tag… Exam Preparation (academic" 316x40; input[text] "Room 2.14, Informatics Building" 316x42; select "Choose a topic… Databases" 316x40; select "Everyone in Databases Everyone in Facult" 316x40; input[number] "2" 316x42; input[number] "15" 316x42; input[radio] "fcfs" 316x40; input[datetime-local] "2026-11-02T11:01" 316x42; input[datetime-local] "2026-11-02T13:01" 316x42; input[datetime-local] "2026-11-02T10:01" 316x42; input[datetime-local] "2026-11-16T11:01" 316x42; button "Save changes" 358x40
- Organiser (Ivan): Applicants: a "Go to leaderboard" 177x36; button "Open menu" 40x40; a "Back to my events" 138x20; button "10 pts" 50x36 (x2); button "30 pts" 50x36
- Organiser (Ivan): Scan attendance (passport): a "Go to leaderboard" 177x36; button "Open menu" 40x40; a "Back to my events" 138x20; a "View applicants list" 122x20
- Organiser (Ivan): QR codes: a "Go to leaderboard" 177x36; button "Open menu" 40x40; a "Organized by me" 129x20; a "Print 1 poster" 135x38; input[number] "1" 316x42; button "Revoke" 73x36
- Organiser (Ivan): Points import: a "Go to leaderboard" 177x36; button "Open menu" 40x40; a "Organized by me" 129x20
- Organiser (Ivan): Topics: a "Go to leaderboard" 177x36; button "Open menu" 40x40

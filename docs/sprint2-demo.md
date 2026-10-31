# Sprint 2 demo: events, applications, attendance and points (Task #67)

A click-by-click script for the supervisor demo. It shows each acceptance criterion of #67
(two branches with refused cross-branch actions, no overbooking, no double credit and waitlist
promotion), and the full run organiser → event → application → attendance → points, credited both
by hand and by QR code. Every step below is also checked automatically by
`tests/Feature/Sprint2/Sprint2DemoSeederTest.php`, and the same journeys run end to end in
`tests/Feature/Sprint2/Sprint2JourneyTest.php`.

## 1. Before the demo (about 5 minutes)

The event times are relative to the moment the data is seeded: the *Indexing Lab* and the
*Campus 5K Run* are running right then and stay open for two hours. Seed shortly before the demo.

```bash
docker compose up -d
docker compose exec app php artisan migrate:fresh --seed     # empties the database first
docker compose exec app php artisan db:seed --class=Sprint2DemoSeeder
```

- App: <http://localhost:8081>. Emails that were "sent": Mailpit at <http://localhost:8025>.
- Notifications and emails are queued. The `queue` container sends them; if it is not running,
  run `docker compose exec app php artisan queue:work --stop-when-empty` once.
- Use two browser windows (one normal, one private) so an organiser and a student are signed in
  side by side. The student's "My QR passport" can also be opened on a phone.
- Running the demo seeder again changes nothing. To start over, repeat both commands.

## 2. The cast

Every demo account uses the password `password`.

| Account | Name | Is | Where |
|---|---|---|---|
| `sprint2.ivan@example.test` | Ivan Kovács | organiser | Faculty of Informatics (branch A) |
| `sprint2.sara@example.test` | Sara Szabó | organiser | Faculty of Sport (branch B) |
| `sprint2.anna@example.test` | Anna Nagy (ANNA01) | student | Databases, in branch A |
| `sprint2.bence@example.test` | Bence Tóth (BENC02) | student | Databases |
| `sprint2.csilla@example.test` | Csilla Varga (CSIL03) | student | Databases |
| `sprint2.dani@example.test` | Dániel Horváth (DANI04) | student | Databases |
| `sprint2.eszter@example.test` | Eszter Kiss (ESZT05) | student | Databases |
| `sprint2.erik@example.test` | Erik Molnár (ERIK06) | student | Athletics Club, in branch B |
| `sprint2.fanni@example.test` | Fanni Balogh (FANN07) | student | Athletics Club |

```text
Demo University
├── Faculty of Informatics      Ivan: "Event organiser"
│   ├── Databases               Anna, Bence, Csilla, Dániel, Eszter: "Event student"
│   └── Web Development
└── Faculty of Sport            Sara: "Event organiser"
    └── Athletics Club          Erik, Fanni: "Event student"
```

Both roles are ordinary topic roles (Topics → Roles); a title gives no power, the capabilities do.

| Event | Branch | State at the start of the demo |
|---|---|---|
| SQL Joins Workshop | A | First come first served, 2 places: Anna and Bence accepted, Csilla waitlisted |
| Database Design Hackathon | A | Selection mode, 2 places, 4 pending: Anna, Bence, Dániel, Eszter |
| Indexing Lab | A | **Running now**; Anna, Bence, Csilla, Dániel accepted; Bence already credited; a poster QR exists |
| Intro to SQL Lecture | A | Completed last week; Anna, Bence, Csilla, Eszter credited 10 points |
| Query Optimisation Seminar | A | Draft |
| Database Careers Evening | A | On hold; Anna applied |
| Lab Safety Briefing | A | Cancelled ("The lab is being renovated.") |
| Surprise SQL Quiz | A | Hidden from students for good; its points are given by importing a list |
| Campus 5K Run | B | **Running now**; Erik and Fanni accepted; Erik credited |
| Athletics Championship | B | Open; Fanni accepted |

## 3. The script

### Step 1: an organiser creates an event (UC-1.3)

1. Sign in as **Ivan**. *Events → Organized by me* lists his branch's events in every state: draft,
   published, on hold, cancelled, completed, and the hidden *Surprise SQL Quiz* with its badge.
2. *Create event*: the topic picker only offers topics in the Faculty of Informatics. Ivan cannot
   create events for the Faculty of Sport.

### Step 2: two branches, refused cross-branch actions (acceptance criterion)

1. As Ivan, open *All events*. *Campus 5K Run* (Sport) is not listed.
2. Paste Sara's event address, `/events/<id>/scan` of the Campus 5K Run (copy it from Sara's
   window): Ivan is sent back with *"You do not have permission to view this page."* Neither its
   applicants, its QR codes, nor putting it on hold or cancelling it work for him.
3. Sign in as **Anna** in the other window: *All events* shows only the Databases events. She cannot
   see or apply to the Sport events. Erik, in turn, cannot see the Informatics events.
4. Sign in as **Sara**: she runs her own branch and sees nothing of Ivan's.

### Step 3: no overbooking (acceptance criterion)

1. As **Eszter**, open *SQL Joins Workshop* (2 places, both taken) and apply: *"The event is at
   capacity. You have been placed on the waitlist."* Her *My events* shows *Waitlisted*.
2. The second-to-last and last places are given out under a database lock, so two students
   applying at the same moment never both get the last place.
3. Selection mode: as Ivan, open *Database Design Hackathon → Applicants* and tick three students for
   two places. Saving asks *"The number of selected students exceeds the maximum capacity. Would you
   like to modify the maximum capacity?"* Answer *No*, select **Anna and Bence**, save, and reject
   **Dániel**. Each of them receives a notification and an email (Mailpit).

### Step 4: waitlist promotion (acceptance criterion)

1. As **Anna**, open *My events → SQL Joins Workshop* and withdraw.
2. **Csilla**, first in line, moves up to *Accepted* by herself and is told *"A place opened up: you
   are in"* (bell icon, Notifications page and Mailpit). Eszter is still waitlisted.

### Step 5: attendance and points, by hand and by QR code (UC-1.7, UC-1.8, UC-2.3)

All at the running **Indexing Lab** (20 points):

1. **Checklist (manual):** Ivan opens *Indexing Lab → Scan attendance*, switches to the list, ticks
   **Csilla** and presses *Credit selected*. Bence already shows as credited.
2. **Teacher scans the student (QR passport):** Anna opens *My QR passport* (it changes every
   minute, so a screenshot stops working). Ivan points the scanner camera at it, or types `ANNA01`.
   *Points credited to Anna Nagy (+20 pts).*
3. **Student scans the event (poster):** Ivan opens *Indexing Lab → QR codes* and prints or shows the
   poster. **Dániel** opens *Scan QR code* and points his camera at it, or pastes the link under the
   image: *20 points credited.*
4. Anna, Bence, Csilla and Dániel now have their points; the lecture of last week adds 10 to the
   first three.

### Step 6: no double credit (acceptance criterion)

1. Ivan credits **Bence** again (type `BENC02`): *"Points have already been credited to Bence Tóth
   for this event."*
2. Dániel scans the same poster again: refused. A paper slip works once in total.
3. The ledger only ever grows: a row is never edited or deleted. A mistake is corrected by a System
   Admin with a negative *reversal* row that points back at the original.

### Step 7: lifecycle notifications (Task #53)

1. As Ivan, restore *Database Careers Evening* (on hold): Anna is told it is back and keeps her place.
2. Cancel an event with a reason: everyone still applied receives the reason; students who withdrew
   or were rejected are left alone.

### Step 8: topic changes freeze events (Task #66), optional

In *Topics*, a branch lead archives *Databases*: its events disappear for students and nothing can be
applied to or credited until the topic is restored. (The demo organisers hold only event
capabilities; use the System Admin or a role with `topic.archive` to show this.)

## 4. Regression of Sprint 1

- The System Admin panel (`/admin`, organisers, students, faculties, tags) and the Topics explorer
  are part of the automated suite. `Sprint2DemoSeederTest` also opens every main page for each kind
  of demo account (organiser, student, System Admin).
- Run everything: `docker compose exec app php artisan test` and `./vendor/bin/pint --test`;
  for the pages `npm run lint`, `npm run format:check` and `npm run build`.

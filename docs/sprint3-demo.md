# Sprint 3 demo: a fair with two booths (Task #90)

A click-by-click script for the fair part of the Sprint 3 demo. For a fair, each booth is a sub-area
with its own students, and points only go to the students assigned to that area (26 October,
recording 28:25). Every step below is also checked automatically by
`tests/Feature/Sprint3/Sprint3DemoSeederTest.php`, and each way of giving points is tried in
`tests/Feature/Events/FairBoothsTest.php`.

## 1. Before the demo

Both booth events start half an hour before the data is seeded and run for four hours, so seed
shortly before the demo. It adds to the Sprint 2 demo data, and creates that first if it is missing.

```bash
docker compose exec app php artisan migrate
docker compose exec app php artisan db:seed --class=Sprint3DemoSeeder
```

- Running it again changes nothing. To start over, empty the database as in `docs/sprint2-demo.md`.
- Scanning with a phone camera needs an https address: see `docs/demo-over-https.md`.

## 2. The cast

Every demo account uses the password `password`.

| Account | Name | Is | Where |
|---|---|---|---|
| `sprint3.greta@example.test` | Gréta Fehér | organiser | University Fair, and so both booths |
| `sprint3.hanna@example.test` | Hanna Lakatos (HANN01) | student | Robotics booth |
| `sprint3.imre@example.test` | Imre Szabó (IMRE02) | student | Robotics booth |
| `sprint3.julia@example.test` | Júlia Kocsis (JULI03) | student | Coding booth |
| `sprint3.karoly@example.test` | Károly Pintér (KARO04) | student | Coding booth |
| `sprint3.lili@example.test` | Lili Papp (LILI05) | student | Both booths |
| `sprint3.marton@example.test` | Márton Farkas (MART06) | student | The fair itself, no booth |
| `sprint3.nora@example.test` | Nóra Balla (NORA07) | student | No role in the fair |

```text
Demo University
└── University Fair        Gréta: "Fair organiser"; Márton: "Event student"
    ├── Robotics booth     Hanna, Imre, Lili: "Event student"
    └── Coding booth       Júlia, Károly, Lili: "Event student"
```

| Event | Announced to | State at the start of the demo |
|---|---|---|
| Robot obstacle course (15 points) | Robotics booth | **Running now**; Imre credited; Lili has a place; Hanna has not applied yet |
| Code golf challenge (10 points) | Coding booth | **Running now**; Károly credited; Júlia and Lili have a place |

## 3. The script

### Step 1: a booth is a sub-area with its own students

1. Sign in as **Gréta**. *Topics* opens at University Fair, with the two booths below it.
2. *New topic* adds another booth, for example Chemistry booth.
3. On the new booth, *Access → Import students*: choose a file with a column of Neptun codes and the
   role "Event student". Leave the fair unticked, unless those students should belong to the fair too.

### Step 2: only the students assigned to a booth see its event

Sign in as each student and open *Events*:

| Student | Sees |
|---|---|
| Hanna | Robot obstacle course |
| Júlia | Code golf challenge |
| Lili | Both |
| Márton | Neither: Márton belongs to the fair, and nothing flows down to the booths |
| Nóra | Neither: Nóra has no role in the fair |

### Step 3: apply and check in with the live code

1. As **Hanna**, open *Robot obstacle course* and apply. Applications stay open while the event runs.
2. As **Gréta**, open *Organized by me*, then *Show attendance QR* on *Robot obstacle course*.
3. On Hanna's phone, open *Scan* and scan the code: "Check-in recorded. 15 points credited."

### Step 4: the same code gives nothing outside the booth

As **Júlia**, **Márton** or **Nóra**, scan the same code: "You are not registered or selected for this
event." No points are written.

### Step 5: an imported list credits only the booth's students

1. As **Gréta**, open *Give points by import* on *Code golf challenge*.
2. Upload a file with this column:

   ```text
   Neptun Code
   JULI03
   KARO04
   HANN01
   MART06
   NORA07
   ```

3. The check shows Júlia to credit, Károly as already credited, and Hanna, Márton and Nóra as
   "Not assigned to Coding booth, the area of this event."
4. Give the points. Importing the same file again adds nothing.

### Step 6, optional: a student taken off a booth stops earning there

1. As **Gréta**, on *Coding booth → Access*, end Lili's role.
2. *Show attendance QR* on *Code golf challenge*. Lili's scan is refused: Lili is no longer assigned to
   the Coding booth. The place at the Robotics booth still works.

An organiser can announce a booth event to the whole fair instead of the booth. It is then open to the
students assigned to the fair, and those who applied before the change keep their places.

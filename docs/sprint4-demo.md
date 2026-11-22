# Sprint 4 demo: the final presentation (Task #129)

A click-by-click script for the presentation of the whole application (#130). It runs on the
presentation data of `Sprint4DemoSeeder`, which builds on the Sprint 2 and Sprint 3 demo data and adds
what the professor asked for on 7 and 9 November:

- **N3:** the tree starts at a University root with its own area admin, who makes the university-wide
  leaderboards and keeps the category list.
- **N2:** each course has its own area admin, who makes that course's leaderboards.
- **D4, change of 19 November:** a student sees the leaderboards of the areas they are assigned to and
  below, never above. Every demo student still sees one on Home: a board on their course or club, or an
  assignment to the area of the board (the fair).
- **N4:** when an area admin leaves, the system admin hands the area to someone else.

Steps 2 to 8 are checked automatically by `tests/Feature/Sprint4/Sprint4JourneyTest.php` on freshly
seeded data, and the data itself by `tests/Feature/Sprint4/Sprint4DemoSeederTest.php`.

## 1. Before the presentation

The *Indexing Lab* runs for two hours from the moment the data is seeded, and the two fair booths for
four hours, so seed shortly before.

```bash
docker compose up -d
docker compose exec app php artisan migrate:fresh --seed     # empties the database first
docker compose exec app php artisan db:seed --class=Sprint4DemoSeeder
```

- It creates the Sprint 2 and Sprint 3 data first. Running it again changes nothing; to start over,
  repeat both commands.
- Sign in once as the system admin before the presentation (`ADMIN_EMAIL` and `ADMIN_PASSWORD` in
  `.env`) and choose a new password: the seeded one is temporary.
- The phone scan in step 6 needs an https address: follow `docs/demo-over-https.md` (built assets,
  `APP_DEBUG=false`, the tunnel). Never sign in to `/admin` through the tunnel.
- Use two browser windows (one normal, one private) and the phone.

## 2. The cast

Every demo account uses the password `password`. The new accounts of Sprint 4:

| Account | Name | Is |
|---|---|---|
| `sprint4.olga@example.test` | Olga Fodor | area admin of **Demo University**, the root (N3) |
| `sprint4.rita@example.test` | Rita Major | area admin of the **Databases** course (N2) |
| `sprint4.tamas@example.test` | Tamás Bíró | area admin of the **Web Development** course, who leaves (N4) |
| `sprint4.zsofia@example.test` | Zsófia Kertész | no role yet: takes over Web Development (N4) |
| `sprint4.otto@example.test` | Ottó Vass (OTTO01) | student on Web Development |

From earlier sprints: Ivan and Sara organise the two faculties, Gréta the fair; the students are in
`docs/sprint2-demo.md` and `docs/sprint3-demo.md`. All three use the role *Area admin* (every right on
the area and below it), given by the system admin to Olga and by Olga to Rita and Tamás.

```text
Demo University                   Olga: Area admin
├── Faculty of Informatics        Ivan: Event organiser
│   ├── Databases                 Rita: Area admin; Anna, Bence, Csilla, Dániel, Eszter
│   └── Web Development           Tamás: Area admin; Nóra, Ottó
├── Faculty of Sport              Sara: Event organiser
│   └── Athletics Club            Erik, Fanni
└── University Fair               Gréta: Fair organiser; Márton, and the booth students (D4)
    ├── Robotics booth            Hanna, Imre, Lili
    └── Coding booth              Júlia, Károly, Lili
```

## 3. The leaderboards at the start

Every board shows the Neptun code and no name (D2). Points come from the events of the area and below.

| Leaderboard | Area | Made by | Who sees it | Standing |
|---|---|---|---|---|
| Databases course | Databases | Rita | its five students | Bence 30, then Anna, Csilla, Eszter 10; Dániel not on it yet |
| Web Development course | Web Development | Tamás | Nóra, Ottó | Nóra and Ottó 10 |
| Athletics Club ranking | Athletics Club | Sara | Erik, Fanni | Erik 25 |
| Fair ranking | University Fair | Gréta | the fair's six students | Imre 15, Károly 10 |
| University total, Informatics total, Sport total | the root and the faculties (Sprint 2) | the system admin | staff of those areas; guests on `/hall` | |

No student sees *University total*, *Informatics total* or *Sport total*: nobody sees the boards above
their own areas. The hall screen shows them to everyone.

## 4. The script

### Step 1: the hall screen, for guests

Open `/hall` in a private window, signed out. The three Sprint 2 boards take turns, with Neptun codes only.

### Step 2: the system admin maintains, the root area admin runs the university

1. Sign in as the **system admin**: the admin panel opens, for maintenance only. The dashboard's
   *Active events* says "4 running now · 5 upcoming".
2. *Areas → Demo University → Access*: Olga holds *Area admin* here, given by the system admin (N3).
   From here on the system admin only maintains; Olga runs the tree.

### Step 3: Olga keeps the categories and makes the university-wide leaderboard (N3)

1. Sign in as **Olga**. *Categories* lists the category list of the whole university; only she and the
   system admin can change it (Task #116). Rita, on a course, cannot open it.
2. *Areas → Demo University → Leaderboards → New leaderboard*. Name it "Whole university" and keep the
   defaults: the Neptun code shows, names stay hidden. There is no Publish button.

### Step 4: the system admin puts it on the hall screen

1. As the **system admin**, *Admin panel → Hall screen*: switch *On the hall screen* on for "Whole university".
2. Reload `/hall`: the board appears within 90 seconds, Bence (BENC02) on top, no names (N13).
3. As **Anna**, open *Leaderboards → All leaderboards*: only *Databases course*. The address of
   "Whole university" says "not found": Anna is not assigned to the root (D4).

### Step 5: a student's Home

Sign in as **Anna**. *Your points* shows 10 points and a tile per category. Below it, *Databases course*:
"#2 of 4 · 10 pts", "20 pts to #1".

### Step 6: the course admin shows the live code, the student scans it with the phone (N2)

1. As **Rita**, *My events → Organized by me → Indexing Lab → Show attendance QR*. Rita can, because she
   runs the Databases course, although Ivan created the event.
2. On the **phone**, over the https address, sign in as **Anna**, open *Scan* and scan the code:
   "Check-in recorded. 20 points credited."
3. Anna's Home: *Databases course* now says "#1", level with Bence on 30. Scanning again gives nothing.

### Step 7: the fair's leaderboard counts every booth (D4)

1. As **Hanna**, open *Robot obstacle course* and apply: applications stay open while it runs.
2. As **Gréta**, *Show attendance QR* on *Robot obstacle course*; Hanna scans it: 15 points.
3. Hanna's Home: *Fair ranking*, "#1", level with Imre. Károly is third.

### Step 8: an area admin leaves, the system admin hands the course over (N4)

1. As **Ottó**, apply to *Vue.js Workshop*. Nóra already has a place.
2. As the **system admin**, *Admin panel → Areas → Demo University → Faculty of Informatics → Web
   Development → Access*. On Tamás's *Area admin*, choose *Replace holder*, pick **Zsófia**, give the
   reason "Tamás leaves the university.", *Continue*, then confirm.
3. Sign in as **Tamás**: Web Development is gone from his areas.
4. Sign in as **Zsófia**: *Organized by me* lists *Vue.js Workshop*, with Nóra and Ottó on its applicants.
   The leaderboard, the points and the event stayed with the course; only the person changed.
   The Access tab shows "handed over" on Tamás's ended role.

## 5. What is checked automatically

| Check | Test |
|---|---|
| Every demo student sees exactly their own board on Home, none above | `Sprint4DemoSeederTest::test_every_demo_student_sees_a_leaderboard_on_home` |
| Olga holds the root and the category list; Rita and Tamás hold their courses, given by Olga | `Sprint4DemoSeederTest` |
| Each board made by the person who runs its area, off the hall screen, Neptun code only | `Sprint4DemoSeederTest` |
| Steps 2 to 4: the root board, the hall screen, no names for guests | `Sprint4JourneyTest::test_the_root_area_admin_makes_a_university_wide_leaderboard_for_the_hall_screen` |
| Step 6: live code, one credit, the course board moves | `Sprint4JourneyTest::test_a_student_checks_in_with_the_live_code_and_moves_up_the_course_leaderboard` |
| Step 7: the fair board counts every booth | `Sprint4JourneyTest::test_an_organiser_opens_check_in_at_a_running_fair_booth` |
| Step 8: the handover, and what stays with the course | `Sprint4JourneyTest::test_the_system_admin_hands_a_course_over_and_everything_stays_with_it` |

The phone over https (step 6.2) is not automated: a test cannot hold a camera. Record each run below.

## 6. Runs on a phone

| Date | Commit | Phone and browser | https address from | Result |
|---|---|---|---|---|
| 22 Nov 2026 | `544c8b4` | Samsung Galaxy A56, Chrome (Android) | Cloudflare quick tunnel (`trycloudflare.com`), `docs/demo-over-https.md` | Passed: Anna scanned Rita's Indexing Lab code, got 20 points and was #1 on *Databases course* on her Home |

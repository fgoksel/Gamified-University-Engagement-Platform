<?php

namespace Database\Seeders;

use App\Enums\Capability;
use App\Enums\UserRole;
use App\Models\Event;
use App\Models\EventApplication;
use App\Models\EventApplicationTransition;
use App\Models\RoleDefinition;
use App\Models\Semester;
use App\Models\Topic;
use App\Models\TopicTag;
use App\Models\User;
use App\Services\AreaLeaderboards;
use App\Services\AssignmentService;
use App\Services\EventApplicationService;
use App\Services\PointLedger;
use App\Services\RoleService;
use Illuminate\Database\Seeder;

/**
 * DEMO DATA ONLY (Task #129): the data of the final presentation. Not part of DatabaseSeeder:
 * production and fresh installs stay blank. Run it shortly before the presentation, because
 * some event times are relative to now (two events are running at that moment):
 *
 *     php artisan db:seed --class=Sprint4DemoSeeder
 *
 * It adds to the Sprint 2 and Sprint 3 data, which it creates first if they are missing, and
 * gives the tree what the professor asked for on 7 and 9 November:
 *
 * - N3: the University root has its own area admin, who makes the university-wide leaderboards
 *   and keeps the category list.
 * - N2: each course has an area admin, who makes that course's leaderboards.
 * - D4 and the change of 19 November: a student sees the leaderboards of the areas they are
 *   assigned to and below, never above. So every demo student gets a leaderboard: one on their
 *   course or club, or an assignment to the area of the board (the fair).
 * - N4: an area admin who leaves, and a colleague the system admin hands the course to.
 *
 * Roles, assignments, leaderboards, applications and credits go through the real services, so
 * the audit trail is what the app itself would write. Running it again changes nothing. It
 * refuses to run in production. The walkthrough is in docs/sprint4-demo.md.
 */
class Sprint4DemoSeeder extends Seeder
{
    public const PASSWORD = Sprint2DemoSeeder::PASSWORD;

    /** The role of an area admin (N1, N2, N3): every right on the area and everything below it. */
    public const AREA_ADMIN = 'Area admin';

    public const WEB = 'Web Development';

    public const WEB_LECTURE = 'HTML and CSS Basics';

    public const WEB_WORKSHOP = 'Vue.js Workshop';

    /**
     * Demo accounts: key => [name, email, account type, Neptun code].
     *
     * @var array<string, array{0: string, 1: string, 2: UserRole, 3: ?string}>
     */
    public const PEOPLE = [
        'olga' => ['Olga Fodor', 'sprint4.olga@example.test', UserRole::Teacher, null],
        'rita' => ['Rita Major', 'sprint4.rita@example.test', UserRole::Teacher, null],
        'tamas' => ['Tamás Bíró', 'sprint4.tamas@example.test', UserRole::Teacher, null],
        'zsofia' => ['Zsófia Kertész', 'sprint4.zsofia@example.test', UserRole::Teacher, null],
        'otto' => ['Ottó Vass', 'sprint4.otto@example.test', UserRole::Student, 'OTTO01'],
    ];

    /**
     * The leaderboards this data adds: name => [area, who makes it]. Each one shows the Neptun
     * code and no name, the default since D2 (Task #62).
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const LEADERBOARDS = [
        'Databases course' => ['Databases', 'rita'],
        'Web Development course' => [self::WEB, 'tamas'],
        'Athletics Club ranking' => ['Athletics Club', 'sara'],
        'Fair ranking' => [Sprint3DemoSeeder::FAIR, 'greta'],
    ];

    /** The booth students of the Sprint 3 data, who are also assigned to the fair itself (D4). */
    public const FAIR_STUDENTS = ['hanna', 'imre', 'julia', 'karoly', 'lili'];

    /** @var array<string, User> */
    private array $people = [];

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('The Sprint 4 demo data is never seeded in production.');

            return;
        }

        // The university, the fair and their people come from here. If they exist, nothing changes.
        $this->call(Sprint3DemoSeeder::class);

        if (User::where('email', self::PEOPLE['olga'][1])->exists()) {
            $this->command?->info('The Sprint 4 demo data already exists. Nothing changed.');

            return;
        }

        $admin = User::role(UserRole::Admin)->where('status', 'active')->orderBy('id')->firstOrFail();

        foreach (self::PEOPLE as $key => [$name, $email, $type, $neptun]) {
            $this->people[$key] = $this->person($name, $email, $type, $neptun);
        }
        foreach ([Sprint2DemoSeeder::PEOPLE, Sprint3DemoSeeder::PEOPLE] as $cast) {
            foreach ($cast as $key => [, $email]) {
                $this->people[$key] = User::where('email', $email)->firstOrFail();
            }
        }

        $university = Topic::where('title', Sprint2DemoSeeder::ROOT)->whereNull('parent_id')->firstOrFail();
        $databases = $this->area('Databases');
        $web = $this->area(self::WEB);
        $fair = $this->area(Sprint3DemoSeeder::FAIR);
        $student = RoleDefinition::where('name', 'Event student')->whereNull('topic_id')->firstOrFail();
        $areaAdmin = $this->areaAdminRole($admin);
        $assignments = app(AssignmentService::class);

        // N3: the system admin gives the University root its own area admin. From then on the
        // system admin only maintains; Olga runs the tree.
        $assignments->grant($admin, $university, $areaAdmin, $this->people['olga']);

        // N2: Olga gives each course its own area admin.
        $assignments->grant($this->people['olga'], $databases, $areaAdmin, $this->people['rita']);
        $assignments->grant($this->people['olga'], $web, $areaAdmin, $this->people['tamas']);

        // Web Development gets its students: Nóra, who has no role in the fair, and Ottó.
        $assignments->grant($this->people['tamas'], $web, $student, $this->people['nora']);
        $assignments->grant($this->people['tamas'], $web, $student, $this->people['otto']);

        // D4: the booth students belong to the fair too, as when the fair is ticked at the import,
        // so they see the fair's leaderboard. Márton was already assigned to the fair.
        foreach (self::FAIR_STUDENTS as $key) {
            $assignments->grant($this->people['greta'], $fair, $student, $this->people[$key]);
        }

        $this->webEvents($web);
        $this->leaderboards();
        $this->report();
    }

    /**
     * Every right on an area and below it, and every right to pass on: an area admin can give
     * a course its own area admin (N2). The category list only counts on a top area (Task #116).
     */
    private function areaAdminRole(User $admin): RoleDefinition
    {
        return RoleDefinition::where('name', self::AREA_ADMIN)->whereNull('topic_id')->first()
            ?? app(RoleService::class)->create($admin, null, [
                'name' => self::AREA_ADMIN,
                'description' => 'Runs an area and everything below it: its structure, people, roles, events and leaderboards. On the University root also the category list (N3).',
                'capabilities' => Capability::values(),
                'delegable' => Capability::values(),
            ]);
    }

    /**
     * Last week's lecture gave Nóra and Ottó their first points; the workshop is still ahead and
     * stays with the course when Tamás hands it over (N4).
     */
    private function webEvents(Topic $web): void
    {
        $tamas = $this->people['tamas'];

        $lecture = $this->event($tamas, $web, 'Exam Preparation', [
            'title' => self::WEB_LECTURE,
            'description' => 'Took place last week; attendance was credited.',
            'location' => 'Lecture Hall B, Main Building',
            'capacity' => 30,
            'points_value' => 10,
            'status' => Event::STATUS_COMPLETED,
        ], startsIn: '-6 days');

        foreach (['nora', 'otto'] as $key) {
            $this->accept($lecture, $this->people[$key]);
            app(PointLedger::class)->credit($lecture, $this->people[$key], $tamas, PointLedger::PASSPORT);
        }

        $workshop = $this->event($tamas, $web, 'Tutoring', [
            'title' => self::WEB_WORKSHOP,
            'description' => 'Build a small page with Vue. Stays with the course when its area admin hands it over.',
            'location' => 'Computer Lab 2, Informatics Building',
            'capacity' => 12,
            'points_value' => 20,
        ], startsIn: '+4 days');

        app(EventApplicationService::class)->apply($this->people['nora'], $workshop);
    }

    /**
     * A leaderboard on each course, the club and the fair, each made by the person who runs that
     * area (N2), and none on the hall screen until the system admin switches it on (D7).
     */
    private function leaderboards(): void
    {
        $boards = app(AreaLeaderboards::class);

        foreach (self::LEADERBOARDS as $name => [$area, $maker]) {
            $boards->create($this->people[$maker], $this->area($area), [
                'name' => $name,
                'show_neptun_code' => true,
                'show_first_name' => false,
                'show_last_name' => false,
            ]);
        }
    }

    private function area(string $title): Topic
    {
        $university = Topic::where('title', Sprint2DemoSeeder::ROOT)->whereNull('parent_id')->firstOrFail();

        return Topic::query()->below($university)->where('title', $title)->firstOrFail();
    }

    /**
     * A published event, starting at $startsIn (relative to now), two hours long.
     *
     * @param  array<string, mixed>  $data
     */
    private function event(User $organiser, Topic $topic, string $tag, array $data, string $startsIn): Event
    {
        $tag = TopicTag::where('name', $tag)->firstOrFail();
        $starts = now()->modify($startsIn)->startOfMinute();

        return Event::create([
            'description' => '',
            'topic_tag_id' => $tag->id,
            'category' => $tag->category,
            'created_by_id' => $organiser->id,
            'owning_topic_id' => $topic->id,
            'audience_topic_id' => $topic->id,
            'semester_id' => Semester::where('status', 'active')->orderByDesc('starts_at')->value('id'),
            'capacity' => 10,
            'application_type' => 'fcfs',
            'points_value' => 15,
            'event_starts_at' => $starts,
            'event_ends_at' => $starts->copy()->addHours(2),
            'application_deadline' => $starts->copy()->subHour(),
            'completion_deadline' => $starts->copy()->addDays(14),
            'status' => Event::STATUS_PUBLISHED,
            'published_at' => now()->subDays(10),
            'is_hidden' => false,
            'qr_enabled' => true,
            ...$data,
        ]);
    }

    /**
     * An accepted application for an event that has already started, written the way the
     * apply service writes it, history row included (the service refuses once an event starts).
     */
    private function accept(Event $event, User $student): void
    {
        $application = EventApplication::create([
            'event_id' => $event->id,
            'student_id' => $student->id,
            'status' => EventApplication::ACCEPTED,
            'applied_at' => $event->application_deadline->copy()->subDay(),
            'decided_at' => $event->application_deadline->copy()->subDay(),
        ]);

        EventApplicationTransition::create([
            'event_application_id' => $application->id,
            'from_status' => null,
            'to_status' => EventApplication::ACCEPTED,
            'actor_id' => $student->id,
            'reason' => 'Initial application',
            'created_at' => $application->applied_at,
        ]);
    }

    private function person(string $name, string $email, UserRole $type, ?string $neptun): User
    {
        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => self::PASSWORD,
                'status' => 'active',
                'must_change_password' => false,
                'email_verified_at' => now(),
            ],
        );
        $user->forceFill(['neptun_code' => $neptun])->save();
        $user->assignRole($type);

        return $user;
    }

    private function report(): void
    {
        $this->command?->info('Sprint 4 demo data created. New accounts (password "'.self::PASSWORD.'"):');

        foreach (self::PEOPLE as [$name, $email, $type, $neptun]) {
            // mb_str_pad: accented names (Tamás, Zsófia) would push the columns out of line.
            $this->command?->line('  '.str_pad($email, 30).mb_str_pad($name, 16).str_pad($type->value, 9).($neptun ?? ''));
        }

        $this->command?->line('Demo University: Olga is its area admin. Databases: Rita. Web Development: Tamás, who hands it to Zsófia.');
        $this->command?->line('Leaderboards: '.implode(', ', array_keys(self::LEADERBOARDS)).'. Every demo student sees at least one on Home.');
        $this->command?->line('Walkthrough: docs/sprint4-demo.md');
    }
}

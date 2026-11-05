<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Event;
use App\Models\RoleDefinition;
use App\Models\Semester;
use App\Models\Topic;
use App\Models\TopicTag;
use App\Models\User;
use App\Services\AssignmentService;
use App\Services\EventApplicationService;
use App\Services\PointLedger;
use App\Services\RoleService;
use App\Services\TopicService;
use Illuminate\Database\Seeder;

/**
 * DEMO DATA ONLY (Task #90). Not part of DatabaseSeeder: production and fresh installs stay
 * blank. Run it shortly before the demo, because both booth events are running at that moment:
 *
 *     php artisan db:seed --class=Sprint3DemoSeeder
 *
 * A fair with two booths: a sub-area per booth, students assigned to each, and a booth event
 * that gives points only to the students assigned to its booth (26 October, recording 28:25).
 * It adds to the Sprint 2 data, which it creates first if it is missing. The walkthrough is in
 * docs/sprint3-demo.md.
 *
 * Applications and credits go through the real services, so transitions, ledger rows and
 * notifications are what the app itself would write. Running it again changes nothing. It
 * refuses to run in production.
 */
class Sprint3DemoSeeder extends Seeder
{
    public const PASSWORD = Sprint2DemoSeeder::PASSWORD;

    public const FAIR = 'University Fair';

    public const ROBOTICS = 'Robotics booth';

    public const CODING = 'Coding booth';

    public const ROBOTICS_EVENT = 'Robot obstacle course';

    public const CODING_EVENT = 'Code golf challenge';

    /**
     * Demo accounts: key => [name, email, account type, Neptun code].
     *
     * @var array<string, array{0: string, 1: string, 2: UserRole, 3: ?string}>
     */
    public const PEOPLE = [
        'greta' => ['Gréta Fehér', 'sprint3.greta@example.test', UserRole::Teacher, null],
        'hanna' => ['Hanna Lakatos', 'sprint3.hanna@example.test', UserRole::Student, 'HANN01'],
        'imre' => ['Imre Szabó', 'sprint3.imre@example.test', UserRole::Student, 'IMRE02'],
        'julia' => ['Júlia Kocsis', 'sprint3.julia@example.test', UserRole::Student, 'JULI03'],
        'karoly' => ['Károly Pintér', 'sprint3.karoly@example.test', UserRole::Student, 'KARO04'],
        'lili' => ['Lili Papp', 'sprint3.lili@example.test', UserRole::Student, 'LILI05'],
        'marton' => ['Márton Farkas', 'sprint3.marton@example.test', UserRole::Student, 'MART06'],
        'nora' => ['Nóra Balla', 'sprint3.nora@example.test', UserRole::Student, 'NORA07'],
    ];

    /** @var array<string, User> */
    private array $people = [];

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('The Sprint 3 demo data is never seeded in production.');

            return;
        }

        // The university and the "Event student" role come from here. If they exist, nothing changes.
        $this->call(Sprint2DemoSeeder::class);

        if (Topic::where('title', self::FAIR)->exists()) {
            $this->command?->info('The Sprint 3 demo data already exists. Nothing changed.');

            return;
        }

        $admin = User::role(UserRole::Admin)->where('status', 'active')->orderBy('id')->firstOrFail();

        foreach (self::PEOPLE as $key => [$name, $email, $type, $neptun]) {
            $this->people[$key] = $this->person($name, $email, $type, $neptun);
        }

        // A sub-area per booth, under the fair (26 October, recording 28:25).
        $topics = app(TopicService::class);
        $university = Topic::where('title', Sprint2DemoSeeder::ROOT)->whereNull('parent_id')->firstOrFail();
        $fair = $topics->create($admin, $university, ['title' => self::FAIR, 'description' => 'Sprint 3 demo: a sub-area per booth, and points only for the students assigned to that booth.']);
        $robotics = $topics->create($admin, $fair, ['title' => self::ROBOTICS]);
        $coding = $topics->create($admin, $fair, ['title' => self::CODING]);

        $student = RoleDefinition::where('name', 'Event student')->whereNull('topic_id')->firstOrFail();
        $organiser = app(RoleService::class)->create($admin, $fair, [
            'name' => 'Fair organiser',
            'description' => 'Runs a fair: adds a sub-area per booth, imports its students and runs the booth events.',
            'capabilities' => ['topic.view', 'topic.create', 'access.view', 'access.assign', 'access.end', 'access.import', 'events.view', 'events.apply', 'events.create', 'events.manage', 'applications.manage', 'points.credit', 'qr_codes.manage'],
            // Enough to give "Event student" to the students of a booth, by hand or by import.
            'delegable' => ['topic.view', 'events.view', 'events.apply'],
        ]);

        $assignments = app(AssignmentService::class);
        $assignments->grant($admin, $fair, $organiser, $this->people['greta']);
        foreach (['hanna', 'imre', 'lili'] as $key) {
            $assignments->grant($admin, $robotics, $student, $this->people[$key]);
        }
        foreach (['julia', 'karoly', 'lili'] as $key) {
            $assignments->grant($admin, $coding, $student, $this->people[$key]);
        }
        // In the fair itself but at no booth: nothing flows down to the booths on its own. Nóra has no role here at all.
        $assignments->grant($admin, $fair, $student, $this->people['marton']);

        $this->boothEvents($robotics, $coding);
        $this->report();
    }

    private function boothEvents(Topic $robotics, Topic $coding): void
    {
        $greta = $this->people['greta'];
        $applications = app(EventApplicationService::class);
        $ledger = app(PointLedger::class);

        // Imre already has the points; Lili has a place; Hanna applies and scans during the demo.
        $course = $this->event($greta, $robotics, 'Conference Talk', [
            'title' => self::ROBOTICS_EVENT,
            'description' => 'Running right now at the Robotics booth. Only students assigned to this booth see it and get its points.',
            'location' => 'Main hall, table 4',
            'points_value' => 15,
        ]);
        $applications->apply($this->people['imre'], $course);
        $applications->apply($this->people['lili'], $course);
        $ledger->credit($course, $this->people['imre'], $greta, PointLedger::PASSPORT);

        // Károly already has the points; Júlia and Lili have a place.
        $golf = $this->event($greta, $coding, 'Tutoring', [
            'title' => self::CODING_EVENT,
            'description' => 'Running right now at the Coding booth. Only students assigned to this booth see it and get its points.',
            'location' => 'Main hall, table 7',
            'points_value' => 10,
        ]);
        foreach (['karoly', 'julia', 'lili'] as $key) {
            $applications->apply($this->people[$key], $golf);
        }
        $ledger->credit($golf, $this->people['karoly'], $greta, PointLedger::PASSPORT);
    }

    /**
     * A booth event that started half an hour ago and is announced to its booth alone.
     * Applications stay open while it runs (Task #78), so a student can still join at the booth.
     *
     * @param  array<string, mixed>  $data
     */
    private function event(User $organiser, Topic $booth, string $tag, array $data): Event
    {
        $tag = TopicTag::where('name', $tag)->firstOrFail();
        $starts = now()->subMinutes(30)->startOfMinute();

        return Event::create([
            'topic_tag_id' => $tag->id,
            'category' => $tag->category,
            'created_by_id' => $organiser->id,
            'owning_topic_id' => $booth->id,
            'audience_topic_id' => $booth->id,
            'semester_id' => Semester::where('status', 'active')->orderByDesc('starts_at')->value('id'),
            'capacity' => 20,
            'application_type' => 'fcfs',
            'event_starts_at' => $starts,
            'event_ends_at' => $starts->copy()->addHours(4),
            'application_deadline' => $starts->copy()->addHours(4),
            'completion_deadline' => $starts->copy()->addDays(14),
            'status' => Event::STATUS_PUBLISHED,
            'published_at' => now()->subDays(3),
            'is_hidden' => false,
            'qr_enabled' => true,
            ...$data,
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
        $this->command?->info('Sprint 3 demo data created. Accounts (password "'.self::PASSWORD.'"):');

        foreach (self::PEOPLE as [$name, $email, $type, $neptun]) {
            // mb_str_pad: accented names (Gréta, Júlia) would push the columns out of line.
            $this->command?->line('  '.str_pad($email, 30).mb_str_pad($name, 16).str_pad($type->value, 9).($neptun ?? ''));
        }

        $this->command?->line('University Fair: Gréta organises; Márton belongs to the fair but to no booth; Nóra has no role here.');
        $this->command?->line('Robotics booth: Hanna, Imre, Lili. Coding booth: Júlia, Károly, Lili.');
        $this->command?->line('Walkthrough: docs/sprint3-demo.md');
    }
}

<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Event;
use App\Models\EventApplication;
use App\Models\EventApplicationTransition;
use App\Models\QrToken;
use App\Models\Semester;
use App\Models\Topic;
use App\Models\TopicTag;
use App\Models\User;
use App\Services\AssignmentService;
use App\Services\EventApplicationService;
use App\Services\PointLedger;
use App\Services\QrTokenService;
use App\Services\RoleService;
use App\Services\TopicService;
use Illuminate\Database\Seeder;

/**
 * DEMO DATA ONLY (Task #67). Not part of DatabaseSeeder: production and fresh
 * installs stay blank. Run it shortly before the Sprint 2 demo, because the
 * event times are relative to now (one event is running at that moment):
 *
 *     php artisan db:seed --class=Sprint2DemoSeeder
 *
 * Two branches of one university, each with its own organiser and students,
 * and events in every state, so the demo can show: refused cross-branch
 * actions, no overbooking, no double credit and waitlist promotion, with both
 * manual and QR crediting. The walkthrough is in docs/sprint2-demo.md.
 *
 * Applications and credits go through the real services, so transitions,
 * ledger rows and notifications are what the app itself would write. Running
 * it again changes nothing. It refuses to run in production.
 */
class Sprint2DemoSeeder extends Seeder
{
    public const PASSWORD = 'password';

    public const ROOT = 'Demo University';

    /**
     * Demo accounts: key => [name, email, account type, Neptun code].
     *
     * @var array<string, array{0: string, 1: string, 2: UserRole, 3: ?string}>
     */
    public const PEOPLE = [
        'ivan' => ['Ivan Kovács', 'sprint2.ivan@example.test', UserRole::Teacher, null],
        'sara' => ['Sara Szabó', 'sprint2.sara@example.test', UserRole::Teacher, null],
        'anna' => ['Anna Nagy', 'sprint2.anna@example.test', UserRole::Student, 'ANNA01'],
        'bence' => ['Bence Tóth', 'sprint2.bence@example.test', UserRole::Student, 'BENC02'],
        'csilla' => ['Csilla Varga', 'sprint2.csilla@example.test', UserRole::Student, 'CSIL03'],
        'dani' => ['Dániel Horváth', 'sprint2.dani@example.test', UserRole::Student, 'DANI04'],
        'eszter' => ['Eszter Kiss', 'sprint2.eszter@example.test', UserRole::Student, 'ESZT05'],
        'erik' => ['Erik Molnár', 'sprint2.erik@example.test', UserRole::Student, 'ERIK06'],
        'fanni' => ['Fanni Balogh', 'sprint2.fanni@example.test', UserRole::Student, 'FANN07'],
    ];

    /** @var array<string, User> */
    private array $people = [];

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('The Sprint 2 demo data is never seeded in production.');

            return;
        }

        $this->call([RolePermissionSeeder::class, SemesterSeeder::class, TopicTagSeeder::class]);

        $admin = User::role(UserRole::Admin)->where('status', 'active')->orderBy('id')->first();

        if ($admin === null) {
            $this->call(AdminUserSeeder::class);
            $admin = User::role(UserRole::Admin)->orderBy('id')->firstOrFail();
        }

        if (Topic::where('title', self::ROOT)->whereNull('parent_id')->exists()) {
            $this->command?->info('The Sprint 2 demo data already exists. Nothing changed.');

            return;
        }

        foreach (self::PEOPLE as $key => [$name, $email, $type, $neptun]) {
            $this->people[$key] = $this->person($name, $email, $type, $neptun);
        }

        // Two branches of one university. Nothing flows between them.
        $topics = app(TopicService::class);
        $university = $topics->create($admin, null, ['title' => self::ROOT, 'description' => 'Sprint 2 demo: two branches with their own organisers and students.']);
        $informatics = $topics->create($admin, $university, ['title' => 'Faculty of Informatics']);
        $databases = $topics->create($admin, $informatics, ['title' => 'Databases', 'code' => 'DEMO-DB101', 'description' => 'Relational databases and SQL.']);
        $topics->create($admin, $informatics, ['title' => 'Web Development', 'code' => 'DEMO-WEB201']);
        $sport = $topics->create($admin, $university, ['title' => 'Faculty of Sport']);
        $athletics = $topics->create($admin, $sport, ['title' => 'Athletics Club']);

        $roles = app(RoleService::class);
        $organiser = $roles->create($admin, null, [
            'name' => 'Event organiser',
            'description' => 'Runs the events of a branch: creates and publishes them, selects applicants, credits points and prints QR codes.',
            'capabilities' => ['topic.view', 'access.view', 'events.view', 'events.create', 'events.manage', 'applications.manage', 'points.credit', 'qr_codes.manage'],
        ]);
        $student = $roles->create($admin, null, [
            'name' => 'Event student',
            'description' => 'Sees the events announced to their topic and applies to them.',
            'capabilities' => ['topic.view', 'events.view', 'events.apply'],
        ]);

        $assignments = app(AssignmentService::class);
        $assignments->grant($admin, $informatics, $organiser, $this->people['ivan']);
        $assignments->grant($admin, $sport, $organiser, $this->people['sara']);
        foreach (['anna', 'bence', 'csilla', 'dani', 'eszter'] as $key) {
            $assignments->grant($admin, $databases, $student, $this->people[$key]);
        }
        foreach (['erik', 'fanni'] as $key) {
            $assignments->grant($admin, $athletics, $student, $this->people[$key]);
        }

        $this->informaticsEvents($databases);
        $this->sportEvents($athletics);

        $this->report();
    }

    private function informaticsEvents(Topic $databases): void
    {
        $ivan = $this->people['ivan'];
        $applications = app(EventApplicationService::class);
        $ledger = app(PointLedger::class);

        // Waitlist promotion and no overbooking: 2 places, 2 taken, Csilla waits.
        $joins = $this->event($ivan, $databases, 'Tutoring', [
            'title' => 'SQL Joins Workshop',
            'description' => 'Two places only. Demo: Eszter applies and is waitlisted; Anna withdraws and Csilla moves up.',
            'location' => 'Room 2.14, Informatics Building',
            'capacity' => 2,
        ], startsIn: '+3 days');
        $applications->apply($this->people['anna'], $joins);
        $applications->apply($this->people['bence'], $joins);
        $applications->apply($this->people['csilla'], $joins);

        // Selection mode: four candidates for two places; the organiser decides.
        $hackathon = $this->event($ivan, $databases, 'Conference Talk', [
            'title' => 'Database Design Hackathon',
            'description' => 'Selection mode. Demo: tick three for two places to see the capacity question, then select two and reject one.',
            'location' => 'Computer Lab 3, Informatics Building',
            'capacity' => 2,
            'application_type' => 'teacher_select',
            'points_value' => 30,
        ], startsIn: '+5 days');
        foreach (['anna', 'bence', 'dani', 'eszter'] as $key) {
            $applications->apply($this->people[$key], $hackathon);
        }

        // Running now: credit by passport and poster; a second credit is refused.
        $lab = $this->event($ivan, $databases, 'Tutoring', [
            'title' => 'Indexing Lab',
            'description' => 'Running right now. Demo: Bence is already credited; credit Anna by her QR passport, and let Dani scan the poster.',
            'location' => 'Computer Lab 1, Informatics Building',
            'capacity' => 10,
            'points_value' => 20,
        ], startsIn: '-30 minutes');
        foreach (['anna', 'bence', 'csilla', 'dani'] as $key) {
            $this->accept($lab, $this->people[$key]);
        }
        $ledger->credit($lab, $this->people['bence'], $ivan, PointLedger::PASSPORT);
        app(QrTokenService::class)->issue($ivan, $lab, QrToken::POSTER);

        // Last week: points already earned, so the totals and tooltips are not empty.
        $lecture = $this->event($ivan, $databases, 'Exam Preparation', [
            'title' => 'Intro to SQL Lecture',
            'description' => 'Took place last week; attendance was credited.',
            'location' => 'Lecture Hall A, Main Building',
            'capacity' => 40,
            'points_value' => 10,
            'status' => Event::STATUS_COMPLETED,
        ], startsIn: '-7 days');
        foreach (['anna', 'bence', 'csilla', 'eszter'] as $key) {
            $this->accept($lecture, $this->people[$key]);
            $ledger->credit($lecture, $this->people[$key], $ivan, PointLedger::PASSPORT);
        }

        // Every other state of the lifecycle, to show on "Organized by me".
        // The draft has no location yet: the field is optional (Task #77).
        $this->event($ivan, $databases, 'Conference Talk', [
            'title' => 'Query Optimisation Seminar',
            'description' => 'Still a draft: students cannot see it.',
            'status' => Event::STATUS_DRAFT,
            'published_at' => null,
        ], startsIn: '+10 days');

        $career = $this->event($ivan, $databases, 'Volunteer Work', [
            'title' => 'Database Careers Evening',
            'description' => 'On hold: hidden from students, applications kept. Demo: restore it, and Anna is told.',
            'location' => 'Aula, Main Building',
        ], startsIn: '+8 days');
        $applications->apply($this->people['anna'], $career);
        $career->update(['status' => Event::STATUS_ON_HOLD]);

        $this->event($ivan, $databases, 'Tutoring', [
            'title' => 'Lab Safety Briefing',
            'description' => 'Cancelled, with a reason. It cannot be brought back.',
            'location' => 'Computer Lab 1, Informatics Building',
            'status' => Event::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancellation_reason' => 'The lab is being renovated.',
        ], startsIn: '+4 days');

        $this->event($ivan, $databases, 'Exam Preparation', [
            'title' => 'Surprise SQL Quiz',
            'description' => 'A hidden surprise event: staff see it now, students only once it is revealed.',
            'is_hidden' => true,
            'reveal_at' => now()->addDay(),
        ], startsIn: '+2 days');
    }

    private function sportEvents(Topic $athletics): void
    {
        $sara = $this->people['sara'];

        // Running now in the other branch. Demo: Ivan cannot open it, Anna cannot see it.
        $run = $this->event($sara, $athletics, 'Charity Run', [
            'title' => 'Campus 5K Run',
            'description' => 'Faculty of Sport only. Running right now; Erik is already credited.',
            'location' => 'University Park, main gate',
            'capacity' => 50,
            'points_value' => 25,
        ], startsIn: '-20 minutes');
        $this->accept($run, $this->people['erik']);
        $this->accept($run, $this->people['fanni']);
        app(PointLedger::class)->credit($run, $this->people['erik'], $sara, PointLedger::PASSPORT);

        $championship = $this->event($sara, $athletics, 'University Championship', [
            'title' => 'Athletics Championship',
            'description' => 'Open to the Athletics Club only.',
            'location' => 'University Stadium',
            'capacity' => 20,
            'points_value' => 40,
        ], startsIn: '+6 days');
        app(EventApplicationService::class)->apply($this->people['fanni'], $championship);
    }

    /**
     * An event published by the organiser, starting at $startsIn (relative to now), two hours long.
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
     * An accepted application for an event that has already started, so the
     * apply service (which refuses once an event starts) cannot be used.
     * Written the same way the service writes it, history row included.
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
        $this->command?->info('Sprint 2 demo data created. Accounts (password "'.self::PASSWORD.'"):');

        foreach (self::PEOPLE as [$name, $email, $type, $neptun]) {
            // mb_str_pad: accented names (Dániel, Tóth) would push the columns out of line.
            $this->command?->line('  '.str_pad($email, 29).mb_str_pad($name, 17).str_pad($type->value, 9).($neptun ?? ''));
        }

        $this->command?->line('Faculty of Informatics: Ivan organises; Anna, Bence, Csilla, Dániel and Eszter are students.');
        $this->command?->line('Faculty of Sport: Sara organises; Erik and Fanni are students.');
        $this->command?->line('Walkthrough: docs/sprint2-demo.md');
    }
}

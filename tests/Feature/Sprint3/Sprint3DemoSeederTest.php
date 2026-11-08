<?php

namespace Tests\Feature\Sprint3;

use App\Models\Event;
use App\Models\EventApplication;
use App\Models\Leaderboard;
use App\Models\PointTransaction;
use App\Models\RoleAssignment;
use App\Models\Topic;
use App\Models\User;
use App\Services\AssignmentService;
use App\Services\EventCatalog;
use App\Services\LiveAttendanceQr;
use App\Services\PointLedger;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\Sprint2DemoSeeder;
use Database\Seeders\Sprint3DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Task #90: the demo data has a fair with two booths, and every step of the walkthrough in
 * docs/sprint3-demo.md works on it.
 */
class Sprint3DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    private function seedDemo(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(Sprint3DemoSeeder::class);
    }

    private function person(string $key): User
    {
        return User::where('email', Sprint3DemoSeeder::PEOPLE[$key][1])->firstOrFail();
    }

    private function event(string $title): Event
    {
        return Event::where('title', $title)->firstOrFail();
    }

    private function topic(string $title): Topic
    {
        return Topic::where('title', $title)->firstOrFail();
    }

    /**
     * @return list<string> the titles of the fair's events the student's catalog lists
     */
    private function catalogOf(string $key): array
    {
        return app(EventCatalog::class)->query($this->person($key))
            ->whereIn('title', [Sprint3DemoSeeder::ROBOTICS_EVENT, Sprint3DemoSeeder::CODING_EVENT])
            ->orderBy('title')
            ->pluck('title')
            ->all();
    }

    /**
     * @return array<string, int> Neptun code => points
     */
    private function credits(string $title): array
    {
        return PointTransaction::query()
            ->where('event_id', $this->event($title)->id)
            ->join('users', 'users.id', '=', 'point_transactions.student_id')
            ->orderBy('users.neptun_code')
            ->pluck('point_transactions.points', 'users.neptun_code')
            ->all();
    }

    private function liveCode(Event $event): string
    {
        $live = app(LiveAttendanceQr::class);

        return $live->payload($live->open($this->person('greta'), $event));
    }

    public function test_the_normal_seeder_creates_no_fair(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(0, Topic::where('title', Sprint3DemoSeeder::FAIR)->count());
        $this->assertSame(0, User::where('email', 'like', 'sprint3.%')->count());
    }

    public function test_it_builds_a_fair_with_two_booths_and_is_repeatable(): void
    {
        $this->seedDemo();

        // Under the Sprint 2 university, which it creates when it is missing.
        $fair = $this->topic(Sprint3DemoSeeder::FAIR);
        $this->assertSame(Sprint2DemoSeeder::ROOT, $fair->parent->title);
        $this->assertSame(
            [Sprint3DemoSeeder::CODING, Sprint3DemoSeeder::ROBOTICS],
            $fair->children()->orderBy('title')->pluck('title')->all(),
        );

        $membersOf = fn (string $topic) => RoleAssignment::query()->active()
            ->where('topic_id', $this->topic($topic)->id)
            ->whereHas('role', fn ($role) => $role->where('name', 'Event student'))
            ->join('users', 'users.id', '=', 'role_assignments.user_id')
            ->orderBy('users.neptun_code')
            ->pluck('users.neptun_code')
            ->all();

        $this->assertSame(['HANN01', 'IMRE02', 'LILI05'], $membersOf(Sprint3DemoSeeder::ROBOTICS));
        $this->assertSame(['JULI03', 'KARO04', 'LILI05'], $membersOf(Sprint3DemoSeeder::CODING));
        $this->assertSame(['MART06'], $membersOf(Sprint3DemoSeeder::FAIR));
        $this->assertSame(0, RoleAssignment::query()->where('user_id', $this->person('nora')->id)->count());

        // Each booth has an event announced to that booth alone, running right now.
        foreach ([Sprint3DemoSeeder::ROBOTICS_EVENT => Sprint3DemoSeeder::ROBOTICS, Sprint3DemoSeeder::CODING_EVENT => Sprint3DemoSeeder::CODING] as $title => $booth) {
            $event = $this->event($title);
            $this->assertSame($this->topic($booth)->id, $event->owning_topic_id);
            $this->assertSame($this->topic($booth)->id, $event->audience_topic_id);
            $this->assertTrue($event->event_starts_at->isPast() && $event->event_ends_at->isFuture());
            $this->assertNull(app(PointLedger::class)->refusalFor($event));
        }

        $this->assertSame(['IMRE02' => 15], $this->credits(Sprint3DemoSeeder::ROBOTICS_EVENT));
        $this->assertSame(['KARO04' => 10], $this->credits(Sprint3DemoSeeder::CODING_EVENT));

        // Running it again changes nothing.
        $topics = Topic::count();
        $events = Event::count();
        $people = User::count();
        $roles = RoleAssignment::count();
        $this->seed(Sprint3DemoSeeder::class);
        $this->assertSame([$topics, $events, $people, $roles], [Topic::count(), Event::count(), User::count(), RoleAssignment::count()]);
    }

    public function test_it_adds_to_sprint_2_data_that_is_already_there(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(Sprint2DemoSeeder::class);
        $topics = Topic::count();
        $events = Event::count();

        $this->seed(Sprint3DemoSeeder::class);

        $this->assertSame(1, Topic::where('title', Sprint2DemoSeeder::ROOT)->count());
        $this->assertSame($topics + 3, Topic::count());
        $this->assertSame($events + 2, Event::count());
    }

    public function test_it_refuses_to_run_in_production(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->app->detectEnvironment(fn () => 'production');

        (new Sprint3DemoSeeder)->setContainer($this->app)->run();

        $this->assertSame(0, Event::count());
        $this->assertSame(0, Topic::count());
    }

    public function test_every_step_of_the_walkthrough_works_on_the_demo_data(): void
    {
        $this->seedDemo();
        $course = $this->event(Sprint3DemoSeeder::ROBOTICS_EVENT);
        $golf = $this->event(Sprint3DemoSeeder::CODING_EVENT);
        $greta = $this->person('greta');

        // Step 1: Gréta can add a booth and import its students.
        $fair = $this->topic(Sprint3DemoSeeder::FAIR);
        $this->actingAs($greta)->get('/topics')->assertRedirect("/topics/{$fair->id}");
        $this->actingAs($greta)->post("/topics/{$fair->id}/children", ['title' => 'Chemistry booth'])->assertSessionHas('success');
        $chemistry = $this->topic('Chemistry booth');
        $this->actingAs($greta)->post("/topics/{$chemistry->id}/imports", [
            'file' => UploadedFile::fake()->createWithContent('booth.csv', "Neptun Code\nNORA07\n"),
            'role_definition_id' => RoleAssignment::where('user_id', $this->person('hanna')->id)->value('role_definition_id'),
        ])->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame(1, RoleAssignment::query()->active()->where(['topic_id' => $chemistry->id, 'user_id' => $this->person('nora')->id])->count());

        // Step 2: everybody sees only the events of their own booths.
        $this->assertSame([Sprint3DemoSeeder::ROBOTICS_EVENT], $this->catalogOf('hanna'));
        $this->assertSame([Sprint3DemoSeeder::CODING_EVENT], $this->catalogOf('julia'));
        $this->assertSame([Sprint3DemoSeeder::CODING_EVENT, Sprint3DemoSeeder::ROBOTICS_EVENT], $this->catalogOf('lili'));
        $this->assertSame([], $this->catalogOf('marton'));
        $this->assertSame([], $this->catalogOf('nora'));
        $this->actingAs($this->person('hanna'))->get("/events/{$golf->id}")->assertNotFound();

        // Step 3: Hanna applies and checks in with the live code.
        $this->actingAs($this->person('hanna'))->post("/events/{$course->id}/apply")->assertSessionHasNoErrors();
        $this->assertTrue(EventApplication::where(['event_id' => $course->id, 'student_id' => $this->person('hanna')->id])->firstOrFail()->isAccepted());
        $code = $this->liveCode($course);
        $this->actingAs($this->person('hanna'))->postJson('/qr/redeem', ['code' => $code])
            ->assertOk()->assertJsonPath('outcome', 'credited')->assertJsonPath('points', 15);

        // Step 4: the same code does nothing for anyone outside the booth.
        foreach (['julia', 'marton', 'nora'] as $key) {
            $this->actingAs($this->person($key))->postJson('/qr/redeem', ['code' => $code])
                ->assertForbidden()->assertJsonPath('message', 'You are not registered or selected for this event.');
        }
        $this->assertSame(['HANN01' => 15, 'IMRE02' => 15], $this->credits(Sprint3DemoSeeder::ROBOTICS_EVENT));

        // Step 5: an imported list credits only the booth's students.
        $file = UploadedFile::fake()->createWithContent('list.csv', "Neptun Code\nJULI03\nKARO04\nHANN01\nMART06\nNORA07\n");
        $this->actingAs($greta)->post("/events/{$golf->id}/points-import", ['file' => $file], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('credited.0.neptun_code', 'JULI03')
            ->assertJsonCount(1, 'credited')
            ->assertJsonPath('already.0.neptun_code', 'KARO04')
            ->assertJsonCount(3, 'skipped')
            ->assertJsonPath('skipped.0.reason', 'Not assigned to Coding booth, the area of this event.');
        $this->assertSame(['JULI03' => 10, 'KARO04' => 10], $this->credits(Sprint3DemoSeeder::CODING_EVENT));

        // Step 6: Lili is taken off the Coding booth, and the place there no longer earns.
        $lili = $this->person('lili');
        $assignment = RoleAssignment::query()->active()->where(['topic_id' => $this->topic(Sprint3DemoSeeder::CODING)->id, 'user_id' => $lili->id])->firstOrFail();
        $confirmation = app(AssignmentService::class)->prepare($greta, $assignment, null, 'Left the booth');
        $this->assertArrayHasKey('token', $confirmation);
        app(AssignmentService::class)->execute($greta, $confirmation['token']);
        $this->actingAs($lili)->postJson('/qr/redeem', ['code' => $this->liveCode($golf)])
            ->assertForbidden()->assertJsonPath('reason', 'left_area');

        // The other booth still works.
        $this->actingAs($lili)->postJson('/qr/redeem', ['code' => $code])->assertOk();
    }

    public function test_the_fair_organiser_can_make_a_leaderboard_for_a_booth(): void
    {
        $this->seedDemo();
        $booth = $this->topic(Sprint3DemoSeeder::ROBOTICS);

        $this->actingAs($this->person('greta'))
            ->post("/topics/{$booth->id}/leaderboards", ['name' => 'Robotics ranking'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $board = Leaderboard::where('name', 'Robotics ranking')->firstOrFail();

        $this->actingAs($this->person('hanna'))->get("/leaderboards/{$board->id}")->assertOk();
        $this->actingAs($this->person('julia'))->get("/leaderboards/{$board->id}")->assertNotFound();
    }

    public function test_every_demo_account_can_sign_in_and_open_its_pages(): void
    {
        $this->seedDemo();
        $course = $this->event(Sprint3DemoSeeder::ROBOTICS_EVENT);

        $this->post('/login', ['email' => Sprint3DemoSeeder::PEOPLE['greta'][1], 'password' => Sprint3DemoSeeder::PASSWORD])->assertRedirect();
        $this->assertAuthenticatedAs($this->person('greta'));
        $this->post('/logout');

        foreach (['/', '/events', '/my-events', '/events/create', "/events/{$course->id}/points-import", "/events/{$course->id}/scan", "/events/{$course->id}/checkin/screen"] as $page) {
            $this->actingAs($this->person('greta'))->get($page)->assertOk();
        }

        foreach (array_keys(Sprint3DemoSeeder::PEOPLE) as $key) {
            if ($key === 'greta') {
                continue;
            }

            foreach (['/', '/events', '/my-events', '/passport', '/scan'] as $page) {
                $this->actingAs($this->person($key))->get($page)->assertOk();
            }
        }
    }
}

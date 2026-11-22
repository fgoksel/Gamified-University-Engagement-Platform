<?php

namespace Tests\Feature\Sprint4;

use App\Enums\UserRole;
use App\Filament\Pages\HallScreen;
use App\Models\Event;
use App\Models\EventApplication;
use App\Models\Leaderboard;
use App\Models\PointTransaction;
use App\Models\RoleAssignment;
use App\Models\Topic;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\Sprint2DemoSeeder;
use Database\Seeders\Sprint3DemoSeeder;
use Database\Seeders\Sprint4DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Task #129: the Sprint 4 integration run, on freshly seeded presentation data, through the same
 * routes the pages use. Each test is a part of docs/sprint4-demo.md, played by the person who
 * does it in the presentation: guest, student, organiser, course and root area admin, and the
 * system admin.
 */
class Sprint4JourneyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->seed(Sprint4DemoSeeder::class);
    }

    private function person(string $key): User
    {
        $email = (Sprint4DemoSeeder::PEOPLE + Sprint3DemoSeeder::PEOPLE + Sprint2DemoSeeder::PEOPLE)[$key][1];

        return User::where('email', $email)->firstOrFail();
    }

    private function admin(): User
    {
        $admin = User::role(UserRole::Admin)->orderBy('id')->firstOrFail();

        // The seeded password is temporary; by the presentation the system admin has chosen their own.
        $admin->forceFill(['must_change_password' => false])->save();

        return $admin;
    }

    private function topic(string $title): Topic
    {
        return Topic::where('title', $title)->firstOrFail();
    }

    private function event(string $title): Event
    {
        return Event::where('title', $title)->firstOrFail();
    }

    /**
     * The student's box for a leaderboard on Home.
     *
     * @return array<string, mixed>
     */
    private function boxOnHome(string $key, string $board): array
    {
        $box = null;

        $this->actingAs($this->person($key))->get('/')->assertOk()
            ->assertInertia(function (Assert $page) use ($board, &$box) {
                $page->component('Home');
                $box = collect($page->toArray()['props']['leaderboards'])->firstWhere('name', $board);
            });

        $this->assertNotNull($box, "{$key} does not see {$board}");

        return $box;
    }

    /**
     * @return list<string> the names of the boards on the hall screen, as a guest gets them
     */
    private function hallBoards(): array
    {
        return array_column($this->getJson('/hall/leaderboards')->assertOk()->json('leaderboards'), 'name');
    }

    public function test_each_person_starts_where_the_presentation_expects(): void
    {
        // Guest: the hall screen needs no login; everything else does.
        $this->get('/hall')->assertOk();
        $this->get('/')->assertRedirect('/login');

        // System admin: maintenance happens in the admin panel.
        $this->actingAs($this->admin())->get('/')->assertRedirect('/admin');
        $this->actingAs($this->admin())->get('/admin')->assertOk();

        // Organiser and area admins: the leaderboards of their areas.
        foreach (['ivan', 'olga', 'rita', 'tamas'] as $key) {
            $this->actingAs($this->person($key))->get('/')->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component('Leaderboard/Index'));
        }

        // Students: Home, with their points and a leaderboard.
        $this->assertSame(1, $this->boxOnHome('bence', 'Databases course')['you']['rank']);
        $this->assertNull($this->boxOnHome('dani', 'Databases course')['you']);
    }

    public function test_the_root_area_admin_makes_a_university_wide_leaderboard_for_the_hall_screen(): void
    {
        $root = $this->topic(Sprint2DemoSeeder::ROOT);
        $olga = $this->person('olga');

        // Olga keeps the category list of the whole university (N3, Task #116).
        $this->actingAs($olga)->get('/categories')->assertOk();
        // A course admin is sent back home with a message, like anyone a page refuses.
        $this->actingAs($this->person('rita'))->get('/categories')->assertRedirect('/')->assertSessionHas('error');

        // Olga makes the university-wide leaderboard (N3). A course admin cannot, at the root.
        $this->actingAs($this->person('rita'))->post("/topics/{$root->id}/leaderboards", ['name' => 'Whole university'])
            ->assertStatus(404);
        $this->actingAs($olga)->post("/topics/{$root->id}/leaderboards", ['name' => 'Whole university'])
            ->assertSessionHas('success');
        $board = Leaderboard::where('topic_id', $root->id)->where('name', 'Whole university')->sole();
        $this->assertSame($olga->id, $board->created_by_id);

        // Not on the hall screen until the system admin switches it on (D7).
        $this->assertNotContains('Whole university', $this->hallBoards());
        Livewire::actingAs($this->admin())->test(HallScreen::class)
            ->call('updateTableColumnState', 'on_hall_screen', (string) $board->id, true);
        $this->assertContains('Whole university', $this->hallBoards());

        // A guest sees Neptun codes and no names (N13).
        $feed = collect($this->getJson('/hall/leaderboards')->json('leaderboards'))->firstWhere('name', 'Whole university');
        $this->assertSame('BENC02', $feed['rows'][0]['neptun_code']);
        $this->assertStringNotContainsString('Bence', json_encode($feed));
        $this->assertStringNotContainsString('example.test', json_encode($feed));

        // A student is not assigned to the root, so the board is not theirs to open (D4, 19 November).
        $this->actingAs($this->person('anna'))->get("/leaderboards?board={$board->id}")->assertNotFound();
        $this->actingAs($this->person('anna'))->get('/leaderboards?show=all')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('leaderboards', fn ($boxes) => collect($boxes)->pluck('name')->all() === ['Databases course']));
    }

    public function test_a_student_checks_in_with_the_live_code_and_moves_up_the_course_leaderboard(): void
    {
        $lab = $this->event('Indexing Lab');
        $this->assertTrue($lab->event_starts_at->isPast() && $lab->event_ends_at->isFuture(), 'The Indexing Lab runs during the presentation.');

        // Before: Bence leads with 30, Anna is second with 10.
        $this->assertSame(2, $this->boxOnHome('anna', 'Databases course')['you']['rank']);

        // Rita runs the Databases course, so she can show the attendance code of its events (N2).
        $code = $this->actingAs($this->person('rita'))->postJson("/events/{$lab->id}/checkin/open")
            ->assertOk()->assertJsonPath('open', true)->json('code');

        // Anna scans it with her phone.
        $this->actingAs($this->person('anna'))->postJson('/qr/redeem', ['code' => $code])
            ->assertOk()->assertJsonPath('outcome', 'credited')->assertJsonPath('points', 20);

        // A second scan, or someone from another area, gets nothing.
        $this->actingAs($this->person('anna'))->postJson('/qr/redeem', ['code' => $code])->assertStatus(409);
        $this->actingAs($this->person('erik'))->postJson('/qr/redeem', ['code' => $code])->assertForbidden();

        // After: Anna shares first place with Bence, both on 30.
        $this->assertSame(1, $this->boxOnHome('anna', 'Databases course')['you']['rank']);
        $this->assertSame(1, $this->boxOnHome('bence', 'Databases course')['you']['rank']);
        $this->assertSame(1, PointTransaction::where('event_id', $lab->id)->where('student_id', $this->person('anna')->id)->count());
    }

    public function test_an_organiser_opens_check_in_at_a_running_fair_booth(): void
    {
        $course = $this->event(Sprint3DemoSeeder::ROBOTICS_EVENT);
        $hanna = $this->person('hanna');

        // Hanna applies at the booth while it runs, and scans the code Gréta shows.
        $this->actingAs($hanna)->postJson("/events/{$course->id}/apply")->assertCreated();
        $code = $this->actingAs($this->person('greta'))->postJson("/events/{$course->id}/checkin/open")->assertOk()->json('code');
        $this->actingAs($hanna)->postJson('/qr/redeem', ['code' => $code])->assertOk()->assertJsonPath('outcome', 'credited');

        // The fair's leaderboard counts every booth: Imre and Hanna share first place with 15.
        $this->assertSame(1, $this->boxOnHome('hanna', 'Fair ranking')['you']['rank']);
        $this->assertSame(1, $this->boxOnHome('imre', 'Fair ranking')['you']['rank']);
        $this->assertSame(3, $this->boxOnHome('karoly', 'Fair ranking')['you']['rank']);
    }

    public function test_the_system_admin_hands_a_course_over_and_everything_stays_with_it(): void
    {
        $web = $this->topic(Sprint4DemoSeeder::WEB);
        $tamas = $this->person('tamas');
        $zsofia = $this->person('zsofia');
        $workshop = $this->event(Sprint4DemoSeeder::WEB_WORKSHOP);
        $assignment = RoleAssignment::query()->active()->where('topic_id', $web->id)->where('user_id', $tamas->id)->sole();

        // Ottó applies to the workshop while Tamás still runs the course.
        $this->actingAs($this->person('otto'))->postJson("/events/{$workshop->id}/apply")->assertCreated();

        // Tamás leaves. The system admin opens the course's Access tab (Admin panel → Areas) and hands
        // the course to Zsófia in two steps (N4, UC-5.3).
        $this->actingAs($this->admin())->get("/topics/{$web->id}?tab=access")->assertOk();
        $token = $this->actingAs($this->admin())
            ->postJson("/topics/assignments/{$assignment->id}/prepare", ['reason' => 'Tamás leaves the university.', 'replacement_id' => $zsofia->id])
            ->assertOk()->json('token');
        $this->actingAs($this->admin())->post('/topics/assignments/execute', ['token' => $token])
            ->assertRedirect(route('topics.show', ['topic' => $web, 'tab' => 'access']))
            ->assertSessionHas('success');

        $new = RoleAssignment::query()->active()->where('topic_id', $web->id)->where('user_id', $zsofia->id)->sole();
        $this->assertSame(Sprint4DemoSeeder::AREA_ADMIN, $new->role->name);
        $this->assertSame($assignment->id, $new->replaces_assignment_id);
        $this->assertFalse($assignment->fresh()->isActive());

        // Tamás has no say any more; Zsófia runs the course, its event and its leaderboard.
        $this->actingAs($tamas)->post("/topics/{$web->id}/leaderboards", ['name' => 'Late'])->assertNotFound();
        $this->actingAs($zsofia)->get("/events/{$workshop->id}/applicants")->assertOk();
        $this->actingAs($zsofia)->get('/my-events')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('events', fn ($events) => collect($events)->pluck('title')->contains(Sprint4DemoSeeder::WEB_WORKSHOP)));
        $this->actingAs($zsofia)->post("/topics/{$web->id}/leaderboards", ['name' => 'Web projects'])->assertSessionHas('success');

        // Nothing else moved: the event, its applications, the leaderboard and the points.
        $this->assertSame(Event::STATUS_PUBLISHED, $workshop->fresh()->status);
        $this->assertSame(2, EventApplication::where('event_id', $workshop->id)->where('status', EventApplication::ACCEPTED)->count());
        $this->assertSame(1, $this->boxOnHome('nora', 'Web Development course')['you']['rank']);
    }
}

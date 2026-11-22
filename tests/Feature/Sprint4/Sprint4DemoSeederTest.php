<?php

namespace Tests\Feature\Sprint4;

use App\Enums\UserRole;
use App\Models\Event;
use App\Models\EventApplication;
use App\Models\Leaderboard;
use App\Models\PointTransaction;
use App\Models\RoleAssignment;
use App\Models\RoleDefinition;
use App\Models\Topic;
use App\Models\User;
use App\Services\CategoryList;
use App\Services\EventCatalog;
use App\Services\LeaderboardStandings;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\Sprint2DemoSeeder;
use Database\Seeders\Sprint3DemoSeeder;
use Database\Seeders\Sprint4DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Task #129: the data of the final presentation. A University root with its own area admin
 * (N3), an area admin on each course (N2), a handover ready to show (N4), and a leaderboard for
 * every demo student on Home, although nobody sees the boards above their own areas (D4, change
 * of 19 November).
 */
class Sprint4DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    private function seedDemo(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(Sprint4DemoSeeder::class);
    }

    private function person(string $key): User
    {
        $email = (Sprint4DemoSeeder::PEOPLE + Sprint3DemoSeeder::PEOPLE + Sprint2DemoSeeder::PEOPLE)[$key][1];

        return User::where('email', $email)->firstOrFail();
    }

    private function topic(string $title): Topic
    {
        return Topic::where('title', $title)->firstOrFail();
    }

    private function board(string $name): Leaderboard
    {
        return Leaderboard::where('name', $name)->firstOrFail();
    }

    /**
     * @return list<string> the keys of every student account in the demo data
     */
    private function demoStudents(): array
    {
        $cast = Sprint2DemoSeeder::PEOPLE + Sprint3DemoSeeder::PEOPLE + Sprint4DemoSeeder::PEOPLE;

        return array_keys(array_filter($cast, fn (array $person) => $person[2] === UserRole::Student));
    }

    /**
     * @return list<string> the names of the leaderboards on the student's Home
     */
    private function boardsOnHome(string $key): array
    {
        $names = [];

        $this->actingAs($this->person($key))->get('/')->assertOk()
            ->assertInertia(function (Assert $page) use (&$names) {
                $page->component('Home');
                $names = array_column($page->toArray()['props']['leaderboards'], 'name');
            });
        sort($names);

        return $names;
    }

    /**
     * @return array<string, int> Neptun code => points, as the leaderboard ranks them
     */
    private function standings(string $board): array
    {
        return app(LeaderboardStandings::class)->ranked($this->board($board))
            ->mapWithKeys(fn (object $row) => [$row->neptun_code => (int) $row->points])
            ->all();
    }

    private function areaAdminAt(string $title): RoleAssignment
    {
        return RoleAssignment::query()->active()
            ->where('topic_id', $this->topic($title)->id)
            ->whereHas('role', fn ($role) => $role->where('name', Sprint4DemoSeeder::AREA_ADMIN))
            ->with('user', 'grantedBy')
            ->sole();
    }

    public function test_the_university_root_has_its_own_area_admin_who_keeps_the_category_list(): void
    {
        $this->seedDemo();

        $root = $this->areaAdminAt(Sprint2DemoSeeder::ROOT);
        $this->assertTrue($root->user->is($this->person('olga')));
        $this->assertNull($this->topic(Sprint2DemoSeeder::ROOT)->parent_id);

        $categories = app(CategoryList::class);
        $this->assertTrue($categories->canManage($this->person('olga')));
        // The same role on a course does not reach the list: a category is shared by every branch.
        $this->assertFalse($categories->canManage($this->person('rita')));
    }

    public function test_each_course_has_an_area_admin_given_by_the_root_area_admin(): void
    {
        $this->seedDemo();

        foreach (['Databases' => 'rita', Sprint4DemoSeeder::WEB => 'tamas'] as $course => $key) {
            $assignment = $this->areaAdminAt($course);
            $this->assertTrue($assignment->user->is($this->person($key)), $course);
            $this->assertTrue($assignment->grantedBy->is($this->person('olga')), $course);
        }

        // Zsófia holds nothing yet: the system admin hands her the course during the presentation (N4).
        $this->assertSame(0, RoleAssignment::query()->active()->where('user_id', $this->person('zsofia')->id)->count());
    }

    public function test_every_demo_student_sees_a_leaderboard_on_home(): void
    {
        $this->seedDemo();

        $expected = [
            'anna' => ['Databases course'], 'bence' => ['Databases course'], 'csilla' => ['Databases course'],
            'dani' => ['Databases course'], 'eszter' => ['Databases course'],
            'erik' => ['Athletics Club ranking'], 'fanni' => ['Athletics Club ranking'],
            'hanna' => ['Fair ranking'], 'imre' => ['Fair ranking'], 'julia' => ['Fair ranking'],
            'karoly' => ['Fair ranking'], 'lili' => ['Fair ranking'], 'marton' => ['Fair ranking'],
            'nora' => ['Web Development course'], 'otto' => ['Web Development course'],
        ];

        // Every student of the three casts is listed above, so a new one cannot be forgotten.
        $this->assertEqualsCanonicalizing($this->demoStudents(), array_keys($expected));

        foreach ($expected as $key => $boards) {
            // Never the boards of the areas above their own: University total, Informatics total, Sport total.
            $this->assertSame($boards, $this->boardsOnHome($key), $key);
        }
    }

    public function test_each_leaderboard_is_made_by_the_person_who_runs_its_area_and_stays_off_the_hall_screen(): void
    {
        $this->seedDemo();

        foreach (Sprint4DemoSeeder::LEADERBOARDS as $name => [$area, $maker]) {
            $board = $this->board($name);

            $this->assertSame($area, $board->topic->title, $name);
            $this->assertSame($this->person($maker)->id, $board->created_by_id, $name);
            $this->assertFalse($board->isPublic(), $name);
            // The Neptun code and no name (D2, Task #62).
            $this->assertSame([true, false, false], [$board->show_neptun_code, $board->show_first_name, $board->show_last_name], $name);
        }
    }

    public function test_the_leaderboards_start_with_points_on_them(): void
    {
        $this->seedDemo();

        $this->assertSame(['BENC02' => 30, 'ANNA01' => 10, 'CSIL03' => 10, 'ESZT05' => 10], $this->standings('Databases course'));
        $this->assertSame(['ERIK06' => 25], $this->standings('Athletics Club ranking'));
        $this->assertSame(['IMRE02' => 15, 'KARO04' => 10], $this->standings('Fair ranking'));
        $this->assertEqualsCanonicalizing(['NORA07' => 10, 'OTTO01' => 10], $this->standings('Web Development course'));
    }

    public function test_the_course_that_is_handed_over_has_an_event_ahead_with_an_application(): void
    {
        $this->seedDemo();

        $workshop = Event::where('title', Sprint4DemoSeeder::WEB_WORKSHOP)->firstOrFail();

        $this->assertSame(Event::STATUS_PUBLISHED, $workshop->status);
        $this->assertTrue($workshop->event_starts_at->isFuture());
        $this->assertSame($this->person('tamas')->id, $workshop->created_by_id);
        $this->assertSame(EventApplication::ACCEPTED, EventApplication::where('event_id', $workshop->id)->where('student_id', $this->person('nora')->id)->value('status'));
    }

    public function test_the_fair_keeps_its_booth_rules(): void
    {
        $this->seedDemo();

        // The booth students now belong to the fair too, but a booth event still credits only its booth.
        $this->assertSame(
            [Sprint3DemoSeeder::ROBOTICS_EVENT],
            app(EventCatalog::class)->query($this->person('hanna'))
                ->whereIn('title', [Sprint3DemoSeeder::ROBOTICS_EVENT, Sprint3DemoSeeder::CODING_EVENT])
                ->pluck('title')->all(),
        );
        // Nóra is on Web Development now, still with no role in the fair.
        $fair = $this->topic(Sprint3DemoSeeder::FAIR);
        $this->assertSame(0, RoleAssignment::query()->active()->where('user_id', $this->person('nora')->id)
            ->whereIn('topic_id', Topic::query()->withinBranch($fair)->select('topics.id'))->count());
    }

    public function test_running_it_again_changes_nothing(): void
    {
        $this->seedDemo();

        $count = fn () => [User::count(), RoleDefinition::count(), RoleAssignment::count(), Leaderboard::count(), Event::count(), EventApplication::count(), PointTransaction::count()];
        $before = $count();

        $this->seed(Sprint4DemoSeeder::class);

        $this->assertSame($before, $count());
    }

    public function test_it_adds_to_a_database_that_already_has_the_sprint_3_data(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(Sprint3DemoSeeder::class);

        $this->seed(Sprint4DemoSeeder::class);

        $this->assertSame(['Databases course'], $this->boardsOnHome('anna'));
        $this->assertSame(['Web Development course'], $this->boardsOnHome('nora'));
    }

    public function test_it_refuses_to_run_in_production(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->app->detectEnvironment(fn () => 'production');

        (new Sprint4DemoSeeder)->setContainer($this->app)->run();

        $this->assertSame(0, User::where('email', 'like', 'sprint%@example.test')->count());
    }
}

<?php

namespace Tests\Feature\Leaderboards;

use App\Enums\Capability;
use App\Enums\UserRole;
use App\Models\Leaderboard;
use App\Models\PointTransaction;
use App\Models\Topic;
use App\Models\User;
use App\Services\OwnLeaderboards;
use App\Services\PointLedger;
use App\Services\TopicService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Events\EventTestCase;

/**
 * Task #54 and the leaderboards redesign (9 November): a box per leaderboard a person may see. A
 * student's box holds their place, the number listed, their points and the points to the next
 * place; a teacher's box says who leads. The selected board's ranking is on the same page, and
 * nobody sees a leaderboard they may not see.
 */
class OwnLeaderboardTest extends EventTestCase
{
    private Topic $sport;

    protected function setUp(): void
    {
        parent::setUp();

        // University > Faculty > Databases (from EventTestCase), University > Sport
        $this->sport = $this->topic('Sport', $this->university);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function board(Topic $topic, string $name, array $data = []): Leaderboard
    {
        return Leaderboard::create([
            'topic_id' => $topic->id,
            'name' => $name,
            'created_by_id' => $this->admin->id,
            ...$data,
        ])->fresh();
    }

    private function credit(User $student, Topic $owner, int $points, string $category = 'academic'): PointTransaction
    {
        $event = $this->makeEvent(['owning_topic_id' => $owner->id, 'audience_topic_id' => $owner->id, 'points_value' => $points, 'category' => $category]);

        return PointTransaction::create([
            'student_id' => $student->id,
            'event_id' => $event->id,
            'semester_id' => $event->semester_id,
            'credited_by_id' => $this->admin->id,
            'points' => $points,
            'type' => PointTransaction::CREDIT,
            'source' => 'import',
            'category' => $category,
        ]);
    }

    /** A student who belongs to a topic, so its area-only leaderboards are open to them. */
    private function member(User $student, Topic $topic): User
    {
        $this->assign($student, $this->viewer(), $topic);

        return $student;
    }

    public function test_the_your_points_box_is_gone_so_the_rankings_lead(): void
    {
        $anna = $this->student('Anna Nagy', 'ANNA01');
        $this->credit($anna, $this->course, 10, 'academic');

        $this->actingAs($anna)->get('/leaderboards')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Leaderboards/Index')
                ->where('isStudent', true)
                ->missing('points'));
    }

    public function test_a_student_sees_their_place_their_points_and_the_gap_to_the_next_place_on_each_leaderboard(): void
    {
        $anna = $this->member($this->student('Anna Nagy', 'ANNA01'), $this->faculty);
        $bence = $this->student('Bence Toth', 'BENC02');
        $csilla = $this->student('Csilla Varga', 'CSIL03');
        $this->credit($anna, $this->course, 10, 'academic');
        $this->credit($anna, $this->faculty, 5, 'sports');
        $this->credit($bence, $this->course, 40, 'scientific');
        $this->credit($csilla, $this->sport, 99, 'sports');   // another branch: not on the faculty's leaderboard

        // Anna belongs to the faculty, so the leaderboards of the faculty and of the areas below it are hers.
        $faculty = $this->board($this->faculty, 'Faculty total');
        $course = $this->board($this->course, 'Databases total');

        $this->actingAs($anna)->get('/leaderboards')
            ->assertInertia(fn (Assert $page) => $page
                ->has('leaderboards', 2)
                ->where('leaderboards.0.name', 'Databases total')
                ->where('leaderboards.0.students', 2)
                ->where('leaderboards.0.you', ['rank' => 2, 'points' => 10, 'to_next' => 30, 'next_rank' => 1])
                ->where('leaderboards.0.leader', null)
                ->where('leaderboards.1.name', 'Faculty total')
                ->where('leaderboards.1.students', 2)
                ->where('leaderboards.1.you', ['rank' => 2, 'points' => 15, 'to_next' => 25, 'next_rank' => 1]));

        // The numbers are the ones the leaderboard itself ranks by.
        $this->assertSame(2, $this->actingAs($anna)->get("/leaderboards?board={$faculty->id}")->viewData('page')['props']['selected']['standings']['you']['rank']);
        $this->assertSame(2, $this->actingAs($anna)->get("/leaderboards?board={$course->id}")->viewData('page')['props']['selected']['standings']['you']['rank']);
    }

    public function test_equal_points_share_a_rank(): void
    {
        $anna = $this->member($this->student(), $this->faculty);
        $bence = $this->member($this->student(), $this->faculty);
        $dora = $this->member($this->student(), $this->faculty);
        $csilla = $this->student();   // not a member: the area-only board is not open to Csilla
        $this->credit($csilla, $this->course, 30);
        $this->credit($anna, $this->course, 10);
        $this->credit($bence, $this->course, 10);
        $this->credit($dora, $this->course, 5);
        $this->board($this->faculty, 'Faculty total');

        // 1, 2, 2, 4: the two with equal points share number 2 and the next one is number 4
        foreach ([[$anna, 2], [$bence, 2], [$dora, 4]] as [$student, $rank]) {
            $this->actingAs($student)->get('/leaderboards')
                ->assertInertia(fn (Assert $page) => $page->where('leaderboards.0.you.rank', $rank)->where('leaderboards.0.students', 4));
        }

        $this->actingAs($csilla)->get('/leaderboards')
            ->assertInertia(fn (Assert $page) => $page->has('leaderboards', 0));
    }

    public function test_a_student_with_no_points_in_an_area_is_not_listed_there(): void
    {
        $anna = $this->member($this->student(), $this->faculty);
        $this->credit($this->student(), $this->course, 10);
        $this->credit($anna, $this->sport, 8);   // Anna's points are in another branch
        $this->board($this->faculty, 'Faculty total');

        $this->actingAs($anna)->get('/leaderboards')
            ->assertInertia(fn (Assert $page) => $page
                ->where('leaderboards.0.students', 1)
                ->where('leaderboards.0.you', null));
    }

    public function test_a_student_sees_the_leaderboards_of_their_areas_and_of_every_area_above_them(): void
    {
        $anna = $this->member($this->student(), $this->course);
        $this->credit($anna, $this->course, 10);

        $this->board($this->course, 'Course board');                                              // their own topic
        $this->board($this->faculty, 'Faculty board');                                            // above them (redesign, 9 November)
        $this->board($this->university, 'University total');                                      // the top of the tree
        $sport = $this->board($this->sport, 'Sport board');                                       // a sibling branch: hidden
        $this->board($this->sport, 'Sport published', ['visibility' => Leaderboard::PUBLIC]);     // published, but still a sibling branch (D4)

        $this->actingAs($anna)->get('/leaderboards?show=all')
            ->assertInertia(fn (Assert $page) => $page
                ->where('show', 'all')
                ->has('leaderboards', 3)
                ->where('leaderboards.0.name', 'Course board')
                ->where('leaderboards.1.name', 'Faculty board')
                ->where('leaderboards.2.name', 'University total'));

        // A board of a sibling area looks exactly like one that does not exist.
        $this->actingAs($anna)->get("/leaderboards?board={$sport->id}")->assertNotFound();
        $this->actingAs($anna)->get("/leaderboards/{$sport->id}")->assertNotFound();
    }

    public function test_staff_keep_the_areas_they_can_open_and_everything_below(): void
    {
        $teacher = $this->makeUser(UserRole::Teacher);
        $this->assign($teacher, $this->viewer(), $this->course);

        $this->board($this->course, 'Course board');
        $faculty = $this->board($this->faculty, 'Faculty board');

        $this->actingAs($teacher)->get('/leaderboards?show=all')
            ->assertInertia(fn (Assert $page) => $page->has('leaderboards', 1)->where('leaderboards.0.name', 'Course board'));
        $this->actingAs($teacher)->get("/leaderboards?board={$faculty->id}")->assertNotFound();
    }

    public function test_a_board_above_an_archived_area_of_the_student_is_not_shown(): void
    {
        $anna = $this->member($this->student(), $this->course);
        $this->board($this->university, 'University total');
        app(TopicService::class)->archive($this->admin, $this->faculty->fresh());

        // Archiving the faculty hides Anna's course too, so nothing above it is hers any more.
        $this->actingAs($anna)->get('/leaderboards?show=all')
            ->assertInertia(fn (Assert $page) => $page->has('leaderboards', 0)->where('selected', null));
    }

    public function test_a_leaderboard_of_an_archived_topic_is_not_shown(): void
    {
        $anna = $this->member($this->student(), $this->sport);
        $this->credit($anna, $this->sport, 10);
        $this->board($this->sport, 'Sport board');
        app(TopicService::class)->archive($this->admin, $this->sport->fresh());

        $this->actingAs($anna)->get('/leaderboards')
            ->assertInertia(fn (Assert $page) => $page->has('leaderboards', 0));
    }

    public function test_a_reversal_lowers_the_totals_and_the_rank(): void
    {
        $anna = $this->member($this->student(), $this->faculty);
        $bence = $this->student();
        $first = $this->credit($anna, $this->course, 50);
        $this->credit($bence, $this->course, 20);
        $this->board($this->faculty, 'Faculty total');

        $this->actingAs($anna)->get('/leaderboards')
            ->assertInertia(fn (Assert $page) => $page->where('leaderboards.0.you.rank', 1));

        app(PointLedger::class)->reverse($first, $this->admin, 'Wrong event');

        $this->actingAs($anna)->get('/leaderboards')
            ->assertInertia(fn (Assert $page) => $page->where('leaderboards.0.you', null));
    }

    public function test_a_student_sees_other_students_only_in_the_columns_of_the_board(): void
    {
        $anna = $this->member($this->student('Anna Nagy', 'ANNA01'), $this->faculty);
        $bence = $this->student('Bence Toth', 'BENC02');
        $this->credit($anna, $this->course, 10);
        $this->credit($bence, $this->course, 20);
        $this->board($this->faculty, 'Faculty total');   // Neptun codes only (L6)

        $props = $this->actingAs($anna)->get('/leaderboards')->viewData('page')['props'];

        // The boxes hold only Anna's own numbers; nobody else's.
        $boxes = json_encode($props['leaderboards']);
        $this->assertStringNotContainsString('BENC02', $boxes);
        $this->assertStringNotContainsString((string) $bence->id, json_encode(array_column($props['leaderboards'], 'leader')));

        // The ranking below shows Bence's Neptun code, the one column of the board, and nothing more.
        $ranking = json_encode($props['selected']);
        $this->assertStringContainsString('BENC02', $ranking);
        $this->assertStringNotContainsString('Bence', $ranking);
        $this->assertStringNotContainsString('Toth', $ranking);
        $this->assertStringNotContainsString('student_id', $ranking);
    }

    public function test_a_teacher_sees_the_leaderboards_they_may_see_and_no_points(): void
    {
        $teacher = $this->makeUser(UserRole::Teacher, 'Teacher One');
        $this->assign($teacher, $this->role('Reader', [Capability::View]), $this->faculty);
        $anna = $this->student();
        $this->credit($anna, $this->course, 10);
        $this->board($this->faculty, 'Faculty total');
        $this->board($this->sport, 'Sport board');   // not theirs

        $this->actingAs($teacher)->get('/')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Leaderboard/Index')
                ->where('isStudent', false)
                ->missing('points')
                ->has('leaderboards', 1)
                ->where('leaderboards.0.name', 'Faculty total')
                ->where('leaderboards.0.students', 1)
                ->where('leaderboards.0.you', null)
                ->where('leaderboards.0.leader', ['position' => 1, 'rank' => 1, 'points' => 10, 'neptun_code' => $anna->neptun_code]));

        $this->actingAs($teacher)->get('/leaderboards')
            ->assertInertia(fn (Assert $page) => $page->component('Leaderboards/Index')->missing('points')->has('leaderboards', 1));
    }

    public function test_a_teacher_without_any_leaderboard_gets_an_empty_list(): void
    {
        $teacher = $this->makeUser(UserRole::Teacher, 'Teacher One');

        $this->actingAs($teacher)->get('/')
            ->assertInertia(fn (Assert $page) => $page->component('Leaderboard/Index')->where('leaderboards', []));
    }

    public function test_a_teacher_and_a_student_of_the_same_area_see_the_same_leaderboards(): void
    {
        // D3, 7 November: one view for everyone. The leaderboards, their order and their lists are the
        // same; a person's own rank is the only thing added, and a teacher earns no points.
        $anna = $this->member($this->student(), $this->course);
        $bence = $this->member($this->student(), $this->course);
        $teacher = $this->makeUser(UserRole::Teacher);
        $this->assign($teacher, $this->viewer(), $this->course);
        $this->credit($anna, $this->course, 10);
        $this->credit($bence, $this->course, 30);

        $second = $this->board($this->course, 'B board');
        $this->board($this->course, 'A board');

        $listFor = fn (User $user) => $this->actingAs($user)->get('/leaderboards')->viewData('page')['props']['leaderboards'];
        $withoutRank = fn (array $rows) => array_map(fn (array $row) => Arr::except($row, ['you', 'leader']), $rows);

        $studentsList = $listFor($anna);
        $teachersList = $listFor($teacher);

        $this->assertSame(['A board', 'B board'], array_column($teachersList, 'name'));
        $this->assertSame($withoutRank($studentsList), $withoutRank($teachersList));
        $this->assertNull($teachersList[0]['you']);
        $this->assertNull($studentsList[0]['leader']);
        $this->assertSame(30, $teachersList[0]['leader']['points']);
        $this->assertSame(2, $studentsList[0]['you']['rank']);

        // The leaderboard itself: the same top places and the same total. Only the row of the person
        // looking is marked as theirs.
        $viewFor = fn (User $user) => $this->actingAs($user)->get("/leaderboards?board={$second->id}")->viewData('page')['props']['selected']['standings'];
        $placesFor = fn (User $user) => array_map(fn (array $row) => Arr::except($row, ['is_you']), $viewFor($user)['top']);

        $this->assertSame($placesFor($anna), $placesFor($teacher));
        $this->assertSame($viewFor($anna)['total'], $viewFor($teacher)['total']);
        $this->assertNull($viewFor($teacher)['you']);
        $this->assertCount(1, array_filter($viewFor($anna)['top'], fn (array $row) => $row['is_you']));
        $this->assertCount(0, array_filter($viewFor($teacher)['top'], fn (array $row) => $row['is_you']));
    }

    public function test_the_pages_declare_every_prop_the_server_sends(): void
    {
        // A page only gets the props it declares, under the very same names, and no other test would
        // notice a mismatch: with "is_student" sent and "isStudent" declared, a student was shown the
        // teachers' page. The three pages that show the boxes all take the same three props.
        $sent = array_keys(app(OwnLeaderboards::class)->for($this->student()));

        $this->assertSame(['isStudent', 'leaderboards', 'highlightLimit'], $sent);

        foreach (['Pages/Leaderboards/Index', 'Pages/Leaderboard/Index', 'Pages/Home'] as $file) {
            $source = file_get_contents(resource_path("js/{$file}.vue"));

            foreach ($sent as $prop) {
                $this->assertMatchesRegularExpression('/^\s+'.$prop.':\s*\{/m', $source, "{$file}.vue does not declare the prop \"{$prop}\" that the server sends");
            }
        }
    }

    public function test_a_student_starts_on_the_home_page_and_everyone_else_on_their_leaderboard(): void
    {
        $this->actingAs($this->student())->get('/')->assertInertia(fn (Assert $page) => $page->component('Home'));
        $this->actingAs($this->makeUser(UserRole::Teacher))->get('/')->assertInertia(fn (Assert $page) => $page->component('Leaderboard/Index'));
    }

    public function test_a_new_credit_shows_on_the_page_at_once_although_the_totals_are_cached(): void
    {
        config(['leaderboards.ttl' => 30, 'leaderboards.cache_store' => 'array']);
        Cache::store('array')->flush();

        $anna = $this->member($this->student(), $this->faculty);
        $bence = $this->student();
        $this->credit($anna, $this->course, 10);
        $this->credit($bence, $this->course, 20);
        $this->board($this->faculty, 'Faculty total');

        $this->actingAs($anna)->get('/leaderboards')
            ->assertInertia(fn (Assert $page) => $page->where('leaderboards.0.you.rank', 2)->where('leaderboards.0.you.points', 10));

        // Same numbers from the cache while nothing is credited ...
        DB::table('point_transactions')->insert([
            'student_id' => $anna->id, 'event_id' => $this->event->id, 'semester_id' => $this->event->semester_id, 'credited_by_id' => $this->admin->id,
            'points' => 100, 'type' => PointTransaction::CREDIT, 'source' => 'import', 'category' => 'academic', 'created_at' => now(),
        ]);
        $this->actingAs($anna)->get('/leaderboards')
            ->assertInertia(fn (Assert $page) => $page->where('leaderboards.0.you.rank', 2)->where('leaderboards.0.you.points', 10));

        // ... and a credit through the ledger shows on the next visit, with the quiet row as well.
        $this->credit($anna, $this->course, 5);
        $this->actingAs($anna)->get('/leaderboards')
            ->assertInertia(fn (Assert $page) => $page->where('leaderboards.0.you.rank', 1)->where('leaderboards.0.you.points', 115));
    }
}

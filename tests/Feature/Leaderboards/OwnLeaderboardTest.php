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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Events\EventTestCase;

/**
 * Task #54: signed-in people see their own leaderboard. A student sees their points in total and
 * per category, and their rank on each leaderboard they may see; a teacher sees the leaderboards
 * they may see. Totals come from the ledger, and nobody sees a leaderboard they may not see.
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

    public function test_a_student_sees_their_points_in_total_and_per_category_as_the_ledger_adds_them_up(): void
    {
        $anna = $this->student('Anna Nagy', 'ANNA01');
        $this->credit($anna, $this->course, 10, 'academic');
        $this->credit($anna, $this->course, 4, 'academic');
        $this->credit($anna, $this->sport, 7, 'sports');
        $this->credit($anna, $this->faculty, 3, 'community');

        $this->actingAs($anna)->get('/leaderboards')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Leaderboards/Index')
                ->where('isStudent', true)
                ->where('points', ['total' => 24, 'academic' => 14, 'scientific' => 0, 'sports' => 7, 'community' => 3]));

        $this->assertSame(24, (int) DB::table('point_transactions')->where('student_id', $anna->id)->sum('points'));
    }

    public function test_a_student_sees_their_rank_and_their_points_per_category_on_each_leaderboard(): void
    {
        $anna = $this->member($this->student('Anna Nagy', 'ANNA01'), $this->faculty);
        $bence = $this->student('Bence Toth', 'BENC02');
        $csilla = $this->student('Csilla Varga', 'CSIL03');
        $this->credit($anna, $this->course, 10, 'academic');
        $this->credit($anna, $this->faculty, 5, 'sports');
        $this->credit($bence, $this->course, 40, 'scientific');
        $this->credit($csilla, $this->sport, 99, 'sports');   // another branch: not on the faculty's leaderboard

        $faculty = $this->board($this->faculty, 'Faculty total');
        $university = $this->board($this->university, 'University total', ['visibility' => Leaderboard::PUBLIC]);

        $this->actingAs($anna)->get('/leaderboards')
            ->assertInertia(fn (Assert $page) => $page
                ->has('leaderboards', 2)
                ->where('leaderboards.0.name', 'Faculty total')
                ->where('leaderboards.0.students', 2)
                ->where('leaderboards.0.you', ['rank' => 2, 'points' => 15, 'categories' => ['academic' => 10, 'scientific' => 0, 'sports' => 5, 'community' => 0]])
                ->where('leaderboards.1.name', 'University total')
                ->where('leaderboards.1.students', 3)
                ->where('leaderboards.1.you', ['rank' => 3, 'points' => 15, 'categories' => ['academic' => 10, 'scientific' => 0, 'sports' => 5, 'community' => 0]]));

        // The numbers are the ones the leaderboard itself ranks by.
        $this->assertSame(2, $this->actingAs($anna)->get("/leaderboards/{$faculty->id}")->viewData('page')['props']['standings']['you']['rank']);
        $this->assertSame(3, $this->actingAs($anna)->get("/leaderboards/{$university->id}")->viewData('page')['props']['standings']['you']['rank']);
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
                ->where('leaderboards.0.you', null)
                ->where('points.total', 8));
    }

    public function test_a_student_sees_only_the_leaderboards_they_may_see(): void
    {
        $anna = $this->member($this->student(), $this->course);
        $this->credit($anna, $this->course, 10);

        $this->board($this->course, 'Course board');                                              // their own topic
        $this->board($this->faculty, 'Faculty board');                                            // above them: not theirs
        $this->board($this->sport, 'Sport board');                                                // another branch
        $this->board($this->sport, 'Sport published', ['visibility' => Leaderboard::PUBLIC]);     // published: for everybody

        $this->actingAs($anna)->get('/leaderboards')
            ->assertInertia(fn (Assert $page) => $page
                ->has('leaderboards', 2)
                ->where('leaderboards.0.name', 'Course board')
                ->where('leaderboards.1.name', 'Sport published'));
    }

    public function test_a_leaderboard_of_an_archived_topic_is_not_shown(): void
    {
        $anna = $this->member($this->student(), $this->sport);
        $this->credit($anna, $this->sport, 10);
        $this->board($this->sport, 'Sport board');
        app(TopicService::class)->archive($this->admin, $this->sport->fresh());

        $this->actingAs($anna)->get('/leaderboards')
            ->assertInertia(fn (Assert $page) => $page->has('leaderboards', 0)->where('points.total', 10));
    }

    public function test_a_reversal_lowers_the_totals_and_the_rank(): void
    {
        $anna = $this->member($this->student(), $this->faculty);
        $bence = $this->student();
        $first = $this->credit($anna, $this->course, 50);
        $this->credit($bence, $this->course, 20);
        $this->board($this->faculty, 'Faculty total');

        $this->actingAs($anna)->get('/leaderboards')
            ->assertInertia(fn (Assert $page) => $page->where('leaderboards.0.you.rank', 1)->where('points.total', 50));

        app(PointLedger::class)->reverse($first, $this->admin, 'Wrong event');

        $this->actingAs($anna)->get('/leaderboards')
            ->assertInertia(fn (Assert $page) => $page->where('leaderboards.0.you', null)->where('points.total', 0));
    }

    public function test_a_page_does_not_show_other_students_to_a_student(): void
    {
        $anna = $this->member($this->student('Anna Nagy', 'ANNA01'), $this->faculty);
        $bence = $this->student('Bence Toth', 'BENC02');
        $this->credit($anna, $this->course, 10);
        $this->credit($bence, $this->course, 20);
        $this->board($this->faculty, 'Faculty total');

        $page = json_encode($this->actingAs($anna)->get('/leaderboards')->viewData('page')['props']);

        $this->assertStringNotContainsString('BENC02', $page);
        $this->assertStringNotContainsString('Bence', $page);
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
                ->where('points', null)
                ->has('leaderboards', 1)
                ->where('leaderboards.0.name', 'Faculty total')
                ->where('leaderboards.0.students', 1)
                ->where('leaderboards.0.you', null));

        $this->actingAs($teacher)->get('/leaderboards')
            ->assertInertia(fn (Assert $page) => $page->component('Leaderboards/Index')->where('points', null)->has('leaderboards', 1));
    }

    public function test_a_teacher_without_any_leaderboard_gets_an_empty_list(): void
    {
        $teacher = $this->makeUser(UserRole::Teacher, 'Teacher One');

        $this->actingAs($teacher)->get('/')
            ->assertInertia(fn (Assert $page) => $page->component('Leaderboard/Index')->where('leaderboards', []));
    }

    public function test_the_pages_declare_every_prop_the_server_sends(): void
    {
        // A page only gets the props it declares, under the very same names, and no other test would
        // notice a mismatch: with "is_student" sent and "isStudent" declared, a student was shown the
        // teachers' page. The pages and the shared component all take the same three props.
        $sent = array_keys(app(OwnLeaderboards::class)->for($this->student()));

        $this->assertSame(['isStudent', 'points', 'leaderboards'], $sent);

        foreach (['Pages/Leaderboards/Index', 'Pages/Leaderboard/Index', 'Components/Leaderboards/OwnLeaderboards'] as $file) {
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
            ->assertInertia(fn (Assert $page) => $page->where('leaderboards.0.you.rank', 2)->where('points.total', 10));

        // Same numbers from the cache while nothing is credited ...
        DB::table('point_transactions')->insert([
            'student_id' => $anna->id, 'event_id' => $this->event->id, 'semester_id' => $this->event->semester_id, 'credited_by_id' => $this->admin->id,
            'points' => 100, 'type' => PointTransaction::CREDIT, 'source' => 'import', 'category' => 'academic', 'created_at' => now(),
        ]);
        $this->actingAs($anna)->get('/leaderboards')
            ->assertInertia(fn (Assert $page) => $page->where('leaderboards.0.you.rank', 2)->where('points.total', 10));

        // ... and a credit through the ledger shows on the next visit, with the quiet row as well.
        $this->credit($anna, $this->course, 5);
        $this->actingAs($anna)->get('/leaderboards')
            ->assertInertia(fn (Assert $page) => $page->where('leaderboards.0.you.rank', 1)->where('points.total', 115));
    }
}

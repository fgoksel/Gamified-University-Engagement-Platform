<?php

namespace Tests\Feature\Leaderboards;

use App\Enums\Capability;
use App\Enums\UserRole;
use App\Models\Leaderboard;
use App\Models\PointTransaction;
use App\Models\Topic;
use App\Models\User;
use App\Services\AreaLeaderboards;
use App\Services\LeaderboardStandings;
use App\Services\PointLedger;
use App\Services\TopicService;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Events\EventTestCase;

/**
 * Task #89: topic admins create and publish area leaderboards.
 *
 * A leaderboard sums everything in its area and all sub-areas; nothing narrower can be picked
 * (L1, 27 October). Published means public: seen without login and on the hall screen; otherwise
 * it is for the people of the area (L4). It shows Neptun codes unless names are ticked (L6).
 */
class AreaLeaderboardTest extends EventTestCase
{
    private Topic $web;

    private Topic $sport;

    private User $areaAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        // University > Faculty > Databases (from EventTestCase), Faculty > Web, University > Sport
        $this->web = $this->topic('Web', $this->faculty);
        $this->sport = $this->topic('Sport', $this->university);

        $this->areaAdmin = $this->makeUser(UserRole::Teacher, 'Area Admin');
        $this->assign($this->areaAdmin, $this->role('Area admin', [Capability::View, Capability::LeaderboardsManage]), $this->faculty);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function board(?Topic $topic = null, array $data = []): Leaderboard
    {
        return Leaderboard::create([
            'topic_id' => ($topic ?? $this->faculty)->id,
            'name' => 'Board '.uniqid(),
            'created_by_id' => $this->admin->id,
            ...$data,
        ])->fresh();
    }

    private function credit(User $student, Topic $owner, int $points): PointTransaction
    {
        $event = $this->makeEvent(['owning_topic_id' => $owner->id, 'audience_topic_id' => $owner->id, 'points_value' => $points]);

        return PointTransaction::create([
            'student_id' => $student->id,
            'event_id' => $event->id,
            'semester_id' => $event->semester_id,
            'credited_by_id' => $this->admin->id,
            'points' => $points,
            'type' => PointTransaction::CREDIT,
            'source' => 'import',
            'category' => 'academic',
        ]);
    }

    /**
     * @return array<string, int> Neptun code => points, best first
     */
    private function totals(Leaderboard $board): array
    {
        return app(LeaderboardStandings::class)->ranked($board)->pluck('points', 'neptun_code')->all();
    }

    /**
     * A person who may not do something goes home with a message (bootstrap/app.php).
     */
    private function assertRefused(TestResponse $response): void
    {
        $response->assertRedirect('/')->assertSessionHas('error', 'You do not have permission to view this page.');
    }

    public function test_a_topic_admin_creates_a_leaderboard_that_starts_area_only_with_the_neptun_code_alone(): void
    {
        $this->actingAs($this->areaAdmin)
            ->post("/topics/{$this->faculty->id}/leaderboards", ['name' => '  Faculty total  '])
            ->assertRedirect("/topics/{$this->faculty->id}?tab=leaderboards")
            ->assertSessionHas('success');

        $board = Leaderboard::firstOrFail();
        $this->assertSame('Faculty total', $board->name);
        $this->assertSame($this->faculty->id, $board->topic_id);
        $this->assertSame($this->areaAdmin->id, $board->created_by_id);
        $this->assertFalse($board->isPublic());
        $this->assertSame(['neptun_code'], $board->columns());
    }

    public function test_only_people_with_the_right_at_the_area_or_above_can_create_or_edit(): void
    {
        $universityAdmin = $this->makeUser(UserRole::Teacher, 'University Admin');
        $this->assign($universityAdmin, $this->role('University admin', [Capability::View, Capability::LeaderboardsManage]), $this->university);

        $courseAdmin = $this->makeUser(UserRole::Teacher, 'Course Admin');
        $this->assign($courseAdmin, $this->role('Course admin', [Capability::View, Capability::LeaderboardsManage]), $this->course);

        $sportAdmin = $this->makeUser(UserRole::Teacher, 'Sport Admin');
        $this->assign($sportAdmin, $this->role('Sport admin', [Capability::View, Capability::LeaderboardsManage]), $this->sport);

        $reader = $this->makeUser(UserRole::Student, 'Reader');
        $this->assign($reader, $this->viewer(), $this->faculty);

        $url = "/topics/{$this->faculty->id}/leaderboards";

        // A right held above or at the area counts, one held below or beside it does not.
        $this->actingAs($universityAdmin)->post($url, ['name' => 'From above'])->assertRedirect();
        $this->actingAs($this->admin)->post($url, ['name' => 'From the System Admin'])->assertRedirect();
        $this->actingAs($courseAdmin)->post($url, ['name' => 'From below'])->assertNotFound();
        $this->actingAs($sportAdmin)->post($url, ['name' => 'From beside'])->assertNotFound();
        $this->assertRefused($this->actingAs($reader)->post($url, ['name' => 'From a reader']));
        $this->assertSame(['From above', 'From the System Admin'], Leaderboard::orderBy('id')->pluck('name')->all());

        $board = Leaderboard::where('name', 'From above')->firstOrFail();
        $this->actingAs($this->areaAdmin)->put("/leaderboards/{$board->id}", ['name' => 'Renamed'])->assertRedirect();
        $this->assertRefused($this->actingAs($reader)->put("/leaderboards/{$board->id}", ['name' => 'Hacked']));
        $this->assertRefused($this->actingAs($reader)->delete("/leaderboards/{$board->id}"));

        $board->refresh();
        $this->assertSame('Renamed', $board->name);
        $this->assertFalse($board->isPublic());
    }

    public function test_the_name_is_required_and_unique_within_its_topic(): void
    {
        $url = "/topics/{$this->faculty->id}/leaderboards";

        $this->actingAs($this->areaAdmin)->post($url, ['name' => ''])
            ->assertSessionHasErrors(['name' => 'Give the leaderboard a name.']);

        $first = $this->board(null, ['name' => 'Total']);

        $this->actingAs($this->areaAdmin)->post($url, ['name' => 'Total'])
            ->assertSessionHasErrors(['name' => 'This area already has a leaderboard with that name.']);

        // The same name is fine on another topic, and a leaderboard keeps its own name when edited.
        $this->actingAs($this->areaAdmin)->post("/topics/{$this->course->id}/leaderboards", ['name' => 'Total'])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->areaAdmin)->put("/leaderboards/{$first->id}", ['name' => 'Total', 'show_first_name' => true])
            ->assertSessionHasNoErrors();
    }

    public function test_at_least_one_column_must_show(): void
    {
        $this->actingAs($this->areaAdmin)
            ->post("/topics/{$this->faculty->id}/leaderboards", [
                'name' => 'Nobody can tell',
                'show_neptun_code' => false,
                'show_first_name' => false,
                'show_last_name' => false,
            ])
            ->assertSessionHasErrors('columns');

        $this->assertSame(0, Leaderboard::count());
    }

    public function test_the_total_covers_the_area_and_every_sub_area_and_nothing_else(): void
    {
        $anna = $this->student('Anna Nagy', 'ANNA01');

        $this->credit($anna, $this->faculty, 1);   // the area itself
        $this->credit($anna, $this->course, 2);    // a sub-area
        $this->credit($anna, $this->web, 4);       // another sub-area
        $this->credit($anna, $this->sport, 8);     // beside it
        $this->credit($anna, $this->university, 16); // above it

        $this->assertSame(['ANNA01' => 7], $this->totals($this->board($this->faculty)));
        $this->assertSame(['ANNA01' => 2], $this->totals($this->board($this->course)));
        $this->assertSame(['ANNA01' => 31], $this->totals($this->board($this->university)));
    }

    public function test_a_topic_moved_into_the_area_counts_at_once(): void
    {
        $anna = $this->student('Anna Nagy', 'ANNA01');
        $this->credit($anna, $this->course, 5);
        $this->credit($anna, $this->sport, 10);

        $board = $this->board($this->faculty);
        $this->assertSame(['ANNA01' => 5], $this->totals($board));

        app(TopicService::class)->move($this->admin, $this->sport->fresh(), $this->faculty->fresh());

        $this->assertSame(['ANNA01' => 15], $this->totals($board));
    }

    public function test_equal_points_share_a_rank(): void
    {
        foreach (['AAA' => 30, 'BBB' => 20, 'CCC' => 20, 'DDD' => 10] as $neptun => $points) {
            $this->credit($this->student("Student {$neptun}", $neptun), $this->course, $points);
        }

        $ranked = app(LeaderboardStandings::class)->ranked($this->board());

        $this->assertSame(['AAA', 'BBB', 'CCC', 'DDD'], $ranked->pluck('neptun_code')->all());
        $this->assertSame([1, 2, 2, 4], $ranked->pluck('rank')->all());
    }

    public function test_only_students_appear_and_reversed_points_drop_out(): void
    {
        $kept = $this->student('Kept Student', 'KEPT01');
        $reversed = $this->student('Reversed Student', 'REVR02');
        $partly = $this->student('Partly Reversed', 'PART03');

        $invited = $this->student('Invited Student', 'INVI04');
        $invited->forceFill(['status' => 'invited'])->save();

        $banned = $this->student('Banned Student', 'BANN05');

        $teacher = $this->makeUser(UserRole::Teacher, 'A Teacher');
        $teacher->forceFill(['neptun_code' => 'TEACH6'])->save();

        $this->credit($kept, $this->course, 10);
        $toReverse = $this->credit($reversed, $this->course, 10);
        $this->credit($partly, $this->course, 10);
        $partReversed = $this->credit($partly, $this->web, 3);
        $this->credit($invited, $this->course, 5);
        $this->credit($banned, $this->course, 50);
        $this->credit($teacher, $this->course, 50);

        $banned->forceFill(['status' => 'inactive'])->save();

        app(PointLedger::class)->reverse($toReverse, $this->admin, 'Wrong student');
        app(PointLedger::class)->reverse($partReversed, $this->admin, 'Wrong event');

        $this->assertSame(['KEPT01' => 10, 'PART03' => 10, 'INVI04' => 5], $this->totals($this->board()));
    }

    public function test_publish_and_unpublish_control_what_the_hall_screen_gets_without_login(): void
    {
        $this->credit($this->student('Anna Nagy', 'ANNA01'), $this->course, 20);

        $board = $this->board($this->faculty, ['name' => 'Faculty total']);

        $this->get('/hall/leaderboards')->assertOk()->assertExactJson(['leaderboards' => []]);

        app(AreaLeaderboards::class)->setPublished($this->admin, $board, true);
        $this->assertTrue($board->fresh()->isPublic());

        $this->get('/hall/leaderboards')->assertOk()->assertExactJson(['leaderboards' => [[
            'id' => $board->id,
            'name' => 'Faculty total',
            'topic' => 'Faculty',
            'columns' => ['neptun_code'],
            'seconds' => Leaderboard::HALL_SECONDS,
            'total' => 1,
            // The Neptun code alone: no name, no email, no account id (L6).
            'rows' => [['position' => 1, 'rank' => 1, 'points' => 20, 'neptun_code' => 'ANNA01']],
        ]]]);

        app(AreaLeaderboards::class)->setPublished($this->admin, $board, false);
        $this->assertFalse($board->fresh()->isPublic());

        $this->get('/hall/leaderboards')->assertOk()->assertExactJson(['leaderboards' => []]);
    }

    public function test_names_show_only_when_their_columns_are_ticked(): void
    {
        $this->credit($this->student('Anna Maria Nagy', 'ANNA01'), $this->course, 20);

        $board = $this->board($this->faculty, ['visibility' => Leaderboard::PUBLIC, 'show_neptun_code' => false, 'show_last_name' => true]);
        $this->get('/hall/leaderboards')->assertOk()
            ->assertJsonPath('leaderboards.0.columns', ['last_name'])
            ->assertJsonPath('leaderboards.0.rows.0', ['position' => 1, 'rank' => 1, 'points' => 20, 'last_name' => 'Nagy']);

        $board->update(['show_neptun_code' => true, 'show_first_name' => true]);
        $this->get('/hall/leaderboards')->assertOk()
            ->assertJsonPath('leaderboards.0.columns', ['neptun_code', 'first_name', 'last_name'])
            ->assertJsonPath('leaderboards.0.rows.0', ['position' => 1, 'rank' => 1, 'points' => 20, 'neptun_code' => 'ANNA01', 'first_name' => 'Anna Maria', 'last_name' => 'Nagy']);
    }

    public function test_the_hall_screen_gets_the_top_ten_and_the_total(): void
    {
        foreach (range(1, 12) as $i) {
            $this->credit($this->student("Student {$i}", sprintf('N%05d', $i)), $this->course, 100 - $i);
        }

        $this->board($this->faculty, ['visibility' => Leaderboard::PUBLIC]);

        $this->get('/hall/leaderboards')->assertOk()
            ->assertJsonPath('leaderboards.0.total', 12)
            ->assertJsonCount(10, 'leaderboards.0.rows');
    }

    public function test_an_archived_topic_takes_its_leaderboards_away(): void
    {
        $board = $this->board($this->course, ['visibility' => Leaderboard::PUBLIC]);

        app(TopicService::class)->archive($this->admin, $this->faculty->fresh());

        $this->get('/hall/leaderboards')->assertOk()->assertExactJson(['leaderboards' => []]);

        $this->actingAs($this->areaAdmin)->get("/leaderboards/{$board->id}")->assertNotFound();
        $this->actingAs($this->areaAdmin)->get('/leaderboards')
            ->assertInertia(fn (Assert $page) => $page->where('leaderboards', []));
    }

    public function test_a_leaderboard_is_for_people_who_can_open_its_topic(): void
    {
        $board = $this->board($this->faculty, ['name' => 'Faculty total']);

        $member = $this->student('Faculty Member', 'MEMB01');
        $this->assign($member, $this->studentRole(), $this->faculty);

        $belowArea = $this->student('Course Student', 'COUR02');
        $this->assign($belowArea, $this->studentRole(), $this->course);

        $outsider = $this->student('Sport Student', 'SPOR03');
        $this->assign($outsider, $this->studentRole(), $this->sport);

        // The board page of before the redesign opens the Leaderboards page with the board selected.
        $this->actingAs($member)->get("/leaderboards/{$board->id}")
            ->assertRedirect("/leaderboards?board={$board->id}");
        $this->actingAs($member)->get("/leaderboards?board={$board->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Leaderboards/Index')
                ->where('selected.name', 'Faculty total'));

        // A student below the area sees the boards above their own (redesign, 9 November).
        $this->actingAs($belowArea)->get("/leaderboards?board={$board->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('selected.name', 'Faculty total'));

        // Not allowed looks exactly like not existing.
        $this->actingAs($outsider)->get("/leaderboards/{$board->id}")->assertNotFound();
        $this->actingAs($outsider)->get("/leaderboards?board={$board->id}")->assertNotFound();

        $this->actingAs($member)->get('/leaderboards')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Leaderboards/Index')
                ->where('leaderboards.0.name', 'Faculty total'));
        $this->actingAs($outsider)->get('/leaderboards')
            ->assertInertia(fn (Assert $page) => $page->where('leaderboards', [])->where('selected', null));
    }

    public function test_a_published_leaderboard_stays_closed_to_other_areas(): void
    {
        // D4, 7 November: people see the leaderboards of the areas they are assigned to. Publishing
        // only puts a leaderboard on the hall screen; it opens nothing for anyone else.
        $board = $this->board($this->faculty, ['name' => 'Faculty total', 'visibility' => Leaderboard::PUBLIC]);

        $member = $this->student('Faculty Member', 'MEMB01');
        $this->assign($member, $this->studentRole(), $this->faculty);

        $outsider = $this->student('Sport Student', 'SPOR03');
        $this->assign($outsider, $this->studentRole(), $this->sport);

        $this->actingAs($outsider)->get("/leaderboards/{$board->id}")->assertNotFound();
        $this->actingAs($outsider)->get("/leaderboards?board={$board->id}")->assertNotFound();
        $this->actingAs($outsider)->get('/leaderboards')
            ->assertInertia(fn (Assert $page) => $page->where('leaderboards', []));

        // The people of the area still see it, published or not.
        $this->actingAs($member)->get("/leaderboards?board={$board->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('selected.is_public', true));

        // The hall feed is the part without a login, and it still carries the published board.
        auth()->logout();
        $this->get("/leaderboards/{$board->id}")->assertRedirect('/login');
        $this->getJson('/hall/leaderboards')->assertOk()->assertJsonPath('leaderboards.0.name', 'Faculty total');
    }

    public function test_the_system_admin_opens_every_leaderboard(): void
    {
        $faculty = $this->board($this->faculty, ['name' => 'Faculty total']);
        $sport = $this->board($this->sport, ['name' => 'Sport total']);

        $this->actingAs($this->admin)->get("/leaderboards/{$sport->id}")->assertRedirect("/leaderboards?board={$sport->id}");
        $this->actingAs($this->admin)->get("/leaderboards?board={$sport->id}")->assertOk();
        $this->actingAs($this->admin)->get('/leaderboards')
            ->assertInertia(fn (Assert $page) => $page
                ->has('leaderboards', 2)
                ->where('leaderboards.0.name', $faculty->name)
                ->where('leaderboards.1.name', $sport->name));
    }

    public function test_a_student_sees_the_top_ten_about_ten_places_around_them_and_the_total(): void
    {
        $students = [];
        foreach (range(1, 30) as $place) {
            $students[$place] = $this->student("Student {$place}", sprintf('N%05d', $place));
            $this->credit($students[$place], $this->course, 1000 - $place);
        }

        $board = $this->board();
        $standings = app(LeaderboardStandings::class);

        // Far down: places 15 to 25 around place 20.
        $view = $standings->view($board, $students[20]);
        $this->assertSame(30, $view['total']);
        $this->assertSame(range(1, 10), array_column($view['top'], 'position'));
        $this->assertSame(range(15, 25), array_column($view['around'], 'position'));
        $this->assertTrue($view['places_skipped']);
        $this->assertSame(['rank' => 20, 'points' => 980, 'to_next' => 1, 'next_rank' => 19], $view['you']);
        $this->assertFalse($view['everyone']);
        $this->assertSame([20], array_column(array_filter($view['around'], fn ($row) => $row['is_you']), 'position'));

        // Just under the top: no places are skipped.
        $view = $standings->view($board, $students[12]);
        $this->assertSame(range(11, 17), array_column($view['around'], 'position'));
        $this->assertFalse($view['places_skipped']);

        // Near the end: the list stops at the last student.
        $view = $standings->view($board, $students[29]);
        $this->assertSame(range(24, 30), array_column($view['around'], 'position'));

        // In the top: nothing more is needed.
        $view = $standings->view($board, $students[3]);
        $this->assertSame([], $view['around']);
        $this->assertFalse($view['places_skipped']);
        $this->assertSame(['rank' => 3, 'points' => 997, 'to_next' => 1, 'next_rank' => 2], $view['you']);

        // Not on the list: only the top.
        $view = $standings->view($board, $this->areaAdmin);
        $this->assertNull($view['you']);
        $this->assertSame([], $view['around']);
        $this->assertSame(30, $view['total']);

        // "Show all": every place, in order, and nothing around.
        $view = $standings->view($board, $students[20], everyone: true);
        $this->assertSame(range(1, 30), array_column($view['top'], 'position'));
        $this->assertSame([], $view['around']);
        $this->assertFalse($view['places_skipped']);
        $this->assertTrue($view['everyone']);
        $this->assertSame(20, $view['you']['rank']);
    }

    public function test_show_all_on_the_page_lists_every_place_of_the_selected_board(): void
    {
        foreach (range(1, 14) as $place) {
            $this->credit($this->student("Student {$place}", sprintf('N%05d', $place)), $this->course, 100 - $place);
        }
        $board = $this->board();

        $this->actingAs($this->areaAdmin)->get("/leaderboards?board={$board->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->has('selected.standings.top', 10)
                ->where('selected.standings.everyone', false));

        $this->actingAs($this->areaAdmin)->get("/leaderboards?board={$board->id}&places=all")
            ->assertInertia(fn (Assert $page) => $page
                ->has('selected.standings.top', 14)
                ->where('selected.standings.everyone', true));
    }

    public function test_the_topics_leaderboards_tab_lists_its_own_and_says_who_may_manage(): void
    {
        $this->board($this->faculty, ['name' => 'Faculty total']);
        $this->board($this->course, ['name' => 'Course total']);

        $reader = $this->makeUser(UserRole::Student, 'Reader');
        $this->assign($reader, $this->viewer(), $this->faculty);

        $this->actingAs($this->areaAdmin)->get("/topics/{$this->faculty->id}?tab=leaderboards")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tab', 'leaderboards')
                ->where('selected.can.manage_leaderboards', true)
                ->has('selected.leaderboards', 1)
                ->where('selected.leaderboards.0.name', 'Faculty total')
                ->where('selected.leaderboards.0.is_public', false)
                ->where('selected.leaderboards.0.columns', ['neptun_code']));

        $this->actingAs($reader)->get("/topics/{$this->faculty->id}?tab=leaderboards")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('selected.can.manage_leaderboards', false)
                ->has('selected.leaderboards', 1));

        // Only that tab loads them.
        $this->actingAs($this->areaAdmin)->get("/topics/{$this->faculty->id}")
            ->assertInertia(fn (Assert $page) => $page->where('tab', 'topic')->where('selected.leaderboards', null));
    }

    public function test_deleting_a_leaderboard_changes_no_points_and_a_topic_with_one_is_not_deleted(): void
    {
        $empty = $this->topic('Empty', $this->faculty);
        $board = $this->board($empty, ['name' => 'Empty total']);

        $this->assertContains('1 leaderboard(s) are made for it.', app(TopicService::class)->deleteBlockers($empty));

        $anna = $this->student('Anna Nagy', 'ANNA01');
        $this->credit($anna, $empty, 5);
        $before = app(PointLedger::class)->totals($anna);

        $this->actingAs($this->areaAdmin)->delete("/leaderboards/{$board->id}")->assertRedirect();

        $this->assertSame(0, Leaderboard::count());
        $this->assertSame($before, app(PointLedger::class)->totals($anna));
    }

    public function test_every_change_is_in_the_audit_trail_and_only_real_changes_count(): void
    {
        $boards = app(AreaLeaderboards::class);

        $board = $boards->create($this->areaAdmin, $this->faculty, ['name' => 'Total', 'show_neptun_code' => true, 'show_first_name' => false, 'show_last_name' => false]);
        $boards->update($this->areaAdmin, $board, ['name' => 'Total', 'show_neptun_code' => true, 'show_first_name' => false, 'show_last_name' => false]);
        $boards->update($this->areaAdmin, $board, ['name' => 'Faculty total', 'show_neptun_code' => true, 'show_first_name' => false, 'show_last_name' => false]);
        $boards->setPublished($this->areaAdmin, $board, true);
        $boards->setPublished($this->areaAdmin, $board, true);
        $boards->setPublished($this->areaAdmin, $board, false);
        $boards->delete($this->areaAdmin, $board);

        $this->assertSame(
            ['leaderboard.created', 'leaderboard.updated', 'leaderboard.published', 'leaderboard.unpublished', 'leaderboard.deleted'],
            DB::table('topic_audit_events')->where('subject_type', 'leaderboard')->orderBy('id')->pluck('action')->all(),
        );
    }
}

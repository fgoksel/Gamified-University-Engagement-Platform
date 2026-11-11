<?php

namespace Tests\Feature\Leaderboards;

use App\Enums\UserRole;
use App\Models\Leaderboard;
use App\Models\Topic;
use App\Models\User;
use App\Services\AreaLeaderboards;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Events\EventTestCase;

/**
 * D6, 7 November: a person highlights about five leaderboards with a star, and the highlighted
 * ones are listed above the rest. The star is personal, it only works on a leaderboard the person
 * can open (D4), and the limit is kept by the server.
 */
class HighlightedLeaderboardTest extends EventTestCase
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

    /** A student who belongs to a topic, so the leaderboards of that area are open to them. */
    private function member(Topic $topic, ?string $name = null): User
    {
        $student = $this->student($name);
        $this->assign($student, $this->viewer(), $topic);

        return $student;
    }

    /**
     * @return list<string>
     */
    /** The boxes of "All leaderboards", in their order. */
    private function listed(User $user): array
    {
        return array_column($this->actingAs($user)->get('/leaderboards?show=all')->viewData('page')['props']['leaderboards'], 'name');
    }

    private function highlightCount(User $user): int
    {
        return DB::table('leaderboard_user')->where('user_id', $user->id)->count();
    }

    public function test_a_highlighted_leaderboard_is_listed_above_the_rest(): void
    {
        $anna = $this->member($this->faculty);
        $this->board($this->faculty, 'A board');
        $b = $this->board($this->course, 'B board');
        $c = $this->board($this->course, 'C board');

        // Nothing highlighted: by name
        $this->assertSame(['A board', 'B board', 'C board'], $this->listed($anna));

        // Nothing highlighted: "Highlighted" shows every board and a hint, never an empty page.
        $this->actingAs($anna)->get('/leaderboards')
            ->assertInertia(fn (Assert $page) => $page
                ->where('show', 'highlighted')
                ->where('hasHighlights', false)
                ->has('leaderboards', 3)
                ->where('selected.name', 'A board'));

        $this->actingAs($anna)->post("/leaderboards/{$c->id}/highlight")->assertRedirect();

        // "Highlighted" now holds the starred board alone, and selects it on opening.
        $this->actingAs($anna)->get('/leaderboards')
            ->assertInertia(fn (Assert $page) => $page
                ->where('hasHighlights', true)
                ->has('leaderboards', 1)
                ->where('leaderboards.0.name', 'C board')
                ->where('selected.name', 'C board'));

        $this->actingAs($anna)->get('/leaderboards?show=all')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Leaderboards/Index')
                ->where('show', 'all')
                ->where('selected.name', 'C board')
                ->where('highlightLimit', 5)
                ->where('leaderboards.0.name', 'C board')
                ->where('leaderboards.0.highlighted', true)
                ->where('leaderboards.1.name', 'A board')
                ->where('leaderboards.1.highlighted', false)
                ->where('leaderboards.2.name', 'B board')
                ->where('leaderboards.2.highlighted', false));

        // Two highlighted ones keep their name order among themselves
        $this->actingAs($anna)->post("/leaderboards/{$b->id}/highlight")->assertRedirect();
        $this->assertSame(['B board', 'C board', 'A board'], $this->listed($anna));

        $this->actingAs($anna)->delete("/leaderboards/{$c->id}/highlight")->assertRedirect();
        $this->assertSame(['B board', 'A board', 'C board'], $this->listed($anna));
    }

    public function test_the_start_page_of_a_teacher_lists_the_highlighted_ones_first_too(): void
    {
        $teacher = $this->makeUser(UserRole::Teacher);
        $this->assign($teacher, $this->viewer(), $this->faculty);
        $this->board($this->faculty, 'A board');
        $second = $this->board($this->course, 'B board');

        $this->actingAs($teacher)->post("/leaderboards/{$second->id}/highlight")->assertRedirect();

        $this->actingAs($teacher)->get('/')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Leaderboard/Index')
                ->where('leaderboards.0.name', 'B board')
                ->where('leaderboards.0.highlighted', true)
                ->where('leaderboards.1.name', 'A board'));
    }

    public function test_highlighting_twice_changes_nothing_and_removing_twice_neither(): void
    {
        $anna = $this->member($this->faculty);
        $board = $this->board($this->faculty, 'Faculty total');

        $this->actingAs($anna)->post("/leaderboards/{$board->id}/highlight")->assertRedirect();
        $this->actingAs($anna)->post("/leaderboards/{$board->id}/highlight")->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, $this->highlightCount($anna));

        $this->actingAs($anna)->delete("/leaderboards/{$board->id}/highlight")->assertRedirect();
        $this->actingAs($anna)->delete("/leaderboards/{$board->id}/highlight")->assertRedirect();
        $this->assertSame(0, $this->highlightCount($anna));
    }

    public function test_a_highlight_belongs_to_the_person_who_made_it(): void
    {
        $anna = $this->member($this->faculty, 'Anna Nagy');
        $bence = $this->member($this->faculty, 'Bence Toth');
        $board = $this->board($this->faculty, 'Faculty total');

        $this->actingAs($anna)->post("/leaderboards/{$board->id}/highlight")->assertRedirect();

        $this->assertSame(1, $this->highlightCount($anna));
        $this->assertSame(0, $this->highlightCount($bence));
        $this->actingAs($bence)->get('/leaderboards')
            ->assertInertia(fn (Assert $page) => $page->where('leaderboards.0.highlighted', false));

        // Bence removing "his" highlight does not touch Anna's
        $this->actingAs($bence)->delete("/leaderboards/{$board->id}/highlight")->assertRedirect();
        $this->assertSame(1, $this->highlightCount($anna));
    }

    public function test_the_limit_is_five_and_the_message_says_so(): void
    {
        $this->assertSame(5, Leaderboard::HIGHLIGHT_LIMIT);

        $anna = $this->member($this->faculty);
        $boards = collect(range(1, 6))->map(fn (int $n) => $this->board($this->course, "Board {$n}"));

        foreach ($boards->take(5) as $board) {
            $this->actingAs($anna)->post("/leaderboards/{$board->id}/highlight")->assertRedirect()->assertSessionHasNoErrors();
        }

        $sixth = $boards->last();
        $this->actingAs($anna)->post("/leaderboards/{$sixth->id}/highlight")
            ->assertRedirect()
            ->assertSessionHasErrors(['highlight' => 'You can highlight up to 5 leaderboards. Remove a highlight first.']);
        $this->assertSame(5, $this->highlightCount($anna));

        // Highlighting one that is already highlighted is no sixth, so it is not refused
        $this->actingAs($anna)->post("/leaderboards/{$boards->first()->id}/highlight")->assertSessionHasNoErrors();

        // Room again once one is removed
        $this->actingAs($anna)->delete("/leaderboards/{$boards->first()->id}/highlight")->assertRedirect();
        $this->actingAs($anna)->post("/leaderboards/{$sixth->id}/highlight")->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(5, $this->highlightCount($anna));
    }

    public function test_the_limit_is_the_same_for_everyone_and_counted_for_each_person(): void
    {
        $anna = $this->member($this->faculty, 'Anna Nagy');
        $bence = $this->member($this->faculty, 'Bence Toth');
        $boards = collect(range(1, 6))->map(fn (int $n) => $this->board($this->course, "Board {$n}"));

        foreach ($boards->take(5) as $board) {
            $this->actingAs($anna)->post("/leaderboards/{$board->id}/highlight");
        }

        // Anna is full; Bence is not
        $this->actingAs($bence)->post("/leaderboards/{$boards->last()->id}/highlight")->assertSessionHasNoErrors();
        $this->assertSame(1, $this->highlightCount($bence));
    }

    public function test_a_leaderboard_the_person_cannot_open_cannot_be_highlighted(): void
    {
        $outsider = $this->member($this->sport, 'Sport Student');
        $board = $this->board($this->faculty, 'Faculty total', ['visibility' => Leaderboard::PUBLIC]);

        // Not allowed looks exactly like not existing, published or not (D4)
        $this->actingAs($outsider)->post("/leaderboards/{$board->id}/highlight")->assertNotFound();
        $this->actingAs($outsider)->delete("/leaderboards/{$board->id}/highlight")->assertNotFound();
        $this->assertSame(0, $this->highlightCount($outsider));
    }

    public function test_a_highlight_that_has_left_the_view_neither_shows_nor_counts(): void
    {
        $anna = $this->member($this->faculty);
        $gone = $this->board($this->sport, 'Sport total');
        $boards = collect(range(1, 5))->map(fn (int $n) => $this->board($this->course, "Board {$n}"));

        // A leftover from an area that is no longer hers
        DB::table('leaderboard_user')->insert(['leaderboard_id' => $gone->id, 'user_id' => $anna->id, 'created_at' => now(), 'updated_at' => now()]);

        foreach ($boards as $board) {
            $this->actingAs($anna)->post("/leaderboards/{$board->id}/highlight")->assertRedirect()->assertSessionHasNoErrors();
        }

        $this->assertSame(['Board 1', 'Board 2', 'Board 3', 'Board 4', 'Board 5'], $this->listed($anna));

        $boards = app(AreaLeaderboards::class);
        $this->assertNotContains($gone->id, $boards->highlightedIn($anna, $boards->openTo($anna)));
    }

    public function test_the_ranking_says_whether_its_board_is_highlighted(): void
    {
        $anna = $this->member($this->faculty);
        $board = $this->board($this->faculty, 'Faculty total');

        $this->actingAs($anna)->get("/leaderboards?board={$board->id}")
            ->assertInertia(fn (Assert $page) => $page->component('Leaderboards/Index')->where('selected.highlighted', false));

        $this->actingAs($anna)->post("/leaderboards/{$board->id}/highlight");

        $this->actingAs($anna)->get("/leaderboards?board={$board->id}")
            ->assertInertia(fn (Assert $page) => $page->where('selected.highlighted', true));
    }

    public function test_deleting_a_leaderboard_takes_its_highlights_with_it(): void
    {
        $anna = $this->member($this->faculty);
        $board = $this->board($this->faculty, 'Faculty total');
        $this->actingAs($anna)->post("/leaderboards/{$board->id}/highlight");
        $this->assertSame(1, $this->highlightCount($anna));

        app(AreaLeaderboards::class)->delete($this->admin, $board);

        $this->assertSame(0, $this->highlightCount($anna));
    }

    public function test_a_highlight_needs_a_login(): void
    {
        $board = $this->board($this->faculty, 'Faculty total');

        $this->post("/leaderboards/{$board->id}/highlight")->assertRedirect('/login');
        $this->delete("/leaderboards/{$board->id}/highlight")->assertRedirect('/login');
        $this->assertSame(0, DB::table('leaderboard_user')->count());
    }
}

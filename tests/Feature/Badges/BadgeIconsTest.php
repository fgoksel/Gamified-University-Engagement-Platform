<?php

namespace Tests\Feature\Badges;

use App\Enums\Capability;
use App\Models\Badge;
use App\Models\BadgeAward;
use App\Models\Event;
use App\Models\Leaderboard;
use App\Models\PointTransaction;
use App\Models\Topic;
use App\Models\User;
use App\Services\BadgeAwards;
use App\Services\LeaderboardStandings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Events\EventTestCase;

/**
 * Task #125 (N10): a badge has an icon the organiser picks, and a student's badges from a board's
 * events show beside their row on the leaderboards and the hall screen. A badge never changes a
 * point, a place or the order.
 */
class BadgeIconsTest extends EventTestCase
{
    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = $this->staff([Capability::EventsCreate, Capability::EventsManage, Capability::PointsCredit], null, 'Mira Manager');
    }

    private function board(Topic $topic, array $data = []): Leaderboard
    {
        return Leaderboard::create([
            'topic_id' => $topic->id,
            'name' => 'Board '.$topic->title,
            'visibility' => Leaderboard::PUBLIC,
            'created_by_id' => $this->admin->id,
            ...$data,
        ])->fresh();
    }

    private function credit(User $student, Event $event, int $points): void
    {
        PointTransaction::create([
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

    private function badge(Event $event, string $name, ?string $icon = null): Badge
    {
        return Badge::create(['event_id' => $event->id, 'name' => $name, 'icon' => $icon, 'created_by_id' => $this->manager->id]);
    }

    private function award(Badge $badge, User $student, string $at = 'now'): void
    {
        BadgeAward::create(['badge_id' => $badge->id, 'student_id' => $student->id, 'awarded_at' => now()->modify($at)]);
    }

    private function standings(): LeaderboardStandings
    {
        return app(LeaderboardStandings::class);
    }

    // ─── Choosing the icon ────────────────────────────────────────────────────

    public function test_an_icon_is_chosen_and_saved_and_the_card_offers_the_list(): void
    {
        $this->actingAs($this->manager)->put("/events/{$this->event->id}/badge", ['name' => 'SQL Night', 'icon' => 'flask-conical'])
            ->assertSessionHasNoErrors();

        $this->assertSame('flask-conical', Badge::query()->where('event_id', $this->event->id)->value('icon'));

        // Saving without an icon keeps the chosen one.
        $this->actingAs($this->manager)->put("/events/{$this->event->id}/badge", ['name' => 'SQL Night 2'])
            ->assertSessionHasNoErrors();
        $this->assertSame('flask-conical', Badge::query()->where('event_id', $this->event->id)->value('icon'));

        $this->actingAs($this->manager)->get("/events/{$this->event->id}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('badge.icon', 'flask-conical')
                ->where('badgeIcons', Badge::ICONS));
    }

    public function test_an_unknown_icon_is_refused(): void
    {
        $this->actingAs($this->manager)->put("/events/{$this->event->id}/badge", ['name' => 'SQL Night', 'icon' => 'skull'])
            ->assertSessionHasErrors(['icon' => 'Choose one of the icons.']);

        $this->assertNull(Badge::query()->where('event_id', $this->event->id)->first());
    }

    public function test_a_badge_without_an_icon_shows_the_award(): void
    {
        $student = $this->student('Anna Nagy', 'ANNA01');
        $this->award($this->badge($this->event, 'Old badge'), $student);

        $this->assertSame('award', app(BadgeAwards::class)->heldBy($student)[0]['icon']);

        // A new badge made without choosing gets the award stored.
        $this->actingAs($this->manager)->put("/events/{$this->makeEvent()->id}/badge", ['name' => 'New'])->assertSessionHasNoErrors();
        $this->assertSame('award', Badge::query()->where('name', 'New')->value('icon'));
    }

    // ─── Badges on leaderboards ───────────────────────────────────────────────

    public function test_a_row_carries_the_badges_of_the_boards_events_only(): void
    {
        $sport = $this->topic('Sport', $this->university);
        $sportEvent = $this->makeEvent(['owning_topic_id' => $sport->id, 'audience_topic_id' => $sport->id]);
        $student = $this->student('Anna Nagy', 'ANNA01');

        $this->credit($student, $this->event, 10);
        $this->credit($student, $sportEvent, 5);
        $this->award($this->badge($this->event, 'SQL Night', 'code'), $student, '-1 day');
        $this->award($this->badge($sportEvent, 'Marathon', 'dumbbell'), $student);

        // The faculty board counts the course's event, not the sport one.
        $view = $this->standings()->view($this->board($this->faculty), $this->manager);
        $this->assertSame([['name' => 'SQL Night', 'icon' => 'code']], $view['top'][0]['badges']);

        // The university board counts both: newest first.
        $view = $this->standings()->view($this->board($this->university), $this->manager);
        $this->assertSame([['name' => 'Marathon', 'icon' => 'dumbbell'], ['name' => 'SQL Night', 'icon' => 'code']], $view['top'][0]['badges']);
    }

    public function test_the_leaderboards_page_sends_the_badges_with_the_rows(): void
    {
        $student = $this->student('Anna Nagy', 'ANNA01');
        $this->assign($student, $this->studentRole(), $this->course);
        $this->credit($student, $this->event, 10);
        $this->award($this->badge($this->event, 'SQL Night', 'star'), $student);
        $board = $this->board($this->course);

        $this->actingAs($student)->get("/leaderboards?board={$board->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('selected.standings.top.0.badges', [['name' => 'SQL Night', 'icon' => 'star']]));
    }

    public function test_the_hall_feed_rows_carry_badges_also_with_names_off(): void
    {
        $student = $this->student('Anna Nagy', 'ANNA01');
        $this->credit($student, $this->event, 20);
        $this->award($this->badge($this->event, 'SQL Night', 'rocket'), $student);
        $board = $this->board($this->faculty, ['show_first_name' => true, 'show_last_name' => true]);
        $this->assertFalse($board->hall_show_names);

        $response = $this->getJson('/hall/leaderboards')->assertOk()
            ->assertJsonPath('leaderboards.0.columns', ['neptun_code'])
            ->assertJsonPath('leaderboards.0.rows.0', [
                'position' => 1, 'rank' => 1, 'points' => 20, 'neptun_code' => 'ANNA01',
                'badges' => [['name' => 'SQL Night', 'icon' => 'rocket']],
            ]);

        // Names off still means no name, badges or not.
        $this->assertStringNotContainsString('Nagy', $response->getContent());
    }

    public function test_ranks_positions_and_points_are_the_same_with_and_without_badges(): void
    {
        $board = $this->board($this->faculty);
        $students = collect([30, 20, 20, 10])->map(function (int $points, int $index) {
            $student = $this->student("Student {$index}", sprintf('STU%03d', $index));
            $this->credit($student, $this->event, $points);

            return $student;
        });

        $strip = fn (array $view) => collect($view['top'])->map(fn (array $row) => collect($row)->except('badges')->all())->all();
        $before = $this->standings()->view($board, $this->manager);

        // The last one gets badges: a badge must not lift them.
        $badge = $this->badge($this->event, 'SQL Night');
        $this->award($badge, $students[3]);
        $this->award($this->badge($this->makeEvent(), 'Second'), $students[3]);
        Cache::flush();

        $after = $this->standings()->view($board, $this->manager);

        $this->assertSame($strip($before), $strip($after));
        $this->assertSame([[1, 1, 30], [2, 2, 20], [3, 2, 20], [4, 4, 10]], collect($after['top'])->map(fn (array $row) => [$row['position'], $row['rank'], $row['points']])->all());
        $this->assertCount(2, $after['top'][3]['badges']);
        $this->assertSame($before['total'], $after['total']);
    }

    public function test_the_number_of_queries_does_not_grow_with_the_rows(): void
    {
        $count = function (int $students): int {
            DB::table('badge_awards')->delete();
            DB::table('point_transactions')->delete();
            $board = $this->board($this->faculty, ['name' => "Board {$students}"]);
            $badge = Badge::query()->firstOrCreate(['event_id' => $this->event->id], ['name' => 'SQL Night', 'icon' => 'star', 'created_by_id' => $this->manager->id]);

            for ($i = 0; $i < $students; $i++) {
                $student = $this->student("S{$students}-{$i}", sprintf('N%02d%03d', $students, $i));
                $this->credit($student, $this->event, 10 + $i);
                $this->award($badge, $student);
            }

            Cache::flush();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $view = $this->standings()->view($board, $this->manager, everyone: true);
            DB::disableQueryLog();

            $this->assertCount($students, $view['top']);
            $this->assertTrue(collect($view['top'])->every(fn (array $row) => count($row['badges']) === 1));

            return count(DB::getQueryLog());
        };

        // A first run loads what is looked up once per request (the category list), so the two
        // measured runs differ only in their number of rows.
        $count(1);
        $this->assertSame($count(3), $count(12));
    }
}

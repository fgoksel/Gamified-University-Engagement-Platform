<?php

namespace Tests\Feature\DataProtection;

use App\Enums\Capability;
use App\Models\Leaderboard;
use App\Models\PointTransaction;
use App\Models\User;
use Tests\Feature\Events\EventTestCase;

/**
 * Task #62 (GDPR, decisions D2 and N13): a leaderboard is anonymous by default. It shows a Neptun
 * code and never a name, and a name appears only where the creator of the leaderboard switched it on.
 * The guest hall screen needs the system admin's switch as well.
 *
 * HallScreenTest and AreaLeaderboardTest check the rules one screen at a time. This asks the
 * question the other way round: with the names off, does a name, an address or an account number
 * of a student reach ANY screen that lists leaderboards, for any kind of person who may open it?
 */
class LeaderboardAnonymityTest extends EventTestCase
{
    private const FIRST = 'Quixote';

    private const LAST = 'Zephyrine';

    private const EMAIL = 'quixote.zephyrine@example.test';

    private const NEPTUN = 'QZ0001';

    private User $star;

    private User $other;

    private User $areaAdmin;

    private Leaderboard $board;

    protected function setUp(): void
    {
        parent::setUp();

        // The student whose identity must not leave, with points, so they are on the board.
        $this->star = $this->student(self::FIRST.' '.self::LAST, self::NEPTUN);
        $this->star->forceFill(['email' => self::EMAIL])->save();
        $this->assign($this->star, $this->studentRole(), $this->faculty);
        $this->credit($this->star, 50);

        // Another student of the area, who asks for the pages, and an admin of the area.
        $this->other = $this->student('Viola Marsh', 'VM0002');
        $this->assign($this->other, $this->studentRole(), $this->faculty);
        $this->credit($this->other, 20);

        $this->areaAdmin = $this->staff([Capability::LeaderboardsManage], $this->faculty, 'Area Admin');

        // Created with nothing ticked but what the form starts with: a Neptun code and no names.
        $this->board = Leaderboard::create([
            'topic_id' => $this->faculty->id,
            'name' => 'Faculty total',
            'visibility' => Leaderboard::PUBLIC,
            'created_by_id' => $this->admin->id,
        ])->fresh();
    }

    private function credit(User $student, int $points): void
    {
        $event = $this->makeEvent(['points_value' => $points]);

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

    /**
     * Every address that lists leaderboards, for each kind of person who may open it.
     *
     * @return list<array{0: string, 1: ?User}> the address, and who asks (null: a guest)
     */
    private function screens(): array
    {
        $board = $this->board->id;
        $pages = ['/leaderboards', "/leaderboards?board={$board}", "/leaderboards?board={$board}&places=all", '/leaderboards?show=all'];

        return [
            // A guest sees the hall screen and the feed it reads.
            ['/hall', null],
            ['/hall/leaderboards', null],
            // A student: the home page, the leaderboards, and "Where am I?" for the area.
            ['/', $this->other],
            ...array_map(fn (string $url) => [$url, $this->other], $pages),
            ["/leaderboards?area={$this->faculty->id}", $this->other],
            // The admin of the area.
            ['/', $this->areaAdmin],
            ...array_map(fn (string $url) => [$url, $this->areaAdmin], $pages),
            // The system admin, also the page where the hall screen is set.
            ...array_map(fn (string $url) => [$url, $this->admin], $pages),
            ['/admin/hall-screen', $this->admin],
        ];
    }

    private function body(string $url, ?User $asks): string
    {
        $this->app['auth']->forgetGuards();
        $asks === null || $this->actingAs($asks);

        $response = $this->get($url, ['Accept' => str_starts_with($url, '/hall/') ? 'application/json' : 'text/html'])->assertOk();

        return (string) $response->getContent();
    }

    public function test_a_leaderboard_starts_with_a_neptun_code_and_no_name(): void
    {
        $this->assertSame(['neptun_code'], $this->board->columns());
        $this->assertSame(['neptun_code'], $this->board->hallColumns());
        $this->assertFalse($this->board->hall_show_names);
    }

    public function test_with_the_names_off_no_screen_that_lists_leaderboards_shows_a_name_an_address_or_an_account_number(): void
    {
        foreach ($this->screens() as [$url, $asks]) {
            $body = $this->body($url, $asks);
            $who = $asks?->name ?? 'a guest';

            // An Inertia page holds its data with the quotes escaped, so the key is looked for without them.
            foreach ([self::FIRST, self::LAST, self::EMAIL, 'student_id'] as $secret) {
                $this->assertStringNotContainsString($secret, $body, "{$url} shows {$secret} to {$who}.");
            }
        }
    }

    public function test_the_screens_are_not_empty_so_the_search_above_means_something(): void
    {
        // The Neptun code is what a board shows, to everyone who may see the board.
        foreach ([['/hall/leaderboards', null], ["/leaderboards?board={$this->board->id}&places=all", $this->other], ["/leaderboards?board={$this->board->id}&places=all", $this->areaAdmin]] as [$url, $asks]) {
            $this->assertStringContainsString(self::NEPTUN, $this->body($url, $asks), $url);
        }
    }

    public function test_where_am_i_gives_a_student_only_their_own_numbers(): void
    {
        $props = $this->actingAs($this->other)->get("/leaderboards?area={$this->faculty->id}")->assertOk()->viewData('page')['props'];
        $explorer = json_encode($props['rankings']);

        $this->assertNotEmpty($props['rankings']);
        $this->assertStringNotContainsString(self::NEPTUN, $explorer);
        $this->assertStringNotContainsString(self::FIRST, $explorer);
    }

    public function test_the_names_the_creator_chose_show_to_people_who_sign_in(): void
    {
        $this->board->update(['show_first_name' => true, 'show_last_name' => true]);

        foreach ([$this->other, $this->areaAdmin, $this->admin] as $viewer) {
            $selected = json_encode($this->actingAs($viewer)->get("/leaderboards?board={$this->board->id}&places=all")->viewData('page')['props']['selected']);

            $this->assertStringContainsString(self::FIRST, $selected, $viewer->name);
            $this->assertStringContainsString(self::LAST, $selected, $viewer->name);
            // A name was chosen. The address and the account number never are.
            $this->assertStringNotContainsString(self::EMAIL, $selected, $viewer->name);
            $this->assertStringNotContainsString('student_id', $selected, $viewer->name);
        }
    }

    public function test_the_hall_screen_gets_a_name_only_when_the_creator_and_the_system_admin_both_agree(): void
    {
        $feed = fn () => $this->body('/hall/leaderboards', null);

        // The creator chose names, the system admin did not: a guest still sees the Neptun code alone.
        $this->board->update(['show_first_name' => true, 'show_last_name' => true]);
        $this->assertStringNotContainsString(self::FIRST, $feed());
        $this->assertStringContainsString(self::NEPTUN, $feed());

        // The system admin switches names on, but the creator did not choose names: still none.
        $this->board->update(['show_first_name' => false, 'show_last_name' => false, 'hall_show_names' => true]);
        $this->assertStringNotContainsString(self::FIRST, $feed());

        // Both agree: the names the creator chose, and nothing else.
        $this->board->update(['show_first_name' => true, 'show_last_name' => false]);
        $both = $feed();
        $this->assertStringContainsString(self::FIRST, $both);
        $this->assertStringNotContainsString(self::LAST, $both);
        $this->assertStringNotContainsString(self::EMAIL, $both);
    }
}

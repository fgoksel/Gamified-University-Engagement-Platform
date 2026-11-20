<?php

namespace Tests\Feature\Leaderboards;

use App\Enums\Capability;
use App\Enums\UserRole;
use App\Filament\Pages\HallScreen;
use App\Models\Leaderboard;
use App\Models\PointTransaction;
use App\Models\Setting;
use App\Models\Topic;
use App\Models\TopicAuditEvent;
use App\Models\User;
use App\Services\AreaLeaderboards;
use App\Services\TopicService;
use Illuminate\Auth\Access\AuthorizationException;
use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Livewire;
use Tests\Feature\Events\EventTestCase;

/**
 * Task #56: the hall screen rotates the published leaderboards on a public display, without login.
 * Task #118 (N13): guests always see Neptun codes, and names only where the system admin switched them on.
 */
class HallScreenTest extends EventTestCase
{
    private ?User $areaAdmin = null;

    /**
     * @param  array<string, mixed>  $data
     */
    private function board(Topic $topic, string $name, array $data = []): Leaderboard
    {
        return Leaderboard::create([
            'topic_id' => $topic->id,
            'name' => $name,
            'visibility' => Leaderboard::PUBLIC,
            'created_by_id' => $this->admin->id,
            ...$data,
        ])->fresh();
    }

    public function test_the_page_opens_without_login(): void
    {
        $this->assertGuest();

        $this->get('/hall')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Screen/Hall'));
    }

    public function test_the_feed_lists_only_published_boards_of_areas_not_archived(): void
    {
        $this->board($this->faculty, 'Published');
        $this->board($this->faculty, 'Area only', ['visibility' => Leaderboard::AREA]);

        $archived = $this->topic('Old area', $this->university);
        $this->board($archived, 'In an archived area');
        app(TopicService::class)->archive($this->admin, $archived);

        $this->getJson('/hall/leaderboards')
            ->assertOk()
            ->assertJsonCount(1, 'leaderboards')
            ->assertJsonPath('leaderboards.0.name', 'Published');
    }

    public function test_the_feed_says_how_long_each_board_stays(): void
    {
        $this->board($this->faculty, 'Published');

        $this->getJson('/hall/leaderboards')
            ->assertJsonPath('leaderboards.0.seconds', Leaderboard::HALL_SECONDS);
    }

    public function test_an_empty_time_uses_the_default(): void
    {
        $this->board($this->faculty, 'Published');
        Setting::setHallSeconds(40);

        $this->getJson('/hall/leaderboards')->assertJsonPath('leaderboards.0.seconds', 40);
    }

    public function test_a_time_of_its_own_wins_over_the_default(): void
    {
        $this->board($this->faculty, 'Published', ['hall_seconds' => 25]);
        Setting::setHallSeconds(40);

        $this->getJson('/hall/leaderboards')->assertJsonPath('leaderboards.0.seconds', 25);
    }

    public function test_an_area_admin_sets_the_time_of_a_leaderboard_they_manage(): void
    {
        $board = $this->board($this->faculty, 'Published');

        $this->actingAs($this->areaAdmin())
            ->put("/leaderboards/{$board->id}", ['name' => 'Published', 'hall_seconds' => 30])
            ->assertSessionHasNoErrors();
        $this->assertSame(30, $board->fresh()->hall_seconds);

        $this->actingAs($this->areaAdmin())
            ->put("/leaderboards/{$board->id}", ['name' => 'Published', 'hall_seconds' => ''])
            ->assertSessionHasNoErrors();
        $this->assertNull($board->fresh()->hall_seconds);
    }

    public function test_saving_without_the_time_leaves_it_alone(): void
    {
        $board = $this->board($this->faculty, 'Published', ['hall_seconds' => 30]);

        $this->actingAs($this->areaAdmin())
            ->put("/leaderboards/{$board->id}", ['name' => 'Renamed'])
            ->assertSessionHasNoErrors();

        $this->assertSame(30, $board->fresh()->hall_seconds);
    }

    public function test_a_time_out_of_range_is_refused_in_plain_words(): void
    {
        $board = $this->board($this->faculty, 'Published');

        foreach ([4, 121, 'soon'] as $seconds) {
            $this->actingAs($this->areaAdmin())
                ->put("/leaderboards/{$board->id}", ['name' => 'Published', 'hall_seconds' => $seconds])
                ->assertSessionHasErrors(['hall_seconds' => Leaderboard::hallSecondsMessage()]);
        }

        $this->assertNull($board->fresh()->hall_seconds);
    }

    public function test_an_area_admin_cannot_change_a_leaderboard_they_do_not_manage(): void
    {
        $board = $this->board($this->topic('Sport', $this->university), 'Elsewhere');

        $this->actingAs($this->areaAdmin())
            ->put("/leaderboards/{$board->id}", ['name' => 'Elsewhere', 'hall_seconds' => 30])
            ->assertNotFound();

        $this->assertNull($board->fresh()->hall_seconds);
    }

    public function test_the_dialog_gets_the_default_on_the_leaderboards_tab(): void
    {
        Setting::setHallSeconds(40);

        $this->actingAs($this->areaAdmin())->get("/topics/{$this->faculty->id}?tab=leaderboards")
            ->assertInertia(fn (Assert $page) => $page->where('selected.hall_default', 40));
    }

    public function test_the_super_admin_changes_the_default_and_any_leaderboards_time(): void
    {
        $board = $this->board($this->topic('Sport', $this->university), 'Elsewhere');

        Livewire::actingAs($this->admin)
            ->test(HallScreen::class)
            ->callAction('default', ['hall_seconds' => 45])
            ->assertHasNoActionErrors();
        $this->assertSame(45, Setting::hallSeconds());
        $this->assertSame(1, Setting::count());

        Livewire::actingAs($this->admin)
            ->test(HallScreen::class)
            ->callTableAction('editTime', $board, ['hall_seconds' => 20])
            ->assertHasNoTableActionErrors();
        $this->assertSame(20, $board->fresh()->hall_seconds);
    }

    public function test_the_default_must_be_in_range(): void
    {
        Livewire::actingAs($this->admin)
            ->test(HallScreen::class)
            ->callAction('default', ['hall_seconds' => 3])
            ->assertHasActionErrors(['hall_seconds']);

        $this->assertSame(Leaderboard::HALL_SECONDS, Setting::hallSeconds());
    }

    public function test_only_the_super_admin_opens_the_filament_page(): void
    {
        // People who are refused go home (bootstrap/app.php).
        $this->actingAs($this->areaAdmin())->get('/admin/hall-screen')->assertRedirect('/');
        $this->actingAs($this->admin)->get('/admin/hall-screen')->assertOk();
    }

    public function test_the_name_of_a_leaderboard_opens_it_in_a_new_tab(): void
    {
        $board = $this->board($this->faculty, 'Open me', ['visibility' => Leaderboard::AREA]);

        $html = Livewire::actingAs($this->admin)->test(HallScreen::class)->html();

        $href = preg_quote('/leaderboards?board='.$board->id, '#');
        $this->assertMatchesRegularExpression('#<a[^>]*href="'.$href.'"[^>]*target="_blank"|<a[^>]*target="_blank"[^>]*href="'.$href.'"#', $html);

        $this->actingAs($this->admin)->get("/leaderboards?board={$board->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('selected.name', 'Open me'));
    }

    public function test_the_system_admin_switches_a_board_on_and_off(): void
    {
        $board = $this->board($this->faculty, 'Chosen', ['visibility' => Leaderboard::AREA]);
        $this->getJson('/hall/leaderboards')->assertJsonCount(0, 'leaderboards');

        Livewire::actingAs($this->admin)->test(HallScreen::class)
            ->call('updateTableColumnState', 'on_hall_screen', (string) $board->id, true);
        $this->getJson('/hall/leaderboards')->assertJsonPath('leaderboards.0.name', 'Chosen');

        Livewire::actingAs($this->admin)->test(HallScreen::class)
            ->call('updateTableColumnState', 'on_hall_screen', (string) $board->id, false);
        $this->getJson('/hall/leaderboards')->assertJsonCount(0, 'leaderboards');
    }

    public function test_an_area_admin_can_no_longer_publish(): void
    {
        $board = $this->board($this->faculty, 'Not chosen', ['visibility' => Leaderboard::AREA]);

        $this->actingAs($this->areaAdmin())->post("/leaderboards/{$board->id}/publish")->assertNotFound();

        $this->assertFalse($board->fresh()->isPublic());
    }

    public function test_the_hall_screen_sends_no_name_until_the_system_admin_switches_names_on(): void
    {
        $this->credit($this->student('Anna Maria Nagy', 'ANNA01'), 20);
        $board = $this->board($this->faculty, 'Names', ['show_first_name' => true, 'show_last_name' => true]);

        // A board that has names still sends none: the switch is off by default (agreed on 10 November).
        $this->assertFalse($board->hall_show_names);

        $response = $this->getJson('/hall/leaderboards')->assertOk()
            ->assertJsonPath('leaderboards.0.columns', ['neptun_code'])
            ->assertJsonPath('leaderboards.0.rows.0', ['position' => 1, 'rank' => 1, 'points' => 20, 'neptun_code' => 'ANNA01']);

        $this->assertStringNotContainsString('Anna', $response->getContent());
        $this->assertStringNotContainsString('Nagy', $response->getContent());
    }

    public function test_the_system_admin_switches_the_names_of_one_board_on_and_off(): void
    {
        $this->credit($this->student('Anna Maria Nagy', 'ANNA01'), 20);
        $board = $this->board($this->faculty, 'Names', ['show_first_name' => true, 'show_last_name' => true]);
        $other = $this->board($this->faculty, 'Other names', ['show_first_name' => true]);

        Livewire::actingAs($this->admin)->test(HallScreen::class)
            ->call('updateTableColumnState', 'hall_show_names', (string) $board->id, true);

        $feed = collect($this->getJson('/hall/leaderboards')->json('leaderboards'))->keyBy('name');
        $this->assertSame(['neptun_code', 'first_name', 'last_name'], $feed['Names']['columns']);
        $this->assertSame('Anna Maria', $feed['Names']['rows'][0]['first_name']);
        $this->assertSame('Nagy', $feed['Names']['rows'][0]['last_name']);
        // The switch belongs to one board: the other one still sends no name.
        $this->assertSame(['neptun_code'], $feed['Other names']['columns']);
        $this->assertArrayNotHasKey('first_name', $feed['Other names']['rows'][0]);

        Livewire::actingAs($this->admin)->test(HallScreen::class)
            ->call('updateTableColumnState', 'hall_show_names', (string) $board->id, false);

        $this->getJson('/hall/leaderboards')->assertOk()
            ->assertJsonPath('leaderboards.0.columns', ['neptun_code'])
            ->assertJsonMissingPath('leaderboards.0.rows.0.first_name');
    }

    public function test_the_switch_never_adds_a_name_the_board_does_not_have(): void
    {
        $this->credit($this->student('Anna Maria Nagy', 'ANNA01'), 20);
        $firstOnly = $this->board($this->faculty, 'First only', ['show_first_name' => true, 'hall_show_names' => true]);
        $none = $this->board($this->faculty, 'No names', ['hall_show_names' => true]);

        $feed = collect($this->getJson('/hall/leaderboards')->json('leaderboards'))->keyBy('name');

        $this->assertSame(['neptun_code', 'first_name'], $feed['First only']['columns']);
        $this->assertArrayNotHasKey('last_name', $feed['First only']['rows'][0]);
        $this->assertSame(['neptun_code'], $feed['No names']['columns']);
        $this->assertSame($firstOnly->id, $feed['First only']['id']);
        $this->assertSame($none->id, $feed['No names']['id']);
    }

    public function test_a_neptun_code_always_shows_on_the_hall_screen(): void
    {
        $this->credit($this->student('Anna Maria Nagy', 'ANNA01'), 20);
        // The creator ticked no Neptun code, and the switch is off: guests still see who is on the board.
        $this->board($this->faculty, 'No code ticked', ['show_neptun_code' => false]);

        $this->getJson('/hall/leaderboards')->assertOk()
            ->assertJsonPath('leaderboards.0.columns', ['neptun_code'])
            ->assertJsonPath('leaderboards.0.rows.0.neptun_code', 'ANNA01');
    }

    public function test_the_switch_changes_only_the_hall_screen_not_the_leaderboard_for_people_who_sign_in(): void
    {
        $this->credit($this->student('Anna Maria Nagy', 'ANNA01'), 20);
        $board = $this->board($this->faculty, 'Names', ['show_first_name' => true, 'show_last_name' => true]);

        foreach ([false, true] as $hallNames) {
            $board->update(['hall_show_names' => $hallNames]);

            $ranking = json_encode($this->actingAs($this->areaAdmin())->get("/leaderboards?board={$board->id}")->viewData('page')['props']['selected']);

            // The names its area admin chose stay, whatever the hall screen gets.
            $this->assertStringContainsString('ANNA01', $ranking);
            $this->assertStringContainsString('Anna Maria', $ranking);
            $this->assertStringContainsString('Nagy', $ranking);
        }
    }

    public function test_only_the_system_admin_can_change_the_switch(): void
    {
        $board = $this->board($this->faculty, 'Names', ['show_first_name' => true]);

        try {
            app(AreaLeaderboards::class)->setHallNames($this->areaAdmin(), $board, true);
            $this->fail('An area admin changed what guests see.');
        } catch (AuthorizationException $e) {
            $this->assertSame(__('leaderboards.hall_names.only_the_system_admin'), $e->getMessage());
        }

        // The edit form of an area admin cannot carry it, and the service ignores it too.
        $this->actingAs($this->areaAdmin())
            ->put("/leaderboards/{$board->id}", ['name' => 'Names', 'show_first_name' => true, 'hall_show_names' => true])
            ->assertSessionHasNoErrors();
        app(AreaLeaderboards::class)->update($this->areaAdmin(), $board->fresh(), ['hall_show_names' => true]);

        $this->assertFalse($board->fresh()->hall_show_names);
        $this->assertSame(0, TopicAuditEvent::where('action', 'like', 'leaderboard.hall_names%')->count());
    }

    public function test_changing_the_switch_is_in_the_audit_trail_and_only_real_changes_count(): void
    {
        $board = $this->board($this->faculty, 'Names', ['show_first_name' => true]);
        $service = app(AreaLeaderboards::class);

        $service->setHallNames($this->admin, $board, true);
        $service->setHallNames($this->admin, $board->fresh(), true);
        $service->setHallNames($this->admin, $board->fresh(), false);

        $events = TopicAuditEvent::where('action', 'like', 'leaderboard.hall_names%')->orderBy('id')->get();

        $this->assertSame(['leaderboard.hall_names_shown', 'leaderboard.hall_names_hidden'], $events->pluck('action')->all());
        $this->assertSame($this->admin->id, $events[0]->actor_id);
        $this->assertFalse($events[0]->before['hall_show_names']);
        $this->assertTrue($events[0]->after['hall_show_names']);
    }

    public function test_the_admin_page_says_what_each_board_shows_and_what_guests_get(): void
    {
        $this->board($this->faculty, 'Both', ['show_first_name' => true, 'show_last_name' => true]);
        $this->board($this->faculty, 'Neptun only');

        Livewire::actingAs($this->admin)->test(HallScreen::class)
            ->assertSee(__('admin.hall_screen.names_for_guests'))
            ->assertSee(__('admin.hall_screen.board_names', ['names' => __('admin.hall_screen.names_both')]))
            ->assertSee(__('admin.hall_screen.board_names', ['names' => __('admin.hall_screen.names_none')]))
            ->assertSee(__('admin.hall_screen.names_help'));
    }

    private function credit(User $student, int $points): PointTransaction
    {
        $event = $this->makeEvent(['points_value' => $points]);

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

    private function areaAdmin(): User
    {
        if ($this->areaAdmin === null) {
            $this->areaAdmin = $this->makeUser(UserRole::Teacher, 'Area Admin');
            $this->assign($this->areaAdmin, $this->role('Area admin', [Capability::View, Capability::LeaderboardsManage]), $this->faculty);
        }

        return $this->areaAdmin;
    }
}

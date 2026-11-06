<?php

namespace Tests\Feature\Leaderboards;

use App\Enums\Capability;
use App\Enums\UserRole;
use App\Filament\Pages\HallScreen;
use App\Models\Leaderboard;
use App\Models\Setting;
use App\Models\Topic;
use App\Models\User;
use App\Services\TopicService;
use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Livewire;
use Tests\Feature\Events\EventTestCase;

/**
 * Task #56: the hall screen rotates the published leaderboards on a public display, without login.
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

    public function test_the_publish_step_warns_that_the_leaderboard_goes_public(): void
    {
        $this->assertSame('Published leaderboards are shown on the hall screen and to anyone without signing in.', __('leaderboards.publish_warning'));
        $this->assertStringContainsString('leaderboards.publish_warning', file_get_contents(resource_path('js/leaderboardWords.js')));
        $this->assertStringContainsString('{{ publishWarning }}', file_get_contents(resource_path('js/Components/Topics/LeaderboardsPanel.vue')));
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

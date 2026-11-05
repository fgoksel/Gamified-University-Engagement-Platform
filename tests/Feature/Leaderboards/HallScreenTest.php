<?php

namespace Tests\Feature\Leaderboards;

use App\Models\Leaderboard;
use App\Models\Topic;
use App\Services\TopicService;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Events\EventTestCase;

/**
 * Task #56: the hall screen rotates the published leaderboards on a public display, without login.
 */
class HallScreenTest extends EventTestCase
{
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
}

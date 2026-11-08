<?php

namespace Tests\Feature\Leaderboards;

use App\Enums\Capability;
use App\Enums\UserRole;
use App\Models\PointTransaction;
use App\Models\Topic;
use App\Models\TopicTag;
use App\Models\User;
use App\Services\PointCalculationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Events\EventTestCase;

/**
 * Task #56: "Where am I?" counts like an area leaderboard, narrowed by category and tag. Only students
 * get it, and only their own numbers; nobody gets a ranked list here (D1, D3, Task #112).
 */
class RankingExplorerTest extends EventTestCase
{
    private Topic $sport;

    private TopicTag $sql;

    private TopicTag $football;

    private User $anna;

    private User $bela;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        // University > Faculty > Databases (from EventTestCase), University > Sport
        $this->sport = $this->topic('Sport', $this->university);
        $this->sql = TopicTag::create(['name' => 'SQL', 'category' => 'academic', 'is_active' => true]);
        $this->football = TopicTag::create(['name' => 'Football', 'category' => 'sports', 'is_active' => true]);

        $this->anna = $this->member('Anna Nagy', 'ANNA01');
        $this->bela = $this->member('Bela Kiss', 'BELA02');

        $this->teacher = $this->makeUser(UserRole::Teacher, 'Tom Teacher');
        $this->assign($this->teacher, $this->role('Lecturer', [Capability::View, Capability::EventsManage]), $this->faculty);
    }

    private function member(string $name, string $neptun, ?Topic $at = null): User
    {
        $student = $this->student($name, $neptun);
        $this->assign($student, $this->role("Student {$neptun}", [Capability::View, Capability::EventsView]), $at ?? $this->faculty);

        return $student;
    }

    private function credit(User $student, int $points, ?Topic $owner = null, ?TopicTag $tag = null, string $category = 'academic'): PointTransaction
    {
        $owner ??= $this->course;
        $event = $this->makeEvent(['owning_topic_id' => $owner->id, 'audience_topic_id' => $owner->id, 'topic_tag_id' => $tag?->id, 'category' => $category]);

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

    /**
     * @param  array<string, mixed>  $query
     */
    private function visit(User $user, array $query = []): TestResponse
    {
        return $this->actingAs($user)->get('/leaderboards?'.http_build_query($query));
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function you(User $student, array $query): mixed
    {
        return $this->visit($student, $query)->assertOk()->inertiaPage()['props']['rankings']['result']['you'];
    }

    public function test_the_filters_count_the_right_points(): void
    {
        $this->credit($this->anna, 10, $this->course, $this->sql, 'academic');
        $this->credit($this->anna, 7, $this->course, $this->football, 'sports');
        $this->credit($this->anna, 100, $this->sport, $this->football, 'sports');

        $points = fn (Topic $area, ?string $category, ?TopicTag $tag) => collect(app(PointCalculationService::class)->standings($area->id, $category, $tag?->id))->sum('points');

        $this->assertSame(17, $points($this->faculty, null, null));
        $this->assertSame(10, $points($this->faculty, 'academic', null));
        $this->assertSame(7, $points($this->faculty, null, $this->football));
        $this->assertSame(0, $points($this->faculty, 'academic', $this->football));
        $this->assertSame(107, $points($this->university, 'sports', $this->football));
    }

    public function test_it_counts_like_a_leaderboard(): void
    {
        $credit = $this->credit($this->anna, 10);
        $this->credit($this->bela, 5);
        $staff = $this->makeUser(UserRole::Teacher);
        $this->credit($staff, 50);

        PointTransaction::create([
            ...$credit->only(['student_id', 'event_id', 'semester_id', 'credited_by_id', 'source', 'category']),
            'points' => -10,
            'type' => PointTransaction::REVERSAL,
            'reversal_of_id' => $credit->id,
        ]);

        $listed = app(PointCalculationService::class)->standings($this->faculty->id, 'academic');

        $this->assertSame([$this->bela->id], array_column($listed, 'student_id'));

        $this->bela->update(['status' => 'inactive']);
        $this->assertSame([], app(PointCalculationService::class)->standings($this->faculty->id, 'academic'));
    }

    public function test_a_students_response_holds_no_other_student(): void
    {
        $this->credit($this->anna, 10);
        $this->credit($this->bela, 30);

        $response = $this->visit($this->anna, ['area' => $this->faculty->id]);

        $response->assertInertia(fn (Assert $page) => $page
            ->where('rankings.result', ['total' => 2, 'you' => ['rank' => 2, 'points' => 10, 'to_next' => 20, 'next_rank' => 1]]));

        foreach (['Bela Kiss', 'BELA02', '"list"', '"items"'] as $hidden) {
            $this->assertStringNotContainsString($hidden, json_encode($response->inertiaPage()['props']['rankings']));
        }
        $this->assertStringNotContainsString('BELA02', $response->getContent());
    }

    public function test_a_student_with_no_points_here_gets_no_rank(): void
    {
        $this->credit($this->bela, 30);

        $this->assertNull($this->you($this->anna, ['area' => $this->faculty->id]));
    }

    public function test_the_first_place_has_no_next_place_and_ties_share_a_rank(): void
    {
        $carla = $this->member('Carla Toth', 'CARL03');
        $dora = $this->member('Dora Varga', 'DORA04');
        $this->credit($this->anna, 30);
        $this->credit($carla, 30);
        $this->credit($this->bela, 20);
        $this->credit($dora, 5);

        $area = ['area' => $this->faculty->id];

        $this->assertSame(['rank' => 1, 'points' => 30, 'to_next' => null, 'next_rank' => null], $this->you($this->anna, $area));
        $this->assertSame(['rank' => 1, 'points' => 30, 'to_next' => null, 'next_rank' => null], $this->you($carla, $area));
        $this->assertSame(['rank' => 3, 'points' => 20, 'to_next' => 10, 'next_rank' => 1], $this->you($this->bela, $area));
        $this->assertSame(['rank' => 4, 'points' => 5, 'to_next' => 15, 'next_rank' => 3], $this->you($dora, $area));
    }

    public function test_a_student_cannot_pick_an_area_they_cannot_open(): void
    {
        $this->visit($this->anna, ['area' => $this->sport->id])
            ->assertNotFound()
            ->assertDontSee('Sport');

        $this->visit($this->anna, ['area' => 999999])->assertNotFound();
    }

    public function test_the_picker_offers_only_areas_the_person_can_open_and_hides_the_titles_above(): void
    {
        $this->visit($this->anna)->assertInertia(fn (Assert $page) => $page
            ->where('rankings.areas', [
                ['id' => $this->faculty->id, 'path' => ['Faculty']],
                ['id' => $this->course->id, 'path' => ['Faculty', $this->course->title]],
            ])
            ->where('rankings.result', null));
    }

    public function test_a_student_never_gets_the_list_even_with_the_rights(): void
    {
        $this->credit($this->bela, 10);
        $this->assign($this->anna, $this->role('Student helper', [Capability::View, Capability::EventsManage]), $this->faculty);

        $response = $this->visit($this->anna, ['area' => $this->faculty->id]);

        $response->assertInertia(fn (Assert $page) => $page->where('rankings.result', ['total' => 1, 'you' => null]));
        $this->assertStringNotContainsString('BELA02', $response->getContent());
    }

    public function test_nobody_gets_a_ranked_list_here(): void
    {
        $this->credit($this->anna, 10);

        $response = $this->visit($this->teacher, ['area' => $this->faculty->id]);

        $response->assertOk()->assertInertia(fn (Assert $page) => $page->where('rankings', null));
        $this->assertStringNotContainsString('ANNA01', $response->getContent());
    }

    public function test_a_new_credit_shows_at_once(): void
    {
        config(['leaderboards.ttl' => 30, 'leaderboards.cache_store' => 'array']);
        Cache::store('array')->flush();

        $this->credit($this->anna, 10, $this->course, $this->sql);
        $query = ['area' => $this->faculty->id, 'category' => 'academic', 'tag' => $this->sql->id];

        $this->assertSame(10, $this->you($this->anna, $query)['points']);

        $this->credit($this->anna, 5, $this->course, $this->sql);

        $this->assertSame(15, $this->you($this->anna, $query)['points']);
    }

    public function test_the_choice_in_the_address_is_kept_and_what_does_not_fit_is_dropped(): void
    {
        $area = ['area' => $this->faculty->id];

        $this->visit($this->anna, [...$area, 'category' => 'academic', 'tag' => $this->sql->id])
            ->assertInertia(fn (Assert $page) => $page->where('rankings.filters', ['area' => $this->faculty->id, 'category' => 'academic', 'tag' => $this->sql->id]));

        // A tag of another category, and a category that does not exist.
        $this->visit($this->anna, [...$area, 'category' => 'academic', 'tag' => $this->football->id])
            ->assertInertia(fn (Assert $page) => $page->where('rankings.filters.tag', null));
        $this->visit($this->anna, [...$area, 'category' => 'nonsense'])
            ->assertInertia(fn (Assert $page) => $page->where('rankings.filters.category', null));
    }

    public function test_only_active_tags_are_offered(): void
    {
        TopicTag::create(['name' => 'Retired', 'category' => 'academic', 'is_active' => false]);

        $this->visit($this->anna)->assertInertia(fn (Assert $page) => $page
            ->where('rankings.tags', fn ($tags) => collect($tags)->pluck('name')->sort()->values()->all() === ['Football', 'SQL']));
    }
}

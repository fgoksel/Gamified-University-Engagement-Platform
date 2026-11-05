<?php

namespace Tests\Feature\Leaderboards;

use App\Models\PointTransaction;
use App\Models\Semester;
use App\Models\Topic;
use App\Models\User;
use App\Services\PointCalculationService;
use App\Services\PointLedger;
use App\Services\TopicService;
use Illuminate\Cache\ArrayStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\Feature\Events\EventTestCase;

/**
 * Task #55: points are added up in one place, and the sums are kept for 30 seconds in a tagged
 * cache that a credit clears at once. The tests of the cache switch it on; everywhere else it is
 * off (phpunit.xml), so the other tests see the ledger as it is.
 *
 * Rows are added "quietly" (straight into the table, without the model) to prove that a value
 * comes from the cache: the cache cannot know about a row that never passed the ledger.
 */
class PointCalculationServiceTest extends EventTestCase
{
    private Topic $web;

    private Topic $sport;

    protected function setUp(): void
    {
        parent::setUp();

        // University > Faculty > Databases (from EventTestCase), Faculty > Web, University > Sport
        $this->web = $this->topic('Web', $this->faculty);
        $this->sport = $this->topic('Sport', $this->university);
    }

    private function cacheOn(): void
    {
        config(['leaderboards.ttl' => 30, 'leaderboards.cache_store' => 'array']);
        Cache::store('array')->flush();
    }

    /** A store with tags that throws when a value is read ("get") or written ("put"); the tag ids it keeps for itself are not values. */
    private function brokenCache(string $failsOn): void
    {
        Cache::extend('broken', fn () => Cache::repository(new class($failsOn) extends ArrayStore
        {
            public function __construct(private string $failsOn)
            {
                parent::__construct();
            }

            public function get($key)
            {
                $this->failsOn === 'get' && throw new RuntimeException('The store cannot be read.');

                return parent::get($key);
            }

            public function put($key, $value, $seconds)
            {
                $this->failsOn === 'put' && ! str_starts_with($key, 'tag:') && throw new RuntimeException('The store cannot be written.');

                return parent::put($key, $value, $seconds);
            }
        }));

        config([
            'leaderboards.ttl' => 30,
            'leaderboards.cache_store' => 'broken',
            'cache.stores.broken' => ['driver' => 'broken'],
        ]);
    }

    private function service(): PointCalculationService
    {
        return app(PointCalculationService::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(User $student, Topic $owner, int $points, string $category): array
    {
        $event = $this->makeEvent(['owning_topic_id' => $owner->id, 'audience_topic_id' => $owner->id, 'points_value' => $points, 'category' => $category]);

        return [
            'student_id' => $student->id,
            'event_id' => $event->id,
            'semester_id' => $event->semester_id,
            'credited_by_id' => $this->admin->id,
            'points' => $points,
            'type' => PointTransaction::CREDIT,
            'source' => 'import',
            'category' => $category,
        ];
    }

    /** Through the ledger model, as every credit does: this is what clears the cache. */
    private function credit(User $student, Topic $owner, int $points, string $category = 'academic'): PointTransaction
    {
        return PointTransaction::create($this->row($student, $owner, $points, $category));
    }

    /** Straight into the table: the cache is not told. */
    private function creditQuietly(User $student, Topic $owner, int $points, string $category = 'academic'): void
    {
        DB::table('point_transactions')->insert([...$this->row($student, $owner, $points, $category), 'created_at' => now()]);
    }

    /**
     * @return array<int, int> student id => points
     */
    private function pointsIn(int $topicId): array
    {
        return collect($this->service()->areaStandings($topicId))->pluck('points', 'student_id')->all();
    }

    public function test_the_standings_of_an_area_add_up_the_ledger_per_category(): void
    {
        $anna = $this->student('Anna Nagy', 'ANNA01');
        $bence = $this->student('Bence Toth', 'BENC02');
        $csilla = $this->student('Csilla Varga', 'CSIL03');

        $this->credit($anna, $this->course, 10, 'academic');
        $this->credit($anna, $this->faculty, 5, 'sports');
        $this->credit($anna, $this->web, 3, 'community');
        $this->credit($bence, $this->web, 20, 'scientific');
        $this->credit($csilla, $this->sport, 50, 'sports');   // another branch of the tree

        $rows = collect($this->service()->areaStandings($this->faculty->id))->keyBy('student_id');

        $this->assertSame([$bence->id, $anna->id], $rows->keys()->all());
        $this->assertSame(
            ['points' => 20, 'academic' => 0, 'scientific' => 20, 'sports' => 0, 'community' => 0, 'position' => 1, 'rank' => 1],
            collect($rows[$bence->id])->only(['points', 'academic', 'scientific', 'sports', 'community', 'position', 'rank'])->all(),
        );
        $this->assertSame(
            ['points' => 18, 'academic' => 10, 'scientific' => 0, 'sports' => 5, 'community' => 3, 'position' => 2, 'rank' => 2],
            collect($rows[$anna->id])->only(['points', 'academic', 'scientific', 'sports', 'community', 'position', 'rank'])->all(),
        );

        // For every student the categories add up to the total, and the total to the ledger.
        foreach ($rows as $row) {
            $this->assertSame($row['points'], $row['academic'] + $row['scientific'] + $row['sports'] + $row['community']);
            $this->assertSame($row['points'], (int) DB::table('point_transactions')->where('student_id', $row['student_id'])->whereIn('event_id', DB::table('events')->whereIn('owning_topic_id', [$this->faculty->id, $this->course->id, $this->web->id])->select('id'))->sum('points'));
        }
    }

    public function test_the_totals_of_a_student_equal_the_ledger_sums(): void
    {
        $anna = $this->student();
        $this->credit($anna, $this->course, 10, 'academic');
        $this->credit($anna, $this->sport, 7, 'sports');
        $this->credit($anna, $this->web, 4, 'academic');

        $this->assertSame(
            ['total' => 21, 'academic' => 14, 'scientific' => 0, 'sports' => 7, 'community' => 0],
            $this->service()->studentTotals($anna->id),
        );
        $this->assertSame((int) DB::table('point_transactions')->where('student_id', $anna->id)->sum('points'), $this->service()->studentTotals($anna->id)['total']);
        // The ledger's own method gives the same numbers: there is one place that adds points up.
        $this->assertSame($this->service()->studentTotals($anna->id), app(PointLedger::class)->totals($anna));
    }

    public function test_the_totals_of_a_student_can_be_for_one_semester(): void
    {
        $anna = $this->student();
        $earlier = $this->credit($anna, $this->course, 10);
        $this->credit($anna, $this->course, 6);

        $other = Semester::create(['name' => 'Spring', 'starts_at' => now()->subYear()->toDateString(), 'ends_at' => now()->subMonths(8)->toDateString(), 'status' => 'archived']);
        DB::table('point_transactions')->where('id', $earlier->id)->update(['semester_id' => $other->id]);

        $this->assertSame(16, $this->service()->studentTotals($anna->id)['total']);
        $this->assertSame(10, $this->service()->studentTotals($anna->id, $other->id)['total']);
        $this->assertSame(6, $this->service()->studentTotals($anna->id, $this->event->semester_id)['total']);
    }

    public function test_a_reversal_takes_the_points_off_and_a_student_left_with_nothing_is_not_listed(): void
    {
        $anna = $this->student();
        $bence = $this->student();
        $credit = $this->credit($anna, $this->course, 10);
        $this->credit($bence, $this->course, 4);

        app(PointLedger::class)->reverse($credit, $this->admin, 'Wrong event');

        $this->assertSame([$bence->id => 4], $this->pointsIn($this->faculty->id));
        $this->assertSame(0, $this->service()->studentTotals($anna->id)['total']);
    }

    public function test_the_standings_are_kept_for_30_seconds(): void
    {
        $this->cacheOn();
        $anna = $this->student();
        $this->credit($anna, $this->course, 10);

        $this->assertSame([$anna->id => 10], $this->pointsIn($this->faculty->id));

        $this->creditQuietly($anna, $this->course, 5);
        $this->assertSame([$anna->id => 10], $this->pointsIn($this->faculty->id), 'Still the cached numbers: the cache was not told.');

        $this->travel(29)->seconds();
        $this->assertSame([$anna->id => 10], $this->pointsIn($this->faculty->id));

        $this->travel(2)->seconds();
        $this->assertSame([$anna->id => 15], $this->pointsIn($this->faculty->id), 'After 30 seconds the ledger is read again.');
    }

    public function test_the_totals_of_a_student_are_kept_for_30_seconds(): void
    {
        $this->cacheOn();
        $anna = $this->student();
        $this->credit($anna, $this->course, 10);

        $this->assertSame(10, $this->service()->studentTotals($anna->id)['total']);

        $this->creditQuietly($anna, $this->course, 5);
        $this->assertSame(10, $this->service()->studentTotals($anna->id)['total']);

        $this->travel(31)->seconds();
        $this->assertSame(15, $this->service()->studentTotals($anna->id)['total']);
    }

    public function test_a_credit_clears_the_standings_at_once(): void
    {
        $this->cacheOn();
        $anna = $this->student();
        $bence = $this->student();
        $this->credit($anna, $this->course, 10);

        $this->assertSame([$anna->id => 10], $this->pointsIn($this->faculty->id));

        $this->credit($bence, $this->course, 25);   // through the ledger: no waiting for the 30 seconds

        $this->assertSame([$bence->id => 25, $anna->id => 10], $this->pointsIn($this->faculty->id));
    }

    public function test_a_reversal_clears_the_cache_at_once(): void
    {
        $this->cacheOn();
        $anna = $this->student();
        $credit = $this->credit($anna, $this->course, 10);

        $this->assertSame([$anna->id => 10], $this->pointsIn($this->faculty->id));
        $this->assertSame(10, $this->service()->studentTotals($anna->id)['total']);

        app(PointLedger::class)->reverse($credit, $this->admin, 'Wrong event');

        $this->assertSame([], $this->pointsIn($this->faculty->id));
        $this->assertSame(0, $this->service()->studentTotals($anna->id)['total']);
    }

    public function test_a_credit_clears_the_student_it_is_for_and_no_other(): void
    {
        $this->cacheOn();
        $anna = $this->student();
        $bence = $this->student();
        $this->credit($anna, $this->course, 10);
        $this->credit($bence, $this->course, 10);

        $this->service()->studentTotals($anna->id);
        $this->service()->studentTotals($bence->id);

        $this->creditQuietly($bence, $this->course, 5);
        $this->credit($anna, $this->course, 1);

        $this->assertSame(11, $this->service()->studentTotals($anna->id)['total']);
        $this->assertSame(10, $this->service()->studentTotals($bence->id)['total'], 'Bence was not credited: his numbers stay as they were.');
    }

    public function test_a_credit_clears_the_area_of_the_event_and_the_areas_above_but_not_the_others(): void
    {
        $this->cacheOn();
        $anna = $this->student();
        $bence = $this->student();
        $this->credit($anna, $this->course, 10);
        $this->credit($anna, $this->sport, 10);

        foreach ([$this->course, $this->faculty, $this->university, $this->web, $this->sport] as $area) {
            $this->pointsIn($area->id);   // all of them are cached now
        }

        $this->creditQuietly($bence, $this->sport, 7);    // not told: Sport and above stay as they were
        $this->creditQuietly($bence, $this->course, 3);   // not told either, until the credit below
        $this->credit($anna, $this->course, 1);           // told: Databases, Faculty and University are cleared

        $this->assertSame([$anna->id => 11, $bence->id => 3], $this->pointsIn($this->course->id));
        $this->assertSame([$anna->id => 11, $bence->id => 3], $this->pointsIn($this->faculty->id));
        // University is cleared too, so it now counts everything: also the two rows nobody told it about.
        $this->assertSame([$anna->id => 21, $bence->id => 10], $this->pointsIn($this->university->id));
        $this->assertSame([], $this->pointsIn($this->web->id), 'Web is not above Databases: it was not cleared.');
        $this->assertSame([$anna->id => 10], $this->pointsIn($this->sport->id), 'Sport is another branch: it was not cleared.');
    }

    public function test_a_topic_moved_into_an_area_counts_at_once(): void
    {
        $this->cacheOn();
        $anna = $this->student();
        $this->credit($anna, $this->sport, 10);

        $this->assertSame([], $this->pointsIn($this->web->id));

        app(TopicService::class)->move($this->admin, $this->sport->fresh(), $this->web->fresh());

        $this->assertSame([$anna->id => 10], $this->pointsIn($this->web->id));
    }

    public function test_the_totals_are_worked_out_each_time_when_the_store_has_no_tags(): void
    {
        config(['leaderboards.ttl' => 30, 'leaderboards.cache_store' => 'file']);
        $anna = $this->student();
        $this->credit($anna, $this->course, 10);

        $this->assertSame([$anna->id => 10], $this->pointsIn($this->faculty->id));

        $this->creditQuietly($anna, $this->course, 5);

        $this->assertSame([$anna->id => 15], $this->pointsIn($this->faculty->id), 'The file store cannot tag, so nothing was kept.');
        $this->assertSame(15, $this->service()->studentTotals($anna->id)['total']);
    }

    public function test_a_time_to_live_of_0_switches_the_cache_off(): void
    {
        config(['leaderboards.ttl' => 0, 'leaderboards.cache_store' => 'array']);
        $anna = $this->student();
        $this->credit($anna, $this->course, 10);

        $this->assertSame([$anna->id => 10], $this->pointsIn($this->faculty->id));

        $this->creditQuietly($anna, $this->course, 5);

        $this->assertSame([$anna->id => 15], $this->pointsIn($this->faculty->id));
    }

    public function test_a_cache_that_cannot_be_reached_never_breaks_the_totals(): void
    {
        // Redis on a port nobody listens on (or no Redis client at all): the totals still come out.
        config([
            'leaderboards.ttl' => 30,
            'leaderboards.cache_store' => 'redis',
            'database.redis.cache.host' => '127.0.0.1',
            'database.redis.cache.port' => '1',
        ]);
        $anna = $this->student();
        $this->creditQuietly($anna, $this->course, 10);

        // The first thing asked of the cache is a read.
        $this->assertSame([$anna->id => 10], $this->pointsIn($this->faculty->id));
        $this->assertSame(10, $this->service()->studentTotals($anna->id)['total']);

        // The next request: a credit with the cache down is still written and still reported by the next read.
        app()->forgetInstance(PointCalculationService::class);
        $this->credit($anna, $this->course, 5);
        $this->assertSame([$anna->id => 15], $this->pointsIn($this->faculty->id));
    }

    public function test_a_cache_that_fails_on_reading_never_breaks_the_totals(): void
    {
        $this->brokenCache('get');
        $anna = $this->student();
        $this->creditQuietly($anna, $this->course, 10);

        Log::spy();

        $this->assertSame([$anna->id => 10], $this->pointsIn($this->faculty->id));
        $this->assertSame(10, $this->service()->studentTotals($anna->id)['total']);

        // One warning, and then the cache is left alone for 30 seconds.
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_a_cache_that_fails_on_writing_never_breaks_the_totals(): void
    {
        $this->brokenCache('put');
        $anna = $this->student();
        $this->creditQuietly($anna, $this->course, 10);

        Log::spy();

        $this->assertSame([$anna->id => 10], $this->pointsIn($this->faculty->id));
        $this->assertSame(10, $this->service()->studentTotals($anna->id)['total']);

        Log::shouldHaveReceived('warning')->once();
    }
}

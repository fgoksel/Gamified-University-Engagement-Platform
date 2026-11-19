<?php

namespace Tests\Feature\Leaderboards;

use App\Enums\Capability;
use App\Enums\UserRole;
use App\Exceptions\EventActionRefused;
use App\Filament\Pages\PointsReset;
use App\Models\Leaderboard;
use App\Models\PointReset;
use App\Models\PointTransaction;
use App\Models\Topic;
use App\Models\TopicAuditEvent;
use App\Models\User;
use App\Services\ApplicantReviewService;
use App\Services\LeaderboardStandings;
use App\Services\PointCalculationService;
use App\Services\PointLedger;
use App\Services\RankingExplorer;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Livewire;
use LogicException;
use Tests\Feature\Events\EventTestCase;

/**
 * Task #102 (UC-6.4): the system admin resets all points by hand, and every total and every
 * leaderboard starts from zero. On 27 October the professor asked for a button, never anything
 * automatic (L3, S4). The ledger is never edited (#59): the earlier rows stay, they no longer count.
 */
class PointsResetTest extends EventTestCase
{
    private function ledger(): PointLedger
    {
        return app(PointLedger::class);
    }

    private function points(): PointCalculationService
    {
        return app(PointCalculationService::class);
    }

    /** Points for an event of the area, written as every credit is: through the ledger model. */
    private function earn(User $student, int $points, string $category = 'academic', ?Topic $owner = null): PointTransaction
    {
        $owner ??= $this->course;
        $event = $this->makeEvent(['owning_topic_id' => $owner->id, 'audience_topic_id' => $owner->id, 'points_value' => $points, 'category' => $category]);

        return PointTransaction::create([
            'student_id' => $student->id,
            'event_id' => $event->id,
            'semester_id' => $event->semester_id,
            'credited_by_id' => $this->admin->id,
            'points' => $points,
            'type' => PointTransaction::CREDIT,
            'source' => PointLedger::IMPORT,
            'category' => $category,
        ]);
    }

    private function board(Topic $topic, string $name): Leaderboard
    {
        return Leaderboard::create([
            'topic_id' => $topic->id,
            'name' => $name,
            'visibility' => Leaderboard::PUBLIC,
            'created_by_id' => $this->admin->id,
        ])->fresh();
    }

    /**
     * @return array<int, int> student id => points, best first
     */
    private function standings(Topic $topic): array
    {
        return collect($this->points()->areaStandings($topic->id))->pluck('points', 'student_id')->all();
    }

    private function refusal(callable $attempt): EventActionRefused
    {
        try {
            $attempt();
        } catch (EventActionRefused $refused) {
            return $refused;
        }

        $this->fail('The ledger did not refuse.');
    }

    public function test_a_reset_starts_every_total_and_every_leaderboard_from_zero(): void
    {
        $anna = $this->student('Anna');
        $bence = $this->student('Bence');
        $this->earn($anna, 10);
        $this->earn($bence, 5, 'sports');
        $board = $this->board($this->faculty, 'Faculty total');

        $this->assertSame([$anna->id => 10, $bence->id => 5], $this->standings($this->faculty));
        $this->assertSame(10, $this->points()->studentTotals($anna->id)['total']);

        $this->ledger()->resetAll($this->admin);

        $this->assertSame([], $this->standings($this->course));
        $this->assertSame([], $this->standings($this->faculty));
        $this->assertSame([], $this->standings($this->university));
        $this->assertSame(0, $this->points()->studentTotals($anna->id)['total']);
        $this->assertSame(0, $this->points()->studentTotals($anna->id)['academic']);
        $this->assertSame(0, $this->points()->studentTotals($bence->id)['sports']);
        $this->assertCount(0, app(LeaderboardStandings::class)->ranked($board));

        // The hall screen too.
        $this->getJson('/hall/leaderboards')
            ->assertOk()
            ->assertJsonPath('leaderboards.0.name', 'Faculty total')
            ->assertJsonPath('leaderboards.0.total', 0)
            ->assertJsonPath('leaderboards.0.rows', []);
    }

    public function test_home_where_am_i_and_the_applicants_list_start_from_zero_too(): void
    {
        $anna = $this->student('Anna');
        $this->assign($anna, $this->studentRole(), $this->course);
        $this->earn($anna, 10);
        $this->accepted($anna);

        $this->actingAs($anna)->get('/')->assertInertia(fn (Assert $page) => $page
            ->component('Home')
            ->where('points.total', 10)
            ->has('recent', 1));
        $this->assertSame(1, app(RankingExplorer::class)->for($anna, $this->course, '', 0)['result']['total']);
        $this->assertSame(10, app(ApplicantReviewService::class)->applicants($this->event)->first()['points']['total']);

        $this->ledger()->resetAll($this->admin);

        // Your points (N7) and Recent points, which list only rows that count, start again from zero.
        $this->actingAs($anna)->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('points.total', 0)
            ->where('points.categories', fn ($categories) => collect($categories)->isNotEmpty() && collect($categories)->every(fn (array $category) => $category['points'] === 0))
            ->where('points.uncategorised', 0)
            ->has('recent', 0));
        $where = app(RankingExplorer::class)->for($anna, $this->course, '', 0)['result'];
        $this->assertSame(0, $where['total']);
        $this->assertNull($where['you']);
        $this->assertSame(0, app(ApplicantReviewService::class)->applicants($this->event)->first()['points']['total']);
    }

    public function test_points_earned_after_a_reset_count_from_zero(): void
    {
        $anna = $this->student('Anna');
        $bence = $this->student('Bence');
        $this->earn($anna, 10);

        $this->ledger()->resetAll($this->admin);
        $this->earn($bence, 3);
        $this->earn($anna, 2, 'sports');

        $this->assertSame([$bence->id => 3, $anna->id => 2], $this->standings($this->faculty));
        $totals = $this->points()->studentTotals($anna->id);
        $this->assertSame([2, 0, 2], [$totals['total'], $totals['academic'], $totals['sports']]);

        $this->actingAs($anna)->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('points.total', 2)
            ->has('recent', 1)
            ->where('recent.0.points', 2));
    }

    public function test_the_totals_of_a_semester_count_from_the_reset_too(): void
    {
        $anna = $this->student('Anna');
        $row = $this->earn($anna, 10);

        $this->ledger()->resetAll($this->admin);

        $this->assertSame(0, $this->points()->studentTotals($anna->id, $row->semester_id)['total']);
    }

    public function test_taking_back_a_credit_from_before_the_reset_changes_no_total(): void
    {
        $anna = $this->student('Anna');
        $old = $this->earn($anna, 10);
        $this->ledger()->resetAll($this->admin);
        $this->earn($anna, 4);

        $this->ledger()->reverse($old, $this->admin, 'Scanned for the wrong student');

        // Not 4 - 10: the correction belongs with the credit, which no longer counts.
        $this->assertSame(4, $this->points()->studentTotals($anna->id)['total']);
        $this->assertSame([$anna->id => 4], $this->standings($this->faculty));
    }

    public function test_taking_back_a_credit_from_after_the_reset_counts(): void
    {
        $anna = $this->student('Anna');
        $this->earn($anna, 10);
        $this->ledger()->resetAll($this->admin);
        $new = $this->earn($anna, 6);

        $this->ledger()->reverse($new, $this->admin, 'Scanned for the wrong student');

        $this->assertSame(0, $this->points()->studentTotals($anna->id)['total']);
        $this->assertSame([], $this->standings($this->faculty));
    }

    public function test_the_ledger_keeps_every_row_and_each_event_keeps_its_history(): void
    {
        $anna = $this->student('Anna');
        $this->accepted($anna);
        $this->ledger()->credit($this->event, $anna, $this->admin, PointLedger::PASSPORT);
        $this->earn($this->student('Bence'), 5);
        $rows = fn () => DB::table('point_transactions')->orderBy('id')->get()->map(fn (object $row) => (array) $row)->all();
        $before = $rows();

        $this->ledger()->resetAll($this->admin);

        $this->assertSame($before, $rows());
        // The organiser still sees who was credited, and nobody gets an event's points twice.
        $this->assertTrue(app(ApplicantReviewService::class)->applicants($this->event)->first()['is_credited']);
        $refused = $this->refusal(fn () => $this->ledger()->credit($this->event, $anna, $this->admin, PointLedger::PASSPORT));
        $this->assertSame('already_credited', $refused->reason);
    }

    public function test_only_a_system_admin_can_reset(): void
    {
        $anna = $this->student('Anna');
        $this->earn($anna, 10);
        $areaAdmin = $this->staff([Capability::LeaderboardsManage, Capability::PointsCredit], $this->university);

        $refused = $this->refusal(fn () => $this->ledger()->resetAll($areaAdmin));

        $this->assertSame('forbidden', $refused->reason);
        $this->assertSame(403, $refused->status);
        $this->assertSame(0, PointReset::count());
        $this->assertSame(10, $this->points()->studentTotals($anna->id)['total']);
    }

    public function test_a_reset_with_nothing_earned_since_the_last_one_is_refused(): void
    {
        $this->assertSame('nothing_to_reset', $this->refusal(fn () => $this->ledger()->resetAll($this->admin))->reason);

        $this->earn($this->student('Anna'), 5);
        $this->ledger()->resetAll($this->admin);

        // A second click adds no second reset.
        $this->assertSame('nothing_to_reset', $this->refusal(fn () => $this->ledger()->resetAll($this->admin))->reason);
        $this->assertSame(1, PointReset::count());
    }

    public function test_the_reset_records_who_reset_and_when(): void
    {
        $this->freezeSecond();
        $last = $this->earn($this->student('Anna'), 10);

        $reset = $this->ledger()->resetAll($this->admin)->fresh();

        $this->assertSame($this->admin->id, $reset->reset_by_id);
        $this->assertSame($last->id, $reset->last_transaction_id);
        $this->assertSame(now()->toDateTimeString(), $reset->created_at->toDateTimeString());

        $audit = TopicAuditEvent::query()->where('action', 'points.reset')->sole();
        $this->assertSame($this->admin->id, $audit->actor_id);
        $this->assertNull($audit->topic_id);
        $this->assertSame(['last_uncounted_id' => 0], $audit->before);
        $this->assertSame(['last_uncounted_id' => $last->id], $audit->after);
    }

    public function test_the_next_reset_counts_from_the_rows_written_since(): void
    {
        $anna = $this->student('Anna');
        $this->earn($anna, 10);
        $this->ledger()->resetAll($this->admin);
        $this->earn($anna, 4);
        $this->ledger()->resetAll($this->admin);
        $this->earn($anna, 1);

        $this->assertSame(1, $this->points()->studentTotals($anna->id)['total']);
        $this->assertSame(2, PointReset::count());
    }

    public function test_a_reset_is_never_changed_or_deleted(): void
    {
        $this->earn($this->student('Anna'), 5);
        $reset = $this->ledger()->resetAll($this->admin);

        foreach ([fn () => $reset->update(['last_transaction_id' => 0]), fn () => $reset->delete()] as $attempt) {
            try {
                $attempt();
                $this->fail('A points reset was changed.');
            } catch (LogicException) {
                // As expected.
            }
        }

        $this->assertSame(1, PointReset::query()->where('id', $reset->id)->where('last_transaction_id', '>', 0)->count());
    }

    public function test_the_reset_clears_the_cached_totals_at_once(): void
    {
        config(['leaderboards.ttl' => 30, 'leaderboards.cache_store' => 'array']);
        Cache::store('array')->flush();
        $anna = $this->student('Anna');
        $this->earn($anna, 10);

        // Now cached for 30 seconds.
        $this->assertSame([$anna->id => 10], $this->standings($this->faculty));
        $this->assertSame(10, $this->points()->studentTotals($anna->id)['total']);

        $this->ledger()->resetAll($this->admin);

        $this->assertSame([], $this->standings($this->faculty));
        $this->assertSame(0, $this->points()->studentTotals($anna->id)['total']);
    }

    public function test_nothing_resets_the_points_on_its_own(): void
    {
        $commands = array_keys(Artisan::all());
        $scheduled = collect(app(Schedule::class)->events())->map(fn ($event) => (string) $event->command);

        $this->assertSame([], array_values(array_filter($commands, fn (string $name) => str_contains($name, 'point'))));
        $this->assertFalse($scheduled->contains(fn (string $command) => str_contains($command, 'reset')));
    }

    public function test_only_the_system_admin_opens_the_page(): void
    {
        $areaAdmin = $this->staff([Capability::LeaderboardsManage], $this->university);

        // People who are refused go home (bootstrap/app.php).
        $this->actingAs($areaAdmin)->get('/admin/points-reset')->assertRedirect('/');
        $this->actingAs($this->admin)->get('/admin/points-reset')
            ->assertOk()
            ->assertSee(__('admin.points_reset.never'));
    }

    public function test_the_reset_asks_to_type_the_word(): void
    {
        $anna = $this->student('Anna');
        $this->earn($anna, 10);
        $page = fn () => Livewire::actingAs($this->admin)->test(PointsReset::class);

        $page()->callAction('resetAllPoints', ['confirmation' => ''])->assertHasActionErrors(['confirmation' => 'required']);
        $page()->callAction('resetAllPoints', ['confirmation' => 'yes'])->assertHasActionErrors(['confirmation']);
        $this->assertSame(0, PointReset::count());
        $this->assertSame(10, $this->points()->studentTotals($anna->id)['total']);

        // Capitals or not, but the whole word.
        $page()->callAction('resetAllPoints', ['confirmation' => ' reset '])
            ->assertHasNoActionErrors()
            ->assertNotified(__('admin.points_reset.done'));
        $this->assertSame(1, PointReset::count());
        $this->assertSame(0, $this->points()->studentTotals($anna->id)['total']);
    }

    public function test_a_refused_reset_says_why_and_changes_nothing(): void
    {
        Livewire::actingAs($this->admin)->test(PointsReset::class)
            ->callAction('resetAllPoints', ['confirmation' => 'RESET'])
            ->assertHasNoActionErrors()
            ->assertNotified(__('admin.points_reset.not_reset'));

        $this->assertSame(0, PointReset::count());
    }

    public function test_the_page_lists_every_reset_with_who_reset_and_when(): void
    {
        $this->freezeSecond();
        $other = $this->makeUser(UserRole::Admin, 'Admin Two');
        $this->earn($this->student('Anna'), 10);
        $first = $this->ledger()->resetAll($this->admin);
        $this->travel(2)->days();
        $this->earn($this->student('Bence'), 5);
        $second = $this->ledger()->resetAll($other);

        Livewire::actingAs($this->admin)->test(PointsReset::class)
            ->assertCanSeeTableRecords([$second, $first], inOrder: true)
            ->assertSee('Admin Two')
            // The table writes dates as the subheading does.
            ->assertSee($first->fresh()->created_at->translatedFormat('j M Y, H:i'));

        $this->actingAs($this->admin)->get('/admin/points-reset')->assertSee(__('admin.points_reset.counting_since', [
            'date' => $second->fresh()->created_at->translatedFormat('j M Y, H:i'),
            'name' => 'Admin Two',
        ]));
    }
}

<?php

namespace Tests\Feature\Sprint2;

use App\Enums\UserRole;
use App\Exceptions\EventActionRefused;
use App\Models\Event;
use App\Models\EventApplication;
use App\Models\PointTransaction;
use App\Models\QrToken;
use App\Models\RoleAssignment;
use App\Models\Topic;
use App\Models\User;
use App\Notifications\EventStatusChangedNotification;
use App\Services\EventApplicationService;
use App\Services\EventCatalog;
use App\Services\EventPlacement;
use App\Services\PointLedger;
use App\Services\QrPassport;
use App\Services\QrTokenService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\Sprint2DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Task #67: the Sprint 2 demo data, and a check that every step of the
 * walkthrough in docs/sprint2-demo.md works on it.
 */
class Sprint2DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    private function seedDemo(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(Sprint2DemoSeeder::class);
    }

    private function person(string $key): User
    {
        return User::where('email', Sprint2DemoSeeder::PEOPLE[$key][1])->firstOrFail();
    }

    private function event(string $title): Event
    {
        return Event::where('title', $title)->firstOrFail();
    }

    /**
     * @return array<string, string> student name => status
     */
    private function applications(string $title): array
    {
        return EventApplication::query()
            ->where('event_id', $this->event($title)->id)
            ->with('student:id,name')
            ->get()
            ->mapWithKeys(fn (EventApplication $a) => [$a->student->name => $a->status])
            ->sortKeys()
            ->all();
    }

    public function test_the_normal_seeder_creates_no_demo_data(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(0, Event::count());
        $this->assertSame(0, User::where('email', 'like', 'sprint2.%')->count());
    }

    public function test_it_builds_two_branches_with_events_in_every_state_and_is_repeatable(): void
    {
        $this->seedDemo();
        $counts = [Topic::count(), User::count(), Event::count(), EventApplication::count(), PointTransaction::count(), RoleAssignment::count()];

        $this->seed(Sprint2DemoSeeder::class);

        $this->assertSame($counts, [Topic::count(), User::count(), Event::count(), EventApplication::count(), PointTransaction::count(), RoleAssignment::count()]);

        $root = Topic::where('title', Sprint2DemoSeeder::ROOT)->firstOrFail();
        $this->assertEqualsCanonicalizing(['Faculty of Informatics', 'Faculty of Sport'], $root->children()->pluck('title')->all());

        $this->assertEqualsCanonicalizing(
            ['draft', 'published', 'on_hold', 'cancelled', 'completed'],
            Event::query()->distinct()->pluck('status')->all()
        );
        $this->assertTrue($this->event('Surprise SQL Quiz')->is_hidden);

        $this->assertSame(['Anna Nagy' => 'accepted', 'Bence Tóth' => 'accepted', 'Csilla Varga' => 'waitlisted'], $this->applications('SQL Joins Workshop'));
        $this->assertSame(['pending'], array_values(array_unique($this->applications('Database Design Hackathon'))));
        $this->assertCount(4, $this->applications('Database Design Hackathon'));

        $lab = $this->event('Indexing Lab');
        $this->assertTrue($lab->event_starts_at->isPast() && $lab->event_ends_at->isFuture(), 'The lab must be running at demo time.');
        $this->assertNull(app(PointLedger::class)->refusalFor($lab));
        $this->assertSame(1, $lab->qrTokens()->where('type', QrToken::POSTER)->count());

        $ledger = app(PointLedger::class);
        $this->assertSame(
            ['anna' => 10, 'bence' => 30, 'csilla' => 10, 'dani' => 0, 'eszter' => 10, 'erik' => 25, 'fanni' => 0],
            collect(['anna', 'bence', 'csilla', 'dani', 'eszter', 'erik', 'fanni'])
                ->mapWithKeys(fn ($key) => [$key => $ledger->totals($this->person($key))['total']])->all()
        );

        // Students heard about the applications the services made for them.
        $this->assertSame(1, $this->person('csilla')->notifications()->where('data->decision', 'waitlisted')->count());
    }

    public function test_every_step_of_the_walkthrough_works_on_the_demo_data(): void
    {
        $this->seedDemo();
        Notification::fake();
        $applications = app(EventApplicationService::class);
        $ledger = app(PointLedger::class);
        [$ivan, $anna, $csilla, $dani, $eszter] = array_map(fn ($key) => $this->person($key), ['ivan', 'anna', 'csilla', 'dani', 'eszter']);

        // 1. Two branches: Ivan and Anna cannot reach the Sport event; Sara's own students can.
        $run = $this->event('Campus 5K Run');
        $this->assertFalse($ivan->can('view', $run));
        $this->assertFalse($ivan->can('credit', $run));
        $this->assertFalse($anna->can('view', $run));
        $this->assertTrue($this->person('sara')->can('credit', $run));
        $this->assertFalse($applications->mayApply($anna, $this->event('Athletics Championship')));

        // ...and the pages agree: Ivan may only create events in his own branch, and
        // "All events" shows each person their own branch only (no drafts, holds or surprises).
        $this->assertEqualsCanonicalizing(
            ['Faculty of Informatics', 'Databases', 'Web Development'],
            array_column(app(EventPlacement::class)->formOptions($ivan), 'title')
        );
        $catalog = app(EventCatalog::class);
        $this->assertNotContains('Campus 5K Run', $catalog->query($ivan)->pluck('title'));
        $this->assertEqualsCanonicalizing(
            ['SQL Joins Workshop', 'Database Design Hackathon', 'Indexing Lab'],
            $catalog->query($anna)->pluck('title')->all()
        );
        $this->assertEqualsCanonicalizing(['Campus 5K Run', 'Athletics Championship'], $catalog->query($this->person('erik'))->pluck('title')->all());

        // 2. No overbooking: the workshop is full, so Eszter is waitlisted.
        $joins = $this->event('SQL Joins Workshop');
        $this->assertSame('waitlisted', $applications->apply($eszter, $joins)->status);

        // 3. Waitlist promotion: Anna withdraws, Csilla (first in line) moves up, Eszter still waits.
        $applications->withdraw($anna, $joins);
        $this->assertSame(['Anna Nagy' => 'withdrawn', 'Bence Tóth' => 'accepted', 'Csilla Varga' => 'accepted', 'Eszter Kiss' => 'waitlisted'], $this->applications('SQL Joins Workshop'));

        // 4. Crediting at the running lab: checklist, passport and poster.
        $lab = $this->event('Indexing Lab');
        $this->assertSame(20, $ledger->credit($lab, $csilla, $ivan, PointLedger::MANUAL)->points);
        $this->actingAs($ivan)->postJson("/events/{$lab->id}/credit", ['identifier' => app(QrPassport::class)->issue($anna)])
            ->assertJsonPath('status', 'credited');
        $qr = app(QrTokenService::class);
        $poster = $qr->payload($lab->qrTokens()->firstOrFail());
        $this->assertSame('credited', $qr->redeem($dani, $poster)['outcome']);

        // 5. No double credit: Bence was credited when the data was seeded.
        $this->actingAs($ivan)->postJson("/events/{$lab->id}/credit", ['identifier' => 'BENC02', 'source' => 'manual'])
            ->assertJsonPath('status', 'already_credited');
        $this->assertThrows(fn () => $qr->redeem($dani, $poster), EventActionRefused::class);
        $this->assertSame(4, $lab->pointTransactions()->count());

        // 6. Restoring the event on hold tells Anna.
        $this->actingAs($ivan)->post('/events/'.$this->event('Database Careers Evening')->id.'/restore')->assertSessionHas('success');
        Notification::assertSentTo($anna, EventStatusChangedNotification::class);
    }

    public function test_every_demo_account_can_sign_in_and_open_its_pages(): void
    {
        $this->seedDemo();
        $hackathon = $this->event('Database Design Hackathon');
        $lab = $this->event('Indexing Lab');
        $joins = $this->event('SQL Joins Workshop');
        $databases = Topic::where('title', 'Databases')->firstOrFail();

        // The password from the walkthrough works.
        $this->post('/login', ['email' => Sprint2DemoSeeder::PEOPLE['ivan'][1], 'password' => Sprint2DemoSeeder::PASSWORD])
            ->assertRedirect();
        $this->assertAuthenticatedAs($this->person('ivan'));
        $this->post('/logout');

        foreach (['ivan' => 'Faculty of Informatics', 'sara' => 'Faculty of Sport'] as $key => $branch) {
            foreach (['/', '/events', '/my-events', '/events/create', '/notifications'] as $page) {
                $this->actingAs($this->person($key))->get($page)->assertOk();
            }

            // The Topics explorer (Sprint 1) opens straight at the organiser's own branch.
            $own = Topic::where('title', $branch)->firstOrFail();
            $this->actingAs($this->person($key))->get('/topics')->assertRedirect("/topics/{$own->id}");
            $this->actingAs($this->person($key))->get("/topics/{$own->id}")->assertOk();
        }
        foreach (["/events/{$lab->id}/scan", "/events/{$lab->id}/qr-codes", "/events/{$hackathon->id}/applicants", "/topics/{$databases->id}"] as $page) {
            $this->actingAs($this->person('ivan'))->get($page)->assertOk();
        }

        foreach (['anna', 'erik'] as $key) {
            foreach (['/', '/events', '/my-events', '/notifications', '/passport', '/scan'] as $page) {
                $this->actingAs($this->person($key))->get($page)->assertOk();
            }
        }
        $this->actingAs($this->person('anna'))->get("/events/{$joins->id}")->assertOk();

        // Sprint 1 admin panel still opens for a System Admin.
        $admin = User::role(UserRole::Admin)->firstOrFail();
        $admin->forceFill(['must_change_password' => false])->save();
        foreach (['/admin', '/admin/organizers', '/admin/students'] as $page) {
            $this->actingAs($admin)->get($page)->assertOk();
        }
    }

    public function test_it_refuses_to_run_in_production(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->app->detectEnvironment(fn () => 'production');

        (new Sprint2DemoSeeder)->setContainer($this->app)->run();

        $this->assertSame(0, Event::count());
        $this->assertSame(0, Topic::count());
    }
}

<?php

namespace Tests\Feature\Events;

use App\Enums\Capability;
use App\Exceptions\EventActionRefused;
use App\Models\Event;
use App\Models\EventApplication;
use App\Models\PointTransaction;
use App\Models\User;
use App\Services\EventApplicationService;
use App\Services\PointLedger;
use App\Services\QrPassport;
use App\Services\QrTokenService;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Task #86: a surprise event stays invisible and is credited only by import (26 Oct, Q8).
 *
 * Students neither find it, nor apply, nor scan. The students it gave points to may open it
 * afterwards, which is how they learn of it.
 */
class SurpriseEventTest extends EventTestCase
{
    private User $organiser;

    private User $anna;

    private User $bela;

    private Event $surprise;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organiser = $this->staff([Capability::EventsManage, Capability::PointsCredit, Capability::QrCodesManage], name: 'Olga Organiser');
        $this->anna = $this->member('ANNA01', 'Anna');
        $this->bela = $this->member('BELA02', 'Bela');

        // Published and running. The old reveal time is data left over from before Task #86.
        $this->surprise = $this->makeEvent([
            'title' => 'Grade 5 badge',
            'is_hidden' => true,
            'reveal_at' => now()->subDay(),
            'qr_enabled' => true,
        ]);
    }

    private function member(string $neptun, string $name): User
    {
        $student = $this->student($name, $neptun);
        $this->assign($student, $this->studentRole(), $this->course);

        return $student;
    }

    /**
     * The titles "All events" lists to a person.
     *
     * @return list<string>
     */
    private function titlesFor(User $user, string $query = ''): array
    {
        $titles = [];

        $this->actingAs($user)->get('/events'.$query)->assertOk()
            ->assertInertia(function (Assert $page) use (&$titles) {
                $titles = collect($page->toArray()['props']['events']['data'])->pluck('title')->sort()->values()->all();
            });

        return $titles;
    }

    private function importAnna(): void
    {
        $this->actingAs($this->organiser)->post(
            "/events/{$this->surprise->id}/points-import",
            ['file' => UploadedFile::fake()->createWithContent('list.csv', "Neptun Code\nANNA01\n")],
            ['Accept' => 'application/json'],
        )->assertOk()->assertJsonCount(1, 'credited');
    }

    public function test_a_student_never_finds_a_surprise_event(): void
    {
        $this->makeEvent(['title' => 'Open to all']);

        // Not in the catalog, not by search, and its address is "not found", whatever an old reveal time says.
        $titles = $this->titlesFor($this->anna);
        $this->assertContains('Open to all', $titles);
        $this->assertNotContains('Grade 5 badge', $titles);
        $this->assertSame([], $this->titlesFor($this->anna, '?q=badge'));
        $this->actingAs($this->anna)->get("/events/{$this->surprise->id}")->assertNotFound();
    }

    public function test_staff_still_see_it_marked_as_a_surprise(): void
    {
        $this->assertContains('Grade 5 badge', $this->titlesFor($this->organiser));

        $this->actingAs($this->organiser)->get("/events/{$this->surprise->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('event.is_surprise', true)
                ->where('event.can_apply', false)
                ->where('event.is_open', false));
    }

    public function test_nobody_can_apply_to_it(): void
    {
        $this->actingAs($this->anna)->postJson("/events/{$this->surprise->id}/apply")
            ->assertStatus(422)
            ->assertJsonPath('errors.event.0', 'This event is not open for applications.');

        $this->assertFalse(app(EventApplicationService::class)->mayApply($this->anna, $this->surprise));
        $this->assertSame(0, EventApplication::count());
    }

    public function test_no_scan_can_credit_it(): void
    {
        // Even somebody who holds an accepted application (old data) cannot be credited by a scan.
        $this->accepted($this->anna, $this->surprise);

        // The organiser's passport scanner.
        $this->actingAs($this->organiser)
            ->postJson("/events/{$this->surprise->id}/credit", ['identifier' => app(QrPassport::class)->issue($this->anna)])
            ->assertOk()
            ->assertJson(['status' => 'error', 'message' => 'This is a surprise event: its points are given only by import.']);

        // Posters, slips and the live code cannot even be made, and the QR page says why.
        $this->actingAs($this->organiser)->postJson("/events/{$this->surprise->id}/qr-tokens", ['type' => 'poster'])
            ->assertStatus(409)->assertJsonPath('reason', 'surprise');
        $this->actingAs($this->organiser)->postJson("/events/{$this->surprise->id}/checkin/open")
            ->assertStatus(409)->assertJsonPath('reason', 'surprise');
        $this->actingAs($this->organiser)->get("/events/{$this->surprise->id}/qr-codes")
            ->assertInertia(fn (Assert $page) => $page->where('refusal', 'A surprise event has no QR codes: its points are given only by import.'));

        // A code made before the event became a surprise one gives nothing either.
        $earlier = $this->makeEvent(['title' => 'Was an ordinary event']);
        $qr = app(QrTokenService::class);
        $code = $qr->payload($qr->issue($this->organiser, $earlier));
        $this->accepted($this->anna, $earlier);
        $earlier->update(['is_hidden' => true]);

        $this->actingAs($this->anna)->postJson('/qr/redeem', ['code' => $code])
            ->assertStatus(409)
            ->assertJsonPath('message', 'This is a surprise event: its points are given only by import.');

        $this->assertSame(0, PointTransaction::count());
    }

    public function test_the_ledger_credits_a_surprise_event_only_through_the_import(): void
    {
        $ledger = app(PointLedger::class);
        $this->accepted($this->anna, $this->surprise);

        try {
            $ledger->credit($this->surprise, $this->anna, $this->organiser, PointLedger::PASSPORT);
            $this->fail('A surprise event was credited without an import.');
        } catch (EventActionRefused $refused) {
            $this->assertSame('import_only', $refused->reason);
        }

        $this->assertSame('import', $ledger->creditImported($this->surprise, $this->anna, $this->organiser)->source);
    }

    public function test_the_student_it_gave_points_to_may_open_it_afterwards(): void
    {
        $this->importAnna();

        $this->assertSame(0, EventApplication::count());
        $this->assertSame(['import'], PointTransaction::query()->pluck('source')->all());

        // Anna learns of the event now: she may open it, and sees it is one she cannot join.
        $this->actingAs($this->anna)->get("/events/{$this->surprise->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('event.title', 'Grade 5 badge')
                ->where('event.points', 10)
                ->where('event.is_surprise', true)
                ->where('event.can_apply', false)
                ->where('event.is_open', false));

        // It is still not in the catalog, and Bela, who got nothing, still cannot find it.
        $this->assertNotContains('Grade 5 badge', $this->titlesFor($this->anna));
        $this->actingAs($this->bela)->get("/events/{$this->surprise->id}")->assertNotFound();
        $this->actingAs($this->anna)->postJson("/events/{$this->surprise->id}/apply")->assertStatus(422);
    }

    public function test_the_credited_student_loses_sight_of_it_while_it_is_frozen(): void
    {
        $this->importAnna();

        $this->surprise->update(['status' => Event::STATUS_ON_HOLD]);
        $this->actingAs($this->anna)->get("/events/{$this->surprise->id}")->assertNotFound();

        $this->surprise->update(['status' => Event::STATUS_PUBLISHED]);
        $this->actingAs($this->anna)->get("/events/{$this->surprise->id}")->assertOk();
    }

    public function test_the_edit_page_offers_nothing_to_scan_for_a_surprise_event(): void
    {
        $ordinary = $this->makeEvent(['title' => 'Ordinary']);

        $this->actingAs($this->organiser)->get("/events/{$ordinary->id}/edit")
            ->assertInertia(fn (Assert $page) => $page->where('tabs.credit', true));
        $this->actingAs($this->organiser)->get("/events/{$this->surprise->id}/edit")
            ->assertInertia(fn (Assert $page) => $page->where('tabs.credit', false));
    }
}

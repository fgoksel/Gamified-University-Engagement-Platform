<?php

namespace Tests\Feature\Events;

use App\Enums\Capability;
use App\Models\QrRedemption;
use App\Models\QrToken;
use App\Models\User;
use App\Services\QrTokenService;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Task #84 (replacing the posters and parts of Task #44): the organiser's page of printed
 * single-use slips. A batch prints exactly the slips that were asked for, and the log shows
 * who used which slip and when. Slips are issued through QrTokenService (Task #45) and
 * drawn by the server (Task #46).
 */
class QrSlipsTest extends EventTestCase
{
    private User $organiser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organiser = $this->staff([Capability::PointsCredit, Capability::QrCodesManage], name: 'QR organiser');
    }

    private function url(string $path = ''): string
    {
        return "/events/{$this->event->id}/qr-codes{$path}";
    }

    private function make(int $copies): string
    {
        $this->actingAs($this->organiser)->post($this->url(), ['copies' => $copies])->assertSessionHas('success');

        return QrToken::query()->latest('id')->value('batch');
    }

    private function use(QrToken $slip, User $student): void
    {
        $qr = app(QrTokenService::class);
        $qr->redeem($student, $qr->payload($slip));
    }

    private function acceptedStudent(string $name, string $neptun): User
    {
        $student = $this->student($name, $neptun);
        $this->accepted($student);

        return $student;
    }

    public function test_a_batch_has_exactly_the_number_of_slips_asked_for(): void
    {
        $this->actingAs($this->organiser)->post($this->url(), ['copies' => 30])
            ->assertSessionHas('success', '30 slips made. Print them from the list below.');
        $this->actingAs($this->organiser)->post($this->url(), ['copies' => 1])
            ->assertSessionHas('success', '1 slip made. Print it from the list below.');

        $this->assertSame(31, QrToken::where('type', QrToken::SLIP)->count());
        $this->assertSame(2, QrToken::distinct()->count('batch'));
        $this->assertSame([$this->organiser->id], QrToken::distinct()->pluck('issuer_id')->all());

        $this->actingAs($this->organiser)->get($this->url())
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Events/QrCodes')
                ->where('refusal', null)
                ->where('maxSlips', 300)
                ->has('batches', 2)
                // The newest batch first.
                ->where('batches.0.label', 'Batch 2')
                ->where('batches.0.total', 1)
                ->where('batches.0.slips.0.number', 31)
                ->where('batches.1.label', 'Batch 1')
                ->where('batches.1.total', 30)
                ->where('batches.1.working', 30)
                ->where('batches.1.used', 0)
                ->where('batches.1.by', 'QR organiser')
                ->where('batches.1.slips.0.number', 1)
                ->where('batches.1.slips.29.number', 30)
                ->where('batches.1.slips.29.state', 'working')
                ->where('log', [])
                ->where('retired', 0));
    }

    public function test_each_batch_prints_on_its_own(): void
    {
        $first = $this->make(30);
        $second = $this->make(10);

        $this->actingAs($this->organiser)->get($this->url("/print/{$first}"))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Print/EventQrCodes')
                ->where('batch', 'Batch 1')
                ->where('event.points', 10)
                ->has('codes', 30)
                ->where('codes.0.number', 1)
                ->where('codes.29.number', 30));

        $slip = QrToken::where('batch', $second)->orderBy('id')->first();
        $this->actingAs($this->organiser)->get($this->url("/print/{$second}"))
            ->assertInertia(fn (Assert $page) => $page
                ->where('batch', 'Batch 2')
                ->has('codes', 10)
                ->where('codes.0.id', $slip->id)
                ->where('codes.0.number', 31)
                ->where('codes.0.image', route('events.qr.image', [$this->event, $slip, 'svg'])));

        // The image behind the sheet really is an SVG of that slip.
        $this->actingAs($this->organiser)->get(route('events.qr.image', [$this->event, $slip, 'svg']))
            ->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
    }

    public function test_used_and_withdrawn_slips_are_left_off_a_reprint(): void
    {
        $batch = $this->make(5);
        [, $two, , $four] = QrToken::orderBy('id')->get()->all();

        $this->use($two, $this->acceptedStudent('Anna Nagy', 'ANNA01'));
        $this->actingAs($this->organiser)->post($this->url("/{$four->id}/revoke"))
            ->assertSessionHas('success', 'The slip no longer works. Points already given with it are kept.');
        $this->assertNotNull($four->fresh()->revoked_at);

        $this->actingAs($this->organiser)->get($this->url("/print/{$batch}"))
            ->assertInertia(fn (Assert $page) => $page->has('codes', 3)
                ->where('codes.0.number', 1)
                ->where('codes.1.number', 3)
                ->where('codes.2.number', 5));

        $this->actingAs($this->organiser)->get($this->url())
            ->assertInertia(fn (Assert $page) => $page
                ->where('batches.0.used', 1)
                ->where('batches.0.withdrawn', 1)
                ->where('batches.0.working', 3)
                ->where('batches.0.slips.0.state', 'working')
                ->where('batches.0.slips.1.state', 'used')
                ->where('batches.0.slips.1.used_by', 'Anna Nagy')
                ->where('batches.0.slips.3.state', 'withdrawn'));
    }

    public function test_the_log_shows_who_used_which_slip_and_when(): void
    {
        $this->freezeTime();
        $this->make(5);
        $this->make(5);
        $slips = QrToken::orderBy('id')->get();
        $anna = $this->acceptedStudent('Anna Nagy', 'ANNA01');
        $bence = $this->acceptedStudent('Bence Toth', 'BENC02');

        $this->use($slips[2], $anna);
        $annaAt = now()->format('j M Y, H:i');
        $this->travel(7)->minutes();
        $this->use($slips[6], $bence);
        $benceAt = now()->format('j M Y, H:i');

        // A poster from before Task #84: it no longer works, but its earlier scan stays on record.
        $poster = QrToken::create(['event_id' => $this->event->id, 'issuer_id' => $this->organiser->id, 'token_code' => Str::random(48), 'type' => QrToken::POSTER]);
        QrRedemption::create(['qr_token_id' => $poster->id, 'student_id' => $this->student('Csilla Varga', 'CSIL03')->id, 'redeemed_at' => now()->subDay()]);

        $this->actingAs($this->organiser)->get($this->url())
            ->assertInertia(fn (Assert $page) => $page
                ->has('log', 3)
                ->where('log.0.student', 'Bence Toth')
                ->where('log.0.neptun', 'BENC02')
                ->where('log.0.code', 'Slip 7')
                ->where('log.0.points', 10)
                ->where('log.0.at', $benceAt)
                ->where('log.1.student', 'Anna Nagy')
                ->where('log.1.neptun', 'ANNA01')
                ->where('log.1.code', 'Slip 3')
                ->where('log.1.at', $annaAt)
                ->where('log.2.code', 'Poster')
                ->where('log.2.points', null)
                ->where('retired', 1)
                // The poster is no slip: it is in no batch and cannot be printed.
                ->has('batches', 2));
    }

    public function test_the_number_of_slips_is_checked(): void
    {
        foreach ([
            [[], 'Say how many slips to make.'],
            [['copies' => 0], 'Make at least 1 slip.'],
            [['copies' => 301], 'Make at most 300 slips at a time.'],
            [['copies' => 'many'], null],
        ] as [$data, $message]) {
            $response = $this->actingAs($this->organiser)->post($this->url(), $data)->assertSessionHasErrors('copies');
            if ($message !== null) {
                $response->assertSessionHasErrors(['copies' => $message]);
            }
        }

        $this->assertSame(0, QrToken::count());
    }

    public function test_no_slips_for_a_draft_an_event_without_qr_one_on_hold_or_after_its_points_end_date(): void
    {
        foreach ([
            [['status' => 'draft', 'published_at' => null], 'Publish the event first, then make its slips.'],
            [['status' => 'published', 'qr_enabled' => false], 'QR attendance is switched off for this event. Turn it on in the event to make slips.'],
            [['qr_enabled' => true, 'status' => 'on_hold'], 'This event is on hold. Restore it to make slips.'],
            [['status' => 'published', 'completion_deadline' => now()->subMinute()], "This event's completion deadline has passed, so new slips would not work."],
        ] as [$state, $reason]) {
            $this->event->update($state);

            $this->actingAs($this->organiser)->get($this->url())
                ->assertInertia(fn (Assert $page) => $page->where('refusal', $reason));
            $this->actingAs($this->organiser)->post($this->url(), ['copies' => 5])
                ->assertSessionHas('error');
        }

        $this->assertSame(0, QrToken::count());
    }

    public function test_expired_slips_are_not_printed(): void
    {
        $token = app(QrTokenService::class)->issue($this->organiser, $this->event, now()->addDay());
        $batch = $this->make(3);
        $this->travel(2)->days();

        $this->actingAs($this->organiser)->get($this->url("/print/{$token->batch}"))
            ->assertInertia(fn (Assert $page) => $page->has('codes', 0));
        $this->actingAs($this->organiser)->get($this->url("/print/{$batch}"))
            ->assertInertia(fn (Assert $page) => $page->has('codes', 3));

        // After the points end date no slip works, so none is printed.
        $this->travel(7)->days();
        $this->actingAs($this->organiser)->get($this->url("/print/{$batch}"))
            ->assertInertia(fn (Assert $page) => $page->has('codes', 0));
        $this->actingAs($this->organiser)->get($this->url())
            ->assertInertia(fn (Assert $page) => $page->where('batches.0.working', 0)->where('batches.0.slips.0.state', 'expired'));
    }

    public function test_slips_made_before_batches_print_together_as_earlier_slips(): void
    {
        foreach (range(1, 2) as $i) {
            QrToken::create(['event_id' => $this->event->id, 'issuer_id' => $this->organiser->id, 'token_code' => Str::random(48), 'type' => QrToken::SLIP]);
        }
        $this->make(3);

        $this->actingAs($this->organiser)->get($this->url())
            ->assertInertia(fn (Assert $page) => $page
                ->has('batches', 2)
                ->where('batches.0.label', 'Batch 1')
                ->where('batches.0.slips.0.number', 3)
                ->where('batches.1.key', 'earlier')
                ->where('batches.1.label', 'Earlier slips')
                ->where('batches.1.total', 2));

        $this->actingAs($this->organiser)->get($this->url('/print/earlier'))
            ->assertInertia(fn (Assert $page) => $page->where('batch', 'Earlier slips')->has('codes', 2)->where('codes.1.number', 2));
    }

    public function test_only_batches_of_this_event_print(): void
    {
        $other = $this->makeEvent();
        $foreign = app(QrTokenService::class)->makeSlips($this->organiser, $other, 2)->first()->batch;
        $this->make(2);

        $this->actingAs($this->organiser)->get($this->url("/print/{$foreign}"))->assertNotFound();
        $this->actingAs($this->organiser)->get($this->url('/print/'.Str::uuid()))->assertNotFound();
        // No earlier slips here, and posters are not printed any more.
        $this->actingAs($this->organiser)->get($this->url('/print/earlier'))->assertNotFound();
        $this->actingAs($this->organiser)->get($this->url('/print/poster'))->assertNotFound();
    }

    public function test_a_slip_of_another_event_cannot_be_withdrawn_through_this_one(): void
    {
        $other = $this->makeEvent(['qr_enabled' => true]);
        $token = app(QrTokenService::class)->issue($this->organiser, $other);

        $this->actingAs($this->organiser)->post($this->url("/{$token->id}/revoke"))->assertNotFound();
        $this->assertNull($token->fresh()->revoked_at);
    }

    public function test_only_people_with_the_qr_capability_may_use_the_page(): void
    {
        $batch = app(QrTokenService::class)->makeSlips($this->organiser, $this->event, 2)->first()->batch;
        $creditOnly = $this->staff([Capability::PointsCredit], name: 'Crediter');

        foreach (['get' => $this->url(), 'post' => $this->url(), 'print' => $this->url("/print/{$batch}")] as $kind => $url) {
            $response = $kind === 'post'
                ? $this->actingAs($creditOnly)->post($url, ['copies' => 5])
                : $this->actingAs($creditOnly)->get($url);
            $response->assertRedirect('/');
        }

        // Someone who is not staff of the event does not even learn it exists.
        $this->actingAs($this->student())->get($this->url())->assertNotFound();
        $this->actingAs($this->student())->get($this->url("/print/{$batch}"))->assertNotFound();

        $this->assertSame(2, QrToken::count());
    }

    public function test_the_organised_list_offers_the_slips_page_to_qr_managers_only(): void
    {
        $this->actingAs($this->organiser)->get('/my-events')
            ->assertInertia(fn (Assert $page) => $page->where('events.0.can_qr', true));

        $creditOnly = $this->staff([Capability::PointsCredit], name: 'Crediter');
        $this->actingAs($creditOnly)->get('/my-events')
            ->assertInertia(fn (Assert $page) => $page->where('events.0.can_qr', false));
    }
}

<?php

namespace Tests\Feature\Events;

use App\Enums\Capability;
use App\Models\QrToken;
use App\Models\User;
use App\Services\QrTokenService;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Task #44: the organiser's QR page and the printable posters and slips.
 * Codes are issued through QrTokenService (Task #45) and drawn by the server (Task #46).
 */
class QrPostersSlipsTest extends EventTestCase
{
    private User $organiser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->event->update([
            'qr_enabled' => true,
            'event_starts_at' => now()->addDays(5),
            'event_ends_at' => now()->addDays(5)->addHours(2),
            'application_deadline' => now()->addDays(4),
            'completion_deadline' => now()->addDays(12),
        ]);
        $this->organiser = $this->staff([Capability::PointsCredit, Capability::QrCodesManage], name: 'QR organiser');
    }

    private function url(string $path = ''): string
    {
        return "/events/{$this->event->id}/qr-codes{$path}";
    }

    public function test_one_poster_is_made_through_the_signed_token_service(): void
    {
        $this->actingAs($this->organiser)->post($this->url(), ['type' => 'poster', 'parts' => 1])
            ->assertSessionHas('success', '1 poster code made.');

        $token = QrToken::sole();
        $this->assertSame('poster', $token->type);
        $this->assertSame($this->organiser->id, $token->issuer_id);
        $this->assertNull($token->step_number);
        // The code is the signed kind of Task #45: its scan link resolves back to it.
        $payload = app(QrTokenService::class)->payload($token);
        $this->assertTrue($token->is(app(QrTokenService::class)->resolve($payload)));

        $this->actingAs($this->organiser)->get($this->url())
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Events/QrCodes')
                ->has('tokens', 1)
                ->where('counts.poster', 1)
                ->where('counts.slip', 0)
                ->where('refusal', null));
    }

    public function test_slips_are_made_for_every_part_of_a_multi_part_task(): void
    {
        $this->actingAs($this->organiser)->post($this->url(), ['type' => 'slip', 'copies' => 5, 'parts' => 3])
            ->assertSessionHas('success', '15 slip codes made.');

        $this->assertSame(15, QrToken::where('type', 'slip')->count());
        $this->assertSame([1, 2, 3], QrToken::distinct()->orderBy('step_number')->pluck('step_number')->all());
        $this->assertSame([3], QrToken::distinct()->pluck('total_steps')->all());
    }

    public function test_a_poster_ignores_copies(): void
    {
        $this->actingAs($this->organiser)->post($this->url(), ['type' => 'poster', 'copies' => 40, 'parts' => 2]);

        $this->assertSame(2, QrToken::count());
    }

    public function test_limits_are_enforced(): void
    {
        $this->actingAs($this->organiser)->post($this->url(), ['type' => 'slip', 'copies' => 100, 'parts' => 4])
            ->assertSessionHas('error', 'At most 300 codes can be made at once.');
        $this->actingAs($this->organiser)->post($this->url(), ['type' => 'poster', 'parts' => 11])
            ->assertSessionHasErrors('parts');
        $this->actingAs($this->organiser)->post($this->url(), ['type' => 'slip', 'parts' => 1])
            ->assertSessionHasErrors('copies');

        $this->assertSame(0, QrToken::count());
    }

    public function test_no_codes_for_a_draft_an_event_without_qr_or_one_on_hold(): void
    {
        foreach ([
            [['status' => 'draft', 'published_at' => null], 'Publish the event first, then make its QR codes.'],
            [['status' => 'published', 'qr_enabled' => false], 'QR attendance is switched off for this event. Turn it on in the event to make codes.'],
            [['qr_enabled' => true, 'status' => 'on_hold'], 'This event is on hold. Restore it to make QR codes.'],
        ] as [$state, $reason]) {
            $this->event->update($state);

            $this->actingAs($this->organiser)->get($this->url())
                ->assertInertia(fn (Assert $page) => $page->where('refusal', $reason));
            $this->actingAs($this->organiser)->post($this->url(), ['type' => 'poster', 'parts' => 1])
                ->assertSessionHas('error');
        }

        $this->assertSame(0, QrToken::count());
    }

    public function test_the_print_sheet_lists_working_codes_with_their_server_images(): void
    {
        $this->actingAs($this->organiser)->post($this->url(), ['type' => 'poster', 'parts' => 2]);
        [$first, $second] = QrToken::orderBy('id')->get()->all();

        $this->actingAs($this->organiser)->post($this->url("/{$first->id}/revoke"))->assertSessionHas('success');
        $this->assertNotNull($first->fresh()->revoked_at);

        $this->actingAs($this->organiser)->get($this->url('/print/poster'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Print/EventQrCodes')
                ->where('type', 'poster')
                ->has('codes', 1)
                ->where('codes.0.id', $second->id)
                ->where('codes.0.step_number', 2)
                ->where('codes.0.image', route('events.qr.image', [$this->event, $second, 'svg'])));

        // The image behind the sheet really is an SVG of that code.
        $this->actingAs($this->organiser)->get(route('events.qr.image', [$this->event, $second, 'svg']))
            ->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
    }

    public function test_expired_codes_are_not_printed(): void
    {
        $token = app(QrTokenService::class)->issue($this->organiser, $this->event, 'slip', expiresAt: now()->addDay());
        $this->travel(2)->days();

        $this->actingAs($this->organiser)->get($this->url('/print/slip'))
            ->assertInertia(fn (Assert $page) => $page->has('codes', 0));
        $this->actingAs($this->organiser)->get($this->url())
            ->assertInertia(fn (Assert $page) => $page->where('tokens.0.id', $token->id)->where('tokens.0.expired', true));
    }

    public function test_a_code_of_another_event_cannot_be_revoked_through_this_one(): void
    {
        $other = $this->makeEvent(['qr_enabled' => true]);
        $token = app(QrTokenService::class)->issue($this->organiser, $other);

        $this->actingAs($this->organiser)->post($this->url("/{$token->id}/revoke"))->assertNotFound();
        $this->assertNull($token->fresh()->revoked_at);
    }

    public function test_only_people_with_the_qr_capability_may_use_the_page(): void
    {
        $creditOnly = $this->staff([Capability::PointsCredit], name: 'Crediter');

        foreach (['get' => $this->url(), 'post' => $this->url(), 'print' => $this->url('/print/slip')] as $kind => $url) {
            $response = $kind === 'post'
                ? $this->actingAs($creditOnly)->post($url, ['type' => 'poster', 'parts' => 1])
                : $this->actingAs($creditOnly)->get($url);
            $response->assertRedirect('/');
        }

        // Someone who is not staff of the event does not even learn it exists.
        $this->actingAs($this->student())->get($this->url())->assertNotFound();

        $this->assertSame(0, QrToken::count());
    }

    public function test_the_organised_list_offers_the_qr_page_to_qr_managers_only(): void
    {
        $this->actingAs($this->organiser)->get('/my-events')
            ->assertInertia(fn (Assert $page) => $page->where('events.0.can_qr', true));

        $creditOnly = $this->staff([Capability::PointsCredit], name: 'Crediter');
        $this->actingAs($creditOnly)->get('/my-events')
            ->assertInertia(fn (Assert $page) => $page->where('events.0.can_qr', false));
    }
}

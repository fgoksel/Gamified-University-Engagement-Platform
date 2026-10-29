<?php

namespace Tests\Feature\Events;

use App\Enums\Capability;
use App\Exceptions\EventActionRefused;
use App\Models\PointTransaction;
use App\Models\QrToken;
use App\Models\User;
use App\Services\PointLedger;
use App\Services\QrTokenService;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Task #45: issuing and redeeming event QR codes.
 */
class QrTokenTest extends EventTestCase
{
    private function qr(): QrTokenService
    {
        return app(QrTokenService::class);
    }

    private function issuer(): User
    {
        return $this->staff([Capability::PointsCredit, Capability::QrCodesManage]);
    }

    private function refused(callable $do, string $reason): void
    {
        try {
            $do();
            $this->fail("Expected the refusal '{$reason}'.");
        } catch (EventActionRefused $e) {
            $this->assertSame($reason, $e->reason);
        }
    }

    public function test_issuing_needs_the_qr_capability_and_an_enabled_active_event(): void
    {
        $creatorOnly = $this->staff([Capability::EventsCreate]);
        $this->refused(fn () => $this->qr()->issue($creatorOnly, $this->event), 'forbidden');

        $this->refused(fn () => $this->qr()->issue($this->issuer(), $this->makeEvent(['qr_enabled' => false])), 'qr_disabled');
        $this->refused(fn () => $this->qr()->issue($this->issuer(), $this->makeEvent(['status' => 'on_hold'])), 'inactive');
        $this->refused(fn () => $this->qr()->issue($this->issuer(), $this->makeEvent(['status' => 'draft'])), 'inactive');
        $this->refused(fn () => $this->qr()->issue($this->issuer(), $this->event, 'sticker'), 'invalid_type');
        $this->refused(fn () => $this->qr()->issue($this->issuer(), $this->event, step: 3, totalSteps: 2), 'invalid_steps');
        $this->refused(fn () => $this->qr()->issue($this->issuer(), $this->event, step: 1), 'invalid_steps');
        $this->assertSame(0, QrToken::count());
    }

    public function test_the_code_is_random_and_signed(): void
    {
        $a = $this->qr()->issue($this->issuer(), $this->event);
        $b = $this->qr()->issue($this->issuer(), $this->event);

        $this->assertNotSame($a->token_code, $b->token_code);
        $this->assertGreaterThanOrEqual(40, strlen($a->token_code));
        $this->assertSame($a->id, $this->qr()->resolve($this->qr()->payload($a))->id);
        $this->assertStringStartsWith(url('/qr/redeem/'), $this->qr()->url($a));
    }

    public function test_made_up_altered_and_revoked_codes_are_invalid(): void
    {
        $issuer = $this->issuer();
        $token = $this->qr()->issue($issuer, $this->event);
        $payload = $this->qr()->payload($token);
        [$code, $signature] = explode('.', $payload);

        foreach (['', 'nonsense', $code, $code.'.', $code.'.'.strrev($signature), 'other.'.$signature, $code.'.'.$signature.'x'] as $bad) {
            $this->refused(fn () => $this->qr()->resolve($bad), 'invalid');
        }

        $this->qr()->revoke($issuer, $token);
        $this->refused(fn () => $this->qr()->resolve($payload), 'invalid');
    }

    public function test_a_poster_credits_each_accepted_student_once(): void
    {
        $issuer = $this->issuer();
        $payload = $this->qr()->payload($this->qr()->issue($issuer, $this->event));
        $a = $this->student();
        $b = $this->student();
        $this->accepted($a);
        $this->accepted($b);

        $result = $this->qr()->redeem($a, $payload);
        $this->qr()->redeem($b, $payload);

        $this->assertSame('credited', $result['outcome']);
        $this->assertSame(10, $result['points']);
        $row = PointTransaction::where('student_id', $a->id)->first();
        $this->assertSame('qr_poster', $row->source);
        $this->assertSame($issuer->id, $row->credited_by_id);
        $this->assertDatabaseHas('qr_redemptions', ['student_id' => $a->id, 'point_transaction_id' => $row->id]);

        $this->refused(fn () => $this->qr()->redeem($a, $payload), 'already_claimed');
        $this->assertSame(2, PointTransaction::count());
    }

    public function test_a_slip_can_be_used_by_one_student_only(): void
    {
        $payload = $this->qr()->payload($this->qr()->issue($this->issuer(), $this->event, QrToken::SLIP));
        $a = $this->student();
        $b = $this->student();
        $this->accepted($a);
        $this->accepted($b);

        $this->qr()->redeem($a, $payload);

        $this->refused(fn () => $this->qr()->redeem($b, $payload), 'already_used');
        $this->refused(fn () => $this->qr()->redeem($a, $payload), 'already_claimed');
        $this->assertSame(1, PointTransaction::count());
        $this->assertSame('qr_slip', PointTransaction::first()->source);
    }

    public function test_the_credit_is_single_even_when_the_organiser_already_credited_the_student(): void
    {
        $payload = $this->qr()->payload($this->qr()->issue($this->issuer(), $this->event));
        $student = $this->student();
        $this->accepted($student);
        app(PointLedger::class)->credit($this->event, $student, $this->staff([]), 'manual');

        $this->refused(fn () => $this->qr()->redeem($student, $payload), 'already_credited');

        // The failed attempt left no redemption behind, so nothing half-done remains.
        $this->assertDatabaseCount('qr_redemptions', 0);
        $this->assertSame(1, PointTransaction::count());
    }

    public function test_a_code_cannot_be_redeemed_by_the_person_who_issued_it(): void
    {
        $tutor = $this->student();
        $this->assign($tutor, $this->role('Tutor', [Capability::View, Capability::EventsView, Capability::PointsCredit, Capability::QrCodesManage]), $this->course);
        $this->accepted($tutor);
        $payload = $this->qr()->payload($this->qr()->issue($tutor, $this->event));

        $this->refused(fn () => $this->qr()->redeem($tutor, $payload), 'own_code');
        $this->assertSame(0, PointTransaction::count());
    }

    public function test_whoever_helps_run_the_event_cannot_redeem_its_codes(): void
    {
        // A student tutor (team rule), also accepted, scanning the teacher's poster code.
        $tutor = $this->student();
        $this->assign($tutor, $this->role('Tutor', [Capability::View, Capability::EventsView, Capability::PointsCredit]), $this->course);
        $this->accepted($tutor);
        $payload = $this->qr()->payload($this->qr()->issue($this->issuer(), $this->event));

        $this->refused(fn () => $this->qr()->redeem($tutor, $payload), 'runs_event');

        // The refusal undid the redemption, so the code is not used up and nothing half-done remains.
        $this->assertDatabaseCount('qr_redemptions', 0);
        $this->assertSame(0, PointTransaction::count());
    }

    public function test_only_accepted_students_can_redeem_and_a_scan_never_applies(): void
    {
        $payload = $this->qr()->payload($this->qr()->issue($this->issuer(), $this->event));
        $stranger = $this->student();
        $pending = $this->student();
        $this->accepted($pending, status: 'pending');

        $this->refused(fn () => $this->qr()->redeem($stranger, $payload), 'not_registered');
        $this->refused(fn () => $this->qr()->redeem($pending, $payload), 'not_registered');
        $this->assertDatabaseMissing('event_applications', ['student_id' => $stranger->id]);
        $this->assertSame(0, PointTransaction::count());
    }

    public function test_expiry_event_state_and_deadline_are_enforced(): void
    {
        $issuer = $this->issuer();
        $student = $this->student();
        $this->accepted($student);

        $expiring = $this->qr()->issue($issuer, $this->event, expiresAt: now()->addMinute());
        $this->travel(2)->minutes();
        $this->refused(fn () => $this->qr()->redeem($student, $this->qr()->payload($expiring)), 'expired');
        $this->travelBack();

        $payload = $this->qr()->payload($this->qr()->issue($issuer, $this->event));
        $this->event->update(['completion_deadline' => now()->subMinute()]);
        $this->refused(fn () => $this->qr()->redeem($student, $payload), 'deadline_passed');

        $this->event->update(['completion_deadline' => now()->addDay(), 'status' => 'cancelled']);
        $this->refused(fn () => $this->qr()->redeem($student, $payload), 'inactive');

        $this->event->update(['status' => 'on_hold']);
        $this->refused(fn () => $this->qr()->redeem($student, $payload), 'inactive');

        $this->event->update(['status' => 'published', 'qr_enabled' => false]);
        $this->refused(fn () => $this->qr()->redeem($student, $payload), 'inactive');
        $this->assertSame(0, PointTransaction::count());
    }

    public function test_a_task_of_several_parts_credits_once_when_every_part_is_done(): void
    {
        $issuer = $this->issuer();
        $codes = collect([1, 2, 3])->map(fn ($step) => $this->qr()->payload($this->qr()->issue($issuer, $this->event, step: $step, totalSteps: 3)));
        $student = $this->student();
        $this->accepted($student);

        $first = $this->qr()->redeem($student, $codes[2]);
        $this->assertSame('progress', $first['outcome']);
        $this->assertSame(0, $first['points']);
        $this->assertSame(0, PointTransaction::count());

        $this->refused(fn () => $this->qr()->redeem($student, $codes[2]), 'already_claimed');
        $this->assertSame('progress', $this->qr()->redeem($student, $codes[0])['outcome']);
        $this->assertSame(0, PointTransaction::count());

        $last = $this->qr()->redeem($student, $codes[1]);
        $this->assertSame('credited', $last['outcome']);
        $this->assertSame(1, PointTransaction::count());
        $this->assertSame('milestone', PointTransaction::first()->source);
        $this->assertDatabaseCount('event_milestone_progress', 3);
    }

    public function test_the_same_part_cannot_be_counted_twice_through_another_code(): void
    {
        $issuer = $this->issuer();
        $one = $this->qr()->payload($this->qr()->issue($issuer, $this->event, step: 1, totalSteps: 2));
        $oneAgain = $this->qr()->payload($this->qr()->issue($issuer, $this->event, step: 1, totalSteps: 2));
        $student = $this->student();
        $this->accepted($student);

        $this->qr()->redeem($student, $one);

        $this->refused(fn () => $this->qr()->redeem($student, $oneAgain), 'already_claimed');
        $this->assertSame(0, PointTransaction::count());
        $this->assertDatabaseCount('event_milestone_progress', 1);
    }

    public function test_the_http_endpoint_redeems_and_reports_refusals(): void
    {
        $payload = $this->qr()->payload($this->qr()->issue($this->issuer(), $this->event));
        $student = $this->student();
        $this->accepted($student);

        $this->actingAs($student)->postJson('/qr/redeem', ['code' => $payload])
            ->assertOk()
            ->assertJsonPath('outcome', 'credited')
            ->assertJsonPath('points', 10);

        $this->actingAs($student)->postJson('/qr/redeem', ['code' => $payload])
            ->assertStatus(409)->assertJsonPath('reason', 'already_claimed');

        $this->actingAs($student)->postJson('/qr/redeem', ['code' => 'bogus.code'])
            ->assertUnprocessable()->assertJsonPath('message', 'Invalid QR code.');

        $this->actingAs($student)->postJson('/qr/redeem', [])->assertUnprocessable();
    }

    public function test_only_students_with_the_redeem_permission_reach_the_endpoint(): void
    {
        $payload = $this->qr()->payload($this->qr()->issue($this->issuer(), $this->event));

        $this->postJson('/qr/redeem', ['code' => $payload])->assertUnauthorized();
        $this->actingAs($this->admin)->postJson('/qr/redeem', ['code' => $payload])->assertForbidden();
        $this->assertSame(0, PointTransaction::count());
    }

    public function test_opening_a_code_shows_the_scan_page_and_gives_no_points(): void
    {
        $payload = $this->qr()->payload($this->qr()->issue($this->issuer(), $this->event));
        $student = $this->student();
        $this->accepted($student);

        $this->actingAs($student)->get("/qr/redeem/{$payload}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Scan/Index')->where('code', $payload));

        $this->assertSame(0, PointTransaction::count());
    }

    public function test_redemption_is_limited_per_student_not_per_address(): void
    {
        $a = $this->student();
        $b = $this->student();

        for ($i = 0; $i < 60; $i++) {
            $this->actingAs($a)->postJson('/qr/redeem', ['code' => 'x'])->assertUnprocessable();
        }

        $this->actingAs($a)->postJson('/qr/redeem', ['code' => 'x'])->assertTooManyRequests();
        $this->actingAs($b)->postJson('/qr/redeem', ['code' => 'x'])->assertUnprocessable();
    }
}

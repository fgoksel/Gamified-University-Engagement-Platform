<?php

namespace Tests\Feature\Events;

use App\Enums\Capability;
use App\Exceptions\EventActionRefused;
use App\Models\PointTransaction;
use App\Models\QrToken;
use App\Models\User;
use App\Services\PointLedger;
use App\Services\QrTokenService;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Tasks #45 and #84: issuing printed single-use slips and redeeming them.
 * A slip carries the event and its points, is not tied to a person, can be
 * handed on, works once in total and never changes (26 Oct, Q5).
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

    private function slip(?User $issuer = null): string
    {
        return $this->qr()->payload($this->qr()->issue($issuer ?? $this->issuer(), $this->event));
    }

    private function refused(callable $do, string $reason, ?string $message = null): void
    {
        try {
            $do();
            $this->fail("Expected the refusal '{$reason}'.");
        } catch (EventActionRefused $e) {
            $this->assertSame($reason, $e->reason);
            if ($message !== null) {
                $this->assertSame($message, $e->getMessage());
            }
        }
    }

    public function test_slips_are_made_only_by_qr_managers_for_events_whose_slips_could_give_points(): void
    {
        $creatorOnly = $this->staff([Capability::EventsCreate]);
        $this->refused(fn () => $this->qr()->issue($creatorOnly, $this->event), 'forbidden');
        $this->refused(fn () => $this->qr()->makeSlips($creatorOnly, $this->event, 5), 'forbidden');

        $this->refused(fn () => $this->qr()->issue($this->issuer(), $this->makeEvent(['qr_enabled' => false])), 'qr_disabled');
        $this->refused(fn () => $this->qr()->issue($this->issuer(), $this->makeEvent(['status' => 'on_hold'])), 'inactive');
        $this->refused(fn () => $this->qr()->issue($this->issuer(), $this->makeEvent(['status' => 'draft'])), 'inactive');
        $this->refused(fn () => $this->qr()->issue($this->issuer(), $this->makeEvent(['is_hidden' => true])), 'surprise');
        // After the points end date a new slip could never be used.
        $this->refused(fn () => $this->qr()->makeSlips($this->issuer(), $this->makeEvent(['completion_deadline' => now()->subMinute()]), 5), 'deadline_passed');

        $this->refused(fn () => $this->qr()->makeSlips($this->issuer(), $this->event, 0), 'invalid_count');
        $this->refused(fn () => $this->qr()->makeSlips($this->issuer(), $this->event, QrTokenService::MAX_SLIPS + 1), 'invalid_count');
        $this->assertSame(0, QrToken::count());
    }

    public function test_a_batch_has_exactly_the_requested_number_of_random_signed_slips(): void
    {
        $issuer = $this->issuer();

        $first = $this->qr()->makeSlips($issuer, $this->event, 5);
        $second = $this->qr()->makeSlips($issuer, $this->event, 2);

        $this->assertCount(5, $first);
        $this->assertCount(2, $second);
        $this->assertSame(7, QrToken::where('type', QrToken::SLIP)->whereNull('total_steps')->count());
        // Each batch has a key of its own, shared by its slips.
        $this->assertCount(1, $first->pluck('batch')->unique());
        $this->assertTrue(Str::isUuid($first->first()->batch));
        $this->assertNotSame($first->first()->batch, $second->first()->batch);
        $this->assertSame($issuer->id, $first->first()->issuer_id);

        $this->assertCount(7, $first->concat($second)->pluck('token_code')->unique());
        $this->assertGreaterThanOrEqual(40, strlen($first->first()->token_code));
        $this->assertSame($first->first()->id, $this->qr()->resolve($this->qr()->payload($first->first()))->id);
        $this->assertStringStartsWith(url('/qr/redeem/'), $this->qr()->url($first->first()));
    }

    public function test_made_up_and_altered_codes_are_invalid_and_a_withdrawn_slip_says_so(): void
    {
        $issuer = $this->issuer();
        $token = $this->qr()->issue($issuer, $this->event);
        $payload = $this->qr()->payload($token);
        [$code, $signature] = explode('.', $payload);

        foreach (['', 'nonsense', $code, $code.'.', $code.'.'.strrev($signature), 'other.'.$signature, $code.'.'.$signature.'x'] as $bad) {
            $this->refused(fn () => $this->qr()->resolve($bad), 'invalid');
        }

        $this->qr()->revoke($issuer, $token);
        $this->refused(fn () => $this->qr()->resolve($payload), 'withdrawn', 'The organiser has withdrawn this code, so it no longer works.');
    }

    public function test_a_slip_works_once_in_total(): void
    {
        $issuer = $this->issuer();
        $payload = $this->slip($issuer);
        $a = $this->student();
        $b = $this->student();
        $this->accepted($a);
        $this->accepted($b);

        $result = $this->qr()->redeem($a, $payload);

        $this->assertSame('credited', $result['outcome']);
        $this->assertSame(10, $result['points']);
        $row = PointTransaction::sole();
        $this->assertSame('qr_slip', $row->source);
        $this->assertSame($a->id, $row->student_id);
        $this->assertSame($issuer->id, $row->credited_by_id);
        $this->assertDatabaseHas('qr_redemptions', ['student_id' => $a->id, 'point_transaction_id' => $row->id]);

        // A used slip says so clearly, to its owner and to anyone it is passed to.
        $this->refused(fn () => $this->qr()->redeem($b, $payload), 'already_used', 'This slip has already been used. Each slip works only once.');
        $this->refused(fn () => $this->qr()->redeem($a, $payload), 'already_claimed', 'You have already used this slip.');
        $this->assertSame(1, PointTransaction::count());
    }

    public function test_a_slip_is_not_tied_to_a_person_and_can_be_handed_on(): void
    {
        $payload = $this->slip();
        $stranger = $this->student();
        $pending = $this->student();
        $this->accepted($pending, status: 'pending');
        $friend = $this->student();
        $this->accepted($friend);

        // Points still go only to accepted students, and a refused scan leaves the slip unused...
        $message = 'You are not registered or selected for this event. The slip has not been used.';
        $this->refused(fn () => $this->qr()->redeem($stranger, $payload), 'not_registered', $message);
        $this->refused(fn () => $this->qr()->redeem($pending, $payload), 'not_registered', $message);
        $this->assertDatabaseMissing('event_applications', ['student_id' => $stranger->id]);
        $this->assertDatabaseCount('qr_redemptions', 0);

        // ...so it can be handed on to an accepted student, who gets the points.
        $this->assertSame('credited', $this->qr()->redeem($friend, $payload)['outcome']);
        $this->assertSame($friend->id, PointTransaction::sole()->student_id);
    }

    public function test_a_student_who_already_has_the_points_leaves_the_slip_unused(): void
    {
        $payload = $this->slip();
        $student = $this->student();
        $this->accepted($student);
        app(PointLedger::class)->credit($this->event, $student, $this->staff([]), PointLedger::PASSPORT);

        $this->refused(fn () => $this->qr()->redeem($student, $payload), 'already_credited', 'You already have the points for this event. The slip has not been used.');

        // The failed attempt left no claim behind, so another student can still use the slip.
        $this->assertDatabaseCount('qr_redemptions', 0);
        $other = $this->student();
        $this->accepted($other);
        $this->assertSame('credited', $this->qr()->redeem($other, $payload)['outcome']);
        $this->assertSame(2, PointTransaction::count());
    }

    public function test_a_slip_cannot_be_redeemed_by_the_person_who_issued_it(): void
    {
        $tutor = $this->student();
        $this->assign($tutor, $this->role('Tutor', [Capability::View, Capability::EventsView, Capability::PointsCredit, Capability::QrCodesManage]), $this->course);
        $this->accepted($tutor);

        $this->refused(fn () => $this->qr()->redeem($tutor, $this->slip($tutor)), 'own_code');
        $this->assertSame(0, PointTransaction::count());
    }

    public function test_expiry_event_state_and_the_points_window_are_enforced(): void
    {
        $issuer = $this->issuer();
        $student = $this->student();
        $this->accepted($student);

        $expiring = $this->qr()->issue($issuer, $this->event, now()->addMinute());
        $this->travel(2)->minutes();
        $this->refused(fn () => $this->qr()->redeem($student, $this->qr()->payload($expiring)), 'expired', 'This slip has expired.');
        $this->travelBack();

        $payload = $this->slip($issuer);

        // No points before the event starts (26 Oct, Q3); the slip keeps working afterwards.
        $this->event->update(['event_starts_at' => now()->addHour(), 'event_ends_at' => now()->addHours(2)]);
        $this->refused(fn () => $this->qr()->redeem($student, $payload), 'not_started');
        $this->event->update(['event_starts_at' => now()->subHour(), 'event_ends_at' => now()->addHour()]);

        $this->event->update(['completion_deadline' => now()->subMinute()]);
        $this->refused(fn () => $this->qr()->redeem($student, $payload), 'deadline_passed');

        $this->event->update(['completion_deadline' => now()->addDay(), 'status' => 'cancelled']);
        $this->refused(fn () => $this->qr()->redeem($student, $payload), 'inactive');

        $this->event->update(['status' => 'on_hold']);
        $this->refused(fn () => $this->qr()->redeem($student, $payload), 'inactive');

        $this->event->update(['status' => 'published', 'qr_enabled' => false]);
        $this->refused(fn () => $this->qr()->redeem($student, $payload), 'inactive');
        $this->assertSame(0, PointTransaction::count());
        $this->assertDatabaseCount('qr_redemptions', 0);

        // A completed event still takes slips until its points end date.
        $this->event->update(['status' => 'completed', 'qr_enabled' => true]);
        $this->assertSame('credited', $this->qr()->redeem($student, $payload)['outcome']);
    }

    public function test_posters_and_parts_of_a_task_from_before_no_longer_work(): void
    {
        $issuer = $this->issuer();
        $student = $this->student();
        $this->accepted($student);
        $old = fn (array $data) => QrToken::create(['event_id' => $this->event->id, 'issuer_id' => $issuer->id, 'token_code' => Str::random(48), ...$data]);

        $retired = 'This printed code is no longer used. Scan the code shown at the event, or a printed slip.';
        foreach ([['type' => QrToken::POSTER], ['type' => QrToken::SLIP, 'step_number' => 1, 'total_steps' => 3]] as $data) {
            $this->refused(fn () => $this->qr()->redeem($student, $this->qr()->payload($old($data))), 'retired', $retired);
        }

        $this->assertSame(0, PointTransaction::count());
        $this->assertDatabaseCount('qr_redemptions', 0);

        // A plain slip made before batches still works.
        $this->assertSame('credited', $this->qr()->redeem($student, $this->qr()->payload($old(['type' => QrToken::SLIP])))['outcome']);
    }

    public function test_the_http_endpoint_redeems_and_reports_refusals(): void
    {
        $payload = $this->slip();
        $student = $this->student();
        $this->accepted($student);

        $this->actingAs($student)->postJson('/qr/redeem', ['code' => $payload])
            ->assertOk()
            ->assertJsonPath('outcome', 'credited')
            ->assertJsonPath('points', 10)
            ->assertJsonPath('category', 'academic');

        $this->actingAs($student)->postJson('/qr/redeem', ['code' => $payload])
            ->assertStatus(409)
            ->assertJsonPath('reason', 'already_claimed')
            ->assertJsonPath('message', 'You have already used this slip.');

        $this->actingAs($student)->postJson('/qr/redeem', ['code' => 'bogus.code'])
            ->assertUnprocessable()->assertJsonPath('message', 'Invalid QR code.');

        $this->actingAs($student)->postJson('/qr/redeem', [])->assertUnprocessable();
    }

    public function test_only_students_with_the_redeem_permission_reach_the_endpoint(): void
    {
        $payload = $this->slip();

        $this->postJson('/qr/redeem', ['code' => $payload])->assertUnauthorized();
        $this->actingAs($this->admin)->postJson('/qr/redeem', ['code' => $payload])->assertForbidden();
        $this->assertSame(0, PointTransaction::count());
    }

    public function test_opening_a_code_shows_the_scan_page_and_gives_no_points(): void
    {
        $payload = $this->slip();
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

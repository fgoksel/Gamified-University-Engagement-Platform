<?php

namespace Tests\Feature\Events;

use App\Enums\Capability;
use App\Models\PointTransaction;
use App\Models\User;
use App\Services\PointLedger;
use App\Services\QrPassport;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Task #83: no typed codes and no manual crediting (UC-1.7, UC-2.3).
 *
 * Attendance is credited from a scanned QR code only. A broken phone is not a special case
 * (the student signs in on another device) and a missed scan is solved by extending the
 * points period. What was credited before stays in the ledger.
 */
class NoManualCreditTest extends EventTestCase
{
    private User $organiser;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organiser = $this->staff([Capability::PointsCredit], name: 'Organiser');
        $this->student = $this->student('Sam Student', 'SAM001');
        $this->accepted($this->student);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function credit(string $identifier, array $extra = [])
    {
        return $this->actingAs($this->organiser)
            ->postJson("/events/{$this->event->id}/credit", ['identifier' => $identifier, ...$extra]);
    }

    public function test_the_checklist_endpoint_is_gone(): void
    {
        $this->assertFalse(Route::has('events.credit.bulk'));

        $this->actingAs($this->organiser)
            ->postJson("/events/{$this->event->id}/credit/bulk", ['student_ids' => [$this->student->id]])
            ->assertNotFound();

        $this->assertDatabaseCount('point_transactions', 0);
    }

    public function test_a_typed_neptun_code_credits_nobody(): void
    {
        foreach ([
            'SAM001',
            'sam001',
            json_encode(['neptun_code' => 'SAM001']),
            json_encode(['neptun' => 'sam001']),
        ] as $typed) {
            $this->credit($typed)
                ->assertOk()
                ->assertJson(['status' => 'passport_invalid', 'student' => ['id' => 0]]);
        }

        $this->assertDatabaseCount('point_transactions', 0);
    }

    public function test_an_id_or_a_pick_from_a_list_credits_nobody(): void
    {
        foreach ([
            (string) $this->student->id,
            json_encode(['student_id' => $this->student->id]),
            "https://example.test/qr/passport/{$this->student->id}",
        ] as $guess) {
            $this->credit($guess, ['source' => 'manual'])
                ->assertOk()
                ->assertJson(['status' => 'passport_invalid']);
        }

        $this->assertDatabaseCount('point_transactions', 0);
    }

    public function test_a_source_sent_by_an_old_page_cannot_make_a_manual_credit(): void
    {
        $this->credit(app(QrPassport::class)->issue($this->student), ['source' => 'manual'])
            ->assertOk()
            ->assertJson(['status' => 'credited']);

        $this->assertDatabaseHas('point_transactions', ['student_id' => $this->student->id, 'source' => 'passport']);
        $this->assertDatabaseMissing('point_transactions', ['source' => 'manual']);
    }

    public function test_the_scanner_page_carries_no_list_of_students(): void
    {
        $this->actingAs($this->organiser)->get("/events/{$this->event->id}/scan")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Events/Scan')
                ->where('stats.accepted_count', 1)
                ->where('stats.credited_count', 0)
                ->missing('students'));
    }

    public function test_rows_written_before_stay_in_the_ledger_and_still_block_a_second_credit(): void
    {
        app(PointLedger::class)->credit($this->event, $this->student, $this->organiser, PointLedger::MANUAL);

        $this->credit(app(QrPassport::class)->issue($this->student))
            ->assertOk()
            ->assertJson(['status' => 'already_credited']);

        $this->actingAs($this->organiser)->get("/events/{$this->event->id}/scan")
            ->assertInertia(fn (Assert $page) => $page->where('stats.credited_count', 1));

        $this->assertSame(1, PointTransaction::query()->where('student_id', $this->student->id)->count());
        $this->assertDatabaseHas('point_transactions', ['student_id' => $this->student->id, 'source' => 'manual']);
    }
}

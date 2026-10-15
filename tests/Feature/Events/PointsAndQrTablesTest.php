<?php

namespace Tests\Feature\Events;

use App\Enums\UserRole;
use App\Models\Event;
use App\Models\EventApplication;
use App\Models\PointTransaction;
use App\Models\QrToken;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Topics\TopicTestCase;

/**
 * Task #7: the points ledger, QR token, redemption and milestone progress tables.
 */
class PointsAndQrTablesTest extends TopicTestCase
{
    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();

        $topic = $this->topic('Databases', $this->topic('Faculty'));
        $this->event = Event::factory()->create(['owning_topic_id' => $topic->id, 'audience_topic_id' => $topic->id]);
    }

    private function student(): User
    {
        $student = $this->makeUser(UserRole::Student);
        $student->forceFill(['neptun_code' => strtoupper(substr(md5(uniqid()), 0, 6))])->save();

        return $student;
    }

    private function ledgerRow(User $student, int $points = 10, string $type = 'credit', array $extra = []): PointTransaction
    {
        return PointTransaction::create([
            'student_id' => $student->id, 'event_id' => $this->event->id, 'semester_id' => $this->event->semester_id,
            'credited_by_id' => $this->admin->id, 'points' => $points, 'type' => $type, 'source' => 'manual',
            'category' => 'academic', ...$extra,
        ]);
    }

    private function token(string $code, string $type = 'poster'): QrToken
    {
        return QrToken::create(['event_id' => $this->event->id, 'issuer_id' => $this->admin->id, 'token_code' => $code, 'type' => $type]);
    }

    public function test_the_tables_and_key_columns_exist(): void
    {
        foreach (['point_transactions', 'qr_tokens', 'qr_redemptions', 'event_milestone_progress'] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }

        $this->assertTrue(Schema::hasColumns('point_transactions', [
            'student_id', 'event_id', 'semester_id', 'credited_by_id', 'points', 'type', 'source', 'category', 'reversal_of_id', 'reversal_reason', 'created_at',
        ]));
        $this->assertTrue(Schema::hasColumns('qr_tokens', [
            'event_id', 'issuer_id', 'token_code', 'type', 'step_number', 'total_steps', 'expires_at', 'revoked_at',
        ]));
        $this->assertTrue(Schema::hasColumns('qr_redemptions', [
            'qr_token_id', 'student_id', 'step_number', 'redeemed_at', 'point_transaction_id', 'slip_claim_id',
        ]));
        $this->assertFalse(Schema::hasColumn('users', 'points'), 'Points are summed from the ledger, never stored as a balance.');
    }

    public function test_the_ledger_and_tokens_belong_to_their_event(): void
    {
        $student = $this->student();
        $row = $this->ledgerRow($student);
        $token = $this->token('abc');

        $this->assertSame([$row->id], $this->event->pointTransactions->pluck('id')->all());
        $this->assertSame([$token->id], $this->event->qrTokens->pluck('id')->all());
        $this->assertSame($student->id, $row->student->id);
    }

    public function test_the_database_allows_one_base_credit_per_student_and_event(): void
    {
        $student = $this->student();
        $this->ledgerRow($student);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->ledgerRow($student, extra: ['source' => 'qr_poster']);
    }

    public function test_a_reversal_does_not_collide_with_the_credit_but_can_happen_only_once(): void
    {
        $student = $this->student();
        $credit = $this->ledgerRow($student);

        $this->ledgerRow($student, -10, 'reversal', ['reversal_of_id' => $credit->id, 'reversal_reason' => 'Mistake']);
        $this->assertSame(0, (int) PointTransaction::where('student_id', $student->id)->sum('points'));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->ledgerRow($student, -10, 'reversal', ['reversal_of_id' => $credit->id, 'reversal_reason' => 'Again']);
    }

    public function test_an_event_with_points_cannot_be_deleted(): void
    {
        $this->ledgerRow($this->student());

        $this->expectException(QueryException::class);
        $this->event->delete();
    }

    public function test_a_token_code_is_unique(): void
    {
        $this->token('same');

        $this->expectException(UniqueConstraintViolationException::class);
        $this->token('same');
    }

    public function test_a_slip_can_be_claimed_once_in_total_and_a_poster_once_per_student(): void
    {
        $slip = $this->token('slip-code', 'slip');
        $a = $this->student();
        $b = $this->student();

        DB::table('qr_redemptions')->insert(['qr_token_id' => $slip->id, 'student_id' => $a->id, 'slip_claim_id' => $slip->id]);

        try {
            DB::table('qr_redemptions')->insert(['qr_token_id' => $slip->id, 'student_id' => $b->id, 'slip_claim_id' => $slip->id]);
            $this->fail('A slip was claimed twice.');
        } catch (UniqueConstraintViolationException) {
        }

        $poster = $this->token('poster-code');
        DB::table('qr_redemptions')->insert(['qr_token_id' => $poster->id, 'student_id' => $a->id]);
        DB::table('qr_redemptions')->insert(['qr_token_id' => $poster->id, 'student_id' => $b->id]);

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('qr_redemptions')->insert(['qr_token_id' => $poster->id, 'student_id' => $a->id]);
    }

    public function test_each_part_of_a_multi_part_task_is_recorded_once_per_student(): void
    {
        $student = $this->student();
        $token = $this->token('part-1');
        EventApplication::create(['event_id' => $this->event->id, 'student_id' => $student->id, 'status' => 'accepted', 'applied_at' => now()]);
        $row = ['event_id' => $this->event->id, 'student_id' => $student->id, 'step_number' => 1, 'qr_token_id' => $token->id];

        DB::table('event_milestone_progress')->insert($row);
        DB::table('event_milestone_progress')->insert([...$row, 'step_number' => 2]);

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('event_milestone_progress')->insert($row);
    }
}

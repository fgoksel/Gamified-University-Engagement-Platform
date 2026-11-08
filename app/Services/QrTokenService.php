<?php

namespace App\Services;

use App\Exceptions\EventActionRefused;
use App\Models\Event;
use App\Models\EventApplication;
use App\Models\QrRedemption;
use App\Models\QrToken;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Printed QR slips: issuing them and redeeming them for points.
 *
 * Since Task #84 (26 Oct, Q5) the printed slip is the only printed code: for an event
 * with no screen, where the live code of Task #80 cannot be shown. A slip carries the
 * event and its points, is not tied to a person, can be handed on, works once in total
 * and never changes. Posters and the parts of a task (Task #44) no longer work.
 *
 * What is printed is "<random token>.<signature>". The signature is an HMAC of
 * the token with the application key, so a made-up or altered code is refused
 * before the database is even asked. Redeeming never gives points by itself:
 * the shared ledger still requires an accepted participant, an open event and
 * a single base credit.
 */
class QrTokenService
{
    /** The most slips one batch may have, to keep a print run reasonable. */
    public const MAX_SLIPS = 300;

    public function __construct(private PointLedger $ledger, private LiveAttendanceQr $live) {}

    /**
     * Make a batch of slips for an event, all or nothing. The batch prints on its own.
     *
     * @return Collection<int, QrToken>
     *
     * @throws EventActionRefused
     */
    public function makeSlips(User $issuer, Event $event, int $count): Collection
    {
        $this->assertCanIssue($issuer, $event);

        if ($count < 1 || $count > self::MAX_SLIPS) {
            throw new EventActionRefused('invalid_count', __('qr.token.make_between', ['max' => self::MAX_SLIPS]));
        }

        $batch = (string) Str::uuid();

        return DB::transaction(fn () => collect(range(1, $count))
            ->map(fn () => $this->create($issuer, $event, $batch, null)));
    }

    /**
     * Make one slip, a batch of its own. The issuer needs the QR capability at the event's topic.
     *
     * @throws EventActionRefused
     */
    public function issue(User $issuer, Event $event, ?CarbonInterface $expiresAt = null): QrToken
    {
        $this->assertCanIssue($issuer, $event);

        return $this->create($issuer, $event, (string) Str::uuid(), $expiresAt);
    }

    public function revoke(User $actor, QrToken $token): void
    {
        if (! Gate::forUser($actor)->allows('manageQr', $token->event)) {
            throw new EventActionRefused('forbidden', __('qr.token.you_cannot_manage_qr_codes'), 403);
        }

        $token->forceFill(['revoked_at' => $token->revoked_at ?? now()])->save();
    }

    /**
     * The text stored in the QR image.
     */
    public function payload(QrToken $token): string
    {
        return $token->token_code.'.'.$this->signature($token->token_code);
    }

    /**
     * The link stored in the QR image, so a phone camera opens the scan page directly.
     */
    public function url(QrToken $token): string
    {
        return route('qr.show', ['code' => $this->payload($token)]);
    }

    /**
     * @throws EventActionRefused
     */
    public function resolve(string $payload): QrToken
    {
        $invalid = new EventActionRefused('invalid', __('qr.token.invalid_qr_code'));
        [$code, $signature] = array_pad(explode('.', trim($payload), 2), 2, '');

        if ($code === '' || ! hash_equals($this->signature($code), $signature)) {
            throw $invalid;
        }

        $token = QrToken::query()->where('token_code', $code)->with('event.owningTopic')->first();

        if ($token === null) {
            throw $invalid;
        }

        // The signature proves that the code was issued, so saying why it stopped working gives nothing away.
        if ($token->revoked_at !== null) {
            throw new EventActionRefused('withdrawn', __('qr.token.withdrawn'), 409);
        }

        return $token;
    }

    /**
     * Redeem a scanned code for the signed-in student.
     *
     * A refused slip stays unused: nothing is written until the points are, in one
     * transaction. So a student who cannot use it can still hand it on.
     *
     * @return array{outcome: string, message: string, event: string, points: int, category: string, step: ?int, total_steps: ?int}
     *
     * @throws EventActionRefused
     */
    public function redeem(User $student, string $payload): array
    {
        // The code the organiser shows live during the event (Task #80) is not stored as a token.
        if ($this->live->looksLikeLiveCode($payload)) {
            return $this->live->redeem($student, $payload);
        }

        $token = $this->resolve($payload);
        $event = $token->event;

        if (! $token->isSlip()) {
            throw new EventActionRefused('retired', __('qr.token.retired'), 409);
        }

        if ($student->is($token->issuer)) {
            throw new EventActionRefused('own_code', __('qr.token.you_cannot_redeem_a_code'), 403);
        }

        if (! $event->qr_enabled || $event->isOnHold() || ! in_array($event->status, [Event::STATUS_PUBLISHED, Event::STATUS_COMPLETED], true)) {
            throw new EventActionRefused('inactive', __('qr.token.this_event_is_no_longer'), 409);
        }

        if ($token->expires_at !== null && $token->expires_at->isPast()) {
            throw new EventActionRefused('expired', __('qr.token.this_qr_code_has_expired'), 409);
        }

        if ($refusal = $this->ledger->refusalFor($event)) {
            throw $refusal;
        }

        $accepted = EventApplication::query()
            ->where('event_id', $event->id)
            ->where('student_id', $student->id)
            ->where('status', EventApplication::ACCEPTED)
            ->exists();

        if (! $accepted) {
            throw new EventActionRefused('not_registered', __('qr.token.you_are_not_registered'), 403);
        }

        try {
            return DB::transaction(fn () => $this->claim($token, $event, $student));
        } catch (UniqueConstraintViolationException) {
            throw $this->used($token, $student);
        } catch (EventActionRefused $refused) {
            // The ledger speaks to the organiser ("Points have already been credited to Anna"); this is the student.
            throw match ($refused->reason) {
                'already_credited' => new EventActionRefused('already_credited', __('qr.token.already_credited'), 409),
                // Only if the application changed since the check above.
                'not_registered' => new EventActionRefused('not_registered', __('qr.token.you_are_not_registered'), 403),
                default => $refused,
            };
        }
    }

    /**
     * @return array{outcome: string, message: string, event: string, points: int, category: string, step: ?int, total_steps: ?int}
     */
    private function claim(QrToken $token, Event $event, User $student): array
    {
        if (QrRedemption::query()->where('qr_token_id', $token->id)->exists()) {
            throw $this->used($token, $student);
        }

        $redemption = QrRedemption::create([
            'qr_token_id' => $token->id,
            'student_id' => $student->id,
            'redeemed_at' => now(),
            // One claim in total: the unique index lets only the first insert through.
            'slip_claim_id' => $token->id,
        ]);

        $row = $this->ledger->credit($event, $student, $token->issuer, PointLedger::QR_SLIP);
        $redemption->forceFill(['point_transaction_id' => $row->id])->save();

        return [
            'outcome' => 'credited',
            'message' => __('qr.token.completion_recorded', ['points' => $row->points]),
            'event' => $event->title,
            'points' => $row->points,
            'category' => $row->category,
            'step' => null,
            'total_steps' => null,
        ];
    }

    /**
     * The slip has a claim already. Work out whose, from the data and not from the database's wording.
     */
    private function used(QrToken $token, User $student): EventActionRefused
    {
        $own = QrRedemption::query()->where('qr_token_id', $token->id)->where('student_id', $student->id)->exists();

        return $own
            ? new EventActionRefused('already_claimed', __('qr.token.you_have_already_used_this_slip'), 409)
            : new EventActionRefused('already_used', __('qr.token.this_slip_has_already_been_used'), 409);
    }

    /**
     * The rules for making slips: the QR capability at the event's topic, and an event
     * whose slips could still give points.
     *
     * @throws EventActionRefused
     */
    private function assertCanIssue(User $issuer, Event $event): void
    {
        if (! Gate::forUser($issuer)->allows('manageQr', $event)) {
            throw new EventActionRefused('forbidden', __('qr.token.you_cannot_manage_qr_codes'), 403);
        }

        if ($event->is_hidden) {
            throw new EventActionRefused('surprise', __('qr.token.surprise_has_no_codes'), 409);
        }

        if (! $event->qr_enabled) {
            throw new EventActionRefused('qr_disabled', __('qr.token.qr_codes_are_not_enabled'));
        }

        if ($event->isOnHold() || ! in_array($event->status, [Event::STATUS_PUBLISHED, Event::STATUS_COMPLETED], true)) {
            throw new EventActionRefused('inactive', __('qr.token.this_event_is_no_longer'), 409);
        }

        if (now()->gt($event->completion_deadline)) {
            throw new EventActionRefused('deadline_passed', __('qr.token.completion_deadline_passed'), 409);
        }
    }

    private function create(User $issuer, Event $event, string $batch, ?CarbonInterface $expiresAt): QrToken
    {
        return QrToken::create([
            'event_id' => $event->id,
            'issuer_id' => $issuer->id,
            'token_code' => Str::random(48),
            'type' => QrToken::SLIP,
            'batch' => $batch,
            'expires_at' => $expiresAt,
        ]);
    }

    private function signature(string $code): string
    {
        return substr(hash_hmac('sha256', $code, (string) config('app.key')), 0, 32);
    }
}

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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Event QR codes: issuing them and redeeming them for points.
 *
 * What is printed is "<random token>.<signature>". The signature is an HMAC of
 * the token with the application key, so a made-up or altered code is refused
 * before the database is even asked. Redeeming never gives points by itself:
 * the shared ledger still requires an accepted participant, an open event and
 * a single base credit.
 */
class QrTokenService
{
    public const MAX_STEPS = 10;

    public function __construct(private PointLedger $ledger) {}

    /**
     * Make a new code for an event. The issuer needs the QR capability at the event's topic.
     *
     * @throws EventActionRefused
     */
    public function issue(
        User $issuer,
        Event $event,
        string $type = QrToken::POSTER,
        ?int $step = null,
        ?int $totalSteps = null,
        ?CarbonInterface $expiresAt = null,
    ): QrToken {
        if (! Gate::forUser($issuer)->allows('manageQr', $event)) {
            throw new EventActionRefused('forbidden', 'You cannot manage QR codes for this event.', 403);
        }

        if (! $event->qr_enabled) {
            throw new EventActionRefused('qr_disabled', 'QR codes are not enabled for this event.');
        }

        if ($event->isOnHold() || ! in_array($event->status, [Event::STATUS_PUBLISHED, Event::STATUS_COMPLETED], true)) {
            throw new EventActionRefused('inactive', 'This event is no longer active.', 409);
        }

        if (! in_array($type, [QrToken::POSTER, QrToken::SLIP], true)) {
            throw new EventActionRefused('invalid_type', 'Unknown QR code type.');
        }

        if ($totalSteps !== null || $step !== null) {
            if ($totalSteps === null || $step === null || $totalSteps < 2 || $totalSteps > self::MAX_STEPS || $step < 1 || $step > $totalSteps) {
                throw new EventActionRefused('invalid_steps', 'A task of several parts needs a part number inside 1 and the total (2 to '.self::MAX_STEPS.').');
            }
        }

        return QrToken::create([
            'event_id' => $event->id,
            'issuer_id' => $issuer->id,
            'token_code' => Str::random(48),
            'type' => $type,
            'step_number' => $step,
            'total_steps' => $totalSteps,
            'expires_at' => $expiresAt,
        ]);
    }

    public function revoke(User $actor, QrToken $token): void
    {
        if (! Gate::forUser($actor)->allows('manageQr', $token->event)) {
            throw new EventActionRefused('forbidden', 'You cannot manage QR codes for this event.', 403);
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
        $invalid = new EventActionRefused('invalid', 'Invalid QR code.');
        [$code, $signature] = array_pad(explode('.', trim($payload), 2), 2, '');

        if ($code === '' || ! hash_equals($this->signature($code), $signature)) {
            throw $invalid;
        }

        $token = QrToken::query()->where('token_code', $code)->with('event.owningTopic')->first();

        if ($token === null || $token->revoked_at !== null) {
            throw $invalid;
        }

        return $token;
    }

    /**
     * Redeem a scanned code for the signed-in student.
     *
     * @return array{outcome: string, message: string, event: string, points: int, step: ?int, total_steps: ?int}
     *
     * @throws EventActionRefused
     */
    public function redeem(User $student, string $payload): array
    {
        $token = $this->resolve($payload);
        $event = $token->event;

        if ($student->is($token->issuer)) {
            throw new EventActionRefused('own_code', 'You cannot redeem a code that you issued yourself.', 403);
        }

        if (! $event->qr_enabled || $event->isOnHold() || ! in_array($event->status, [Event::STATUS_PUBLISHED, Event::STATUS_COMPLETED], true)) {
            throw new EventActionRefused('inactive', 'This event is no longer active.', 409);
        }

        if ($token->expires_at !== null && $token->expires_at->isPast()) {
            throw new EventActionRefused('expired', 'This QR code has expired.', 409);
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
            throw new EventActionRefused('not_registered', 'You are not registered or selected for this event.', 403);
        }

        try {
            return DB::transaction(fn () => $this->claim($token, $event, $student));
        } catch (UniqueConstraintViolationException) {
            throw $this->duplicate($token, $student);
        }
    }

    /**
     * @return array{outcome: string, message: string, event: string, points: int, step: ?int, total_steps: ?int}
     */
    private function claim(QrToken $token, Event $event, User $student): array
    {
        if (QrRedemption::query()->where('qr_token_id', $token->id)->where('student_id', $student->id)->exists()) {
            throw $this->duplicate($token, $student);
        }

        if ($token->type === QrToken::SLIP && QrRedemption::query()->where('qr_token_id', $token->id)->exists()) {
            throw $this->duplicate($token, $student);
        }

        $redemption = QrRedemption::create([
            'qr_token_id' => $token->id,
            'student_id' => $student->id,
            'step_number' => $token->step_number,
            'redeemed_at' => now(),
            // One claim in total for a slip: the unique index lets only the first insert through.
            'slip_claim_id' => $token->type === QrToken::SLIP ? $token->id : null,
        ]);

        if ($token->isMilestone()) {
            DB::table('event_milestone_progress')->insert([
                'event_id' => $event->id,
                'student_id' => $student->id,
                'step_number' => $token->step_number,
                'qr_token_id' => $token->id,
                'recorded_at' => now(),
            ]);

            $done = DB::table('event_milestone_progress')
                ->where('event_id', $event->id)
                ->where('student_id', $student->id)
                ->count();

            if ($done < $token->total_steps) {
                return [
                    'outcome' => 'progress',
                    'message' => "Part {$token->step_number} recorded ({$done} of {$token->total_steps}). Points are credited when every part is done.",
                    'event' => $event->title,
                    'points' => 0,
                    'step' => $token->step_number,
                    'total_steps' => $token->total_steps,
                ];
            }
        }

        $source = $token->isMilestone()
            ? PointLedger::MILESTONE
            : ($token->type === QrToken::SLIP ? PointLedger::QR_SLIP : PointLedger::QR_POSTER);

        $row = $this->ledger->credit($event, $student, $token->issuer, $source);
        $redemption->forceFill(['point_transaction_id' => $row->id])->save();

        return [
            'outcome' => 'credited',
            'message' => "Completion recorded! {$row->points} points credited.",
            'event' => $event->title,
            'points' => $row->points,
            'step' => $token->step_number,
            'total_steps' => $token->total_steps,
        ];
    }

    /**
     * A unique index said no. Work out which rule it was, from the data and not from the database's wording.
     */
    private function duplicate(QrToken $token, User $student): EventActionRefused
    {
        $own = QrRedemption::query()->where('qr_token_id', $token->id)->where('student_id', $student->id)->exists();

        if (! $own && $token->type === QrToken::SLIP) {
            return new EventActionRefused('already_used', 'This code has already been used.', 409);
        }

        return new EventActionRefused('already_claimed', 'You have already claimed points for this QR code.', 409);
    }

    private function signature(string $code): string
    {
        return substr(hash_hmac('sha256', $code, (string) config('app.key')), 0, 32);
    }
}

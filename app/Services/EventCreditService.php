<?php

namespace App\Services;

use App\Exceptions\EventActionRefused;
use App\Models\Event;
use App\Models\PointTransaction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Handles attendance verification and point crediting for events (Task #43, UC-1.7).
 *
 * Delegates core crediting invariants to PointLedger::credit():
 * - Anti-Self-Dealing guardrail: organizers cannot credit themselves (D04)
 * - Candidate eligibility: participant must hold an ACCEPTED application (UC-1.7)
 * - Single-award ledger invariant: at most one positive base award per student and event (D06)
 * - Shared event state & window validation across all crediting routes
 */
class EventCreditService
{
    public function __construct(
        private PointLedger $ledger,
        private QrPassport $passports,
    ) {}

    /**
     * Credit attendance points to a student for an event.
     *
     * @return array{status: string, message: string, points?: int, student: array{id: int, name: string, neptun_code: ?string}, credited_count?: int}
     *
     * @throws AuthorizationException|ValidationException
     */
    public function credit(User $actor, Event $event, int|string|User $identifier, string $source = PointLedger::PASSPORT): array
    {
        // 1. Authorization: Actor must have points.credit capability on owning topic
        if (! $actor->can('credit', $event)) {
            throw new AuthorizationException('You are not authorized to credit points for this event.');
        }

        // 2. Validate Event State & Window via PointLedger refusal check
        if ($refusal = $this->ledger->refusalFor($event)) {
            throw ValidationException::withMessages([
                'event' => $refusal->getMessage(),
            ]);
        }

        // 3. Resolve Student. A scanned passport must be signed and still valid (Task #52).
        if (is_string($identifier) && $this->passports->looksLikePassport($identifier)) {
            ['student' => $student, 'problem' => $problem] = $this->passports->resolve($identifier);

            if ($problem !== null) {
                return [
                    'status' => $problem === 'expired' ? 'passport_expired' : 'passport_invalid',
                    'message' => $problem === 'expired'
                        ? 'This QR passport has expired. Ask the student to open their passport again.'
                        : 'This QR passport is not valid.',
                    'student' => ['id' => 0, 'name' => 'Unknown Student', 'neptun_code' => null],
                ];
            }
        } else {
            $student = $this->resolveStudent($identifier, $source);
        }

        if (! $student) {
            return [
                'status' => 'not_found',
                'message' => 'No student found matching the scanned QR code or input.',
                'student' => [
                    'id' => 0,
                    'name' => 'Unknown Student',
                    'neptun_code' => null,
                ],
            ];
        }

        // 4. Delegate to PointLedger::credit()
        try {
            $transaction = $this->ledger->credit($event, $student, $actor, $source);

            $totalCredited = PointTransaction::query()
                ->where('event_id', $event->id)
                ->where('type', PointTransaction::CREDIT)
                ->count();

            return [
                'status' => 'credited',
                'message' => "Success: Points credited to {$student->name} (+{$transaction->points} pts).",
                'points' => $transaction->points,
                'student' => [
                    'id' => $student->id,
                    'name' => $student->name,
                    'neptun_code' => $student->neptun_code,
                ],
                'credited_count' => $totalCredited,
            ];
        } catch (EventActionRefused $e) {
            $status = match ($e->reason) {
                'self_credit' => 'self_credit',
                'already_credited' => 'already_credited',
                'not_registered' => 'not_accepted',
                default => 'error',
            };

            return [
                'status' => $status,
                'message' => $e->getMessage(),
                'student' => [
                    'id' => $student->id,
                    'name' => $student->name,
                    'neptun_code' => $student->neptun_code,
                ],
            ];
        }
    }

    /**
     * Resolves a student from what the organiser typed or picked (Task #43).
     *
     * Accepted: a Neptun code (typed by an organiser who sees the student), or,
     * for "manual" only, the id of a student picked from the scanner's own list.
     * Codes that anyone could make up are refused: unsigned passport links
     * (/qr/passport/{id}), JSON with an id, or a bare id from the camera. A real
     * passport is a signed, short-lived code (QrPassport, Task #52).
     */
    public function resolveStudent(int|string|User $identifier, string $source = PointLedger::PASSPORT): ?User
    {
        if ($identifier instanceof User) {
            return $identifier;
        }

        $manual = $source === 'manual';

        if (is_int($identifier)) {
            return $manual ? User::query()->find($identifier) : null;
        }

        $raw = trim($identifier);

        if ($raw === '' || str_contains($raw, '/qr/passport/')) {
            return null;
        }

        // JSON payloads may name a Neptun code, never an id.
        if (str_starts_with($raw, '{') && str_ends_with($raw, '}')) {
            $data = json_decode($raw, true);
            $neptun = is_array($data) ? ($data['neptun_code'] ?? $data['neptun'] ?? null) : null;

            return is_string($neptun) && $neptun !== ''
                ? User::query()->where('neptun_code', strtoupper($neptun))->first()
                : null;
        }

        // A bare id: only when the organiser picked the student from the list.
        if (ctype_digit($raw)) {
            return $manual ? User::query()->find((int) $raw) : null;
        }

        // Otherwise a Neptun code (case-insensitive)
        return User::query()->where('neptun_code', strtoupper($raw))->first();
    }
}

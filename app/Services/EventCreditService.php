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
 * The only thing it credits from is a scanned QR passport (Task #52). A typed code, a
 * Neptun code, an id or a pick from a list is refused: a missed scan is solved by
 * extending the points period, not by crediting by hand (Task #83).
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
     * Credit attendance points to the student whose QR passport was scanned.
     *
     * @return array{status: string, message: string, points?: int, student: array{id: int, name: string, neptun_code: ?string}, credited_count?: int}
     *
     * @throws AuthorizationException|ValidationException
     */
    public function credit(User $actor, Event $event, string $passport): array
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

        // 3. Resolve the student. The passport must be signed and still valid (Task #52).
        if (! $this->passports->looksLikePassport($passport)) {
            return $this->unknownStudent(
                'passport_invalid',
                'This is not a QR passport. Ask the student to show the one on their "My QR passport" page.',
            );
        }

        ['student' => $student, 'problem' => $problem] = $this->passports->resolve($passport);

        if ($problem !== null) {
            return $this->unknownStudent(
                $problem === 'expired' ? 'passport_expired' : 'passport_invalid',
                $problem === 'expired'
                    ? 'This QR passport has expired. Ask the student to open their passport again.'
                    : 'This QR passport is not valid.',
            );
        }

        // 4. Delegate to PointLedger::credit()
        try {
            $transaction = $this->ledger->credit($event, $student, $actor, PointLedger::PASSPORT);

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
     * The answer when the scanned text names no student.
     *
     * @return array{status: string, message: string, student: array{id: int, name: string, neptun_code: ?string}}
     */
    private function unknownStudent(string $status, string $message): array
    {
        return [
            'status' => $status,
            'message' => $message,
            'student' => ['id' => 0, 'name' => 'Unknown Student', 'neptun_code' => null],
        ];
    }
}

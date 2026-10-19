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
    public function __construct(private PointLedger $ledger) {}

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

        // 3. Resolve Student
        $student = $this->resolveStudent($identifier);
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
     * Resolves a student model from a User instance, ID, Neptun code, or QR URL string.
     */
    public function resolveStudent(int|string|User $identifier): ?User
    {
        if ($identifier instanceof User) {
            return $identifier;
        }

        if (is_int($identifier)) {
            return User::query()->find($identifier);
        }

        $raw = trim($identifier);

        // Check if raw is a JSON payload
        if (str_starts_with($raw, '{') && str_ends_with($raw, '}')) {
            $data = json_decode($raw, true);
            if (is_array($data)) {
                if (! empty($data['user_id'])) {
                    return User::query()->find((int) $data['user_id']);
                }
                if (! empty($data['student_id'])) {
                    return User::query()->find((int) $data['student_id']);
                }
                if (! empty($data['neptun_code'])) {
                    return User::query()->where('neptun_code', strtoupper((string) $data['neptun_code']))->first();
                }
                if (! empty($data['neptun'])) {
                    return User::query()->where('neptun_code', strtoupper((string) $data['neptun']))->first();
                }
            }
        }

        // Check if raw is a URL like /qr/passport/{user} or http://.../qr/passport/{user}?...
        if (preg_match('#/qr/passport/(\d+)#', $raw, $matches)) {
            return User::query()->find((int) $matches[1]);
        }

        // Check if integer string
        if (ctype_digit($raw)) {
            return User::query()->find((int) $raw);
        }

        // Otherwise check as Neptun code (case-insensitive)
        return User::query()->where('neptun_code', strtoupper($raw))->first();
    }
}

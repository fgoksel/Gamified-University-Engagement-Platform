<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventApplication;
use App\Models\PointTransaction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Handles attendance verification and point crediting for events (Task #43, UC-1.7).
 *
 * Enforces:
 * - Organizer authorization via topic capabilities (Capability::PointsCredit)
 * - Anti-Self-Dealing guardrail: organizers cannot credit themselves (D04)
 * - Candidate eligibility: participant must hold an ACCEPTED application (UC-1.7)
 * - Single-award ledger invariant: at most one positive base award per student and event (D06)
 */
class EventCreditService
{
    /**
     * Credit attendance points to a student for an event.
     *
     * @return array{status: string, message: string, points?: int, student: array{id: int, name: string, neptun_code: ?string}, credited_count?: int}
     *
     * @throws AuthorizationException|ValidationException
     */
    public function credit(User $actor, Event $event, int|string|User $identifier, string $source = 'passport'): array
    {
        // 1. Authorization: Actor must have points.credit capability on owning topic
        if (! $actor->can('credit', $event)) {
            throw new AuthorizationException('You are not authorized to credit points for this event.');
        }

        // 2. Validate Event State
        $this->validateEventState($event);

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

        // 4. Anti-Self-Dealing Guardrail (Decision Record D04)
        if ($actor->id === $student->id) {
            return [
                'status' => 'self_credit',
                'message' => 'Organizers cannot credit points to themselves.',
                'student' => [
                    'id' => $student->id,
                    'name' => $student->name,
                    'neptun_code' => $student->neptun_code,
                ],
            ];
        }

        // 5. Verification & Atomic Crediting
        return DB::transaction(function () use ($actor, $event, $student, $source) {
            /** @var Event $lockedEvent */
            $lockedEvent = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

            /** @var EventApplication|null $application */
            $application = EventApplication::query()
                ->where('event_id', $lockedEvent->id)
                ->where('student_id', $student->id)
                ->lockForUpdate()
                ->first();

            // Verify Application: Must exist and have status 'accepted'
            if (! $application || $application->status !== EventApplication::ACCEPTED) {
                return [
                    'status' => 'not_accepted',
                    'message' => "Student {$student->name} is not registered or selected for this event.",
                    'student' => [
                        'id' => $student->id,
                        'name' => $student->name,
                        'neptun_code' => $student->neptun_code,
                    ],
                ];
            }

            // Verify Single-Award Ledger Invariant (Decision Record D06)
            $alreadyCredited = PointTransaction::query()
                ->where('event_id', $lockedEvent->id)
                ->where('student_id', $student->id)
                ->where('type', PointTransaction::CREDIT)
                ->lockForUpdate()
                ->exists();

            if ($alreadyCredited) {
                return [
                    'status' => 'already_credited',
                    'message' => "Points have already been credited to {$student->name} for this event.",
                    'student' => [
                        'id' => $student->id,
                        'name' => $student->name,
                        'neptun_code' => $student->neptun_code,
                    ],
                ];
            }

            // Create ledger entry
            PointTransaction::create([
                'student_id' => $student->id,
                'event_id' => $lockedEvent->id,
                'semester_id' => $lockedEvent->semester_id,
                'credited_by_id' => $actor->id,
                'points' => $lockedEvent->points_value,
                'type' => PointTransaction::CREDIT,
                'source' => $source,
                'category' => $lockedEvent->category,
                'created_at' => now(),
            ]);

            // Update application completion timestamps
            $application->update([
                'scored_at' => now(),
                'completed_at' => now(),
            ]);

            $totalCredited = PointTransaction::query()
                ->where('event_id', $lockedEvent->id)
                ->where('type', PointTransaction::CREDIT)
                ->count();

            return [
                'status' => 'credited',
                'message' => "Success: Points credited to {$student->name} (+{$lockedEvent->points_value} pts).",
                'points' => $lockedEvent->points_value,
                'student' => [
                    'id' => $student->id,
                    'name' => $student->name,
                    'neptun_code' => $student->neptun_code,
                ],
                'credited_count' => $totalCredited,
            ];
        });
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

    /**
     * Validates that the event is in a state permitting point crediting.
     *
     * @throws ValidationException
     */
    private function validateEventState(Event $event): void
    {
        if ($event->status === Event::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'event' => 'Attendance cannot be credited for draft events.',
            ]);
        }

        if ($event->status === Event::STATUS_CANCELLED) {
            throw ValidationException::withMessages([
                'event' => 'Attendance cannot be credited for cancelled events.',
            ]);
        }

        if ($event->status === Event::STATUS_ON_HOLD) {
            throw ValidationException::withMessages([
                'event' => 'Attendance crediting is paused while this event is on hold.',
            ]);
        }
    }
}

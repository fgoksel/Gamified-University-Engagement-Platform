<?php

namespace App\Services;

use App\Exceptions\EventActionRefused;
use App\Models\Event;
use App\Models\EventApplication;
use App\Models\EventCheckinSession;
use App\Models\PointTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Attendance with a live QR code (Task #80, meeting of 26 Oct, section 3).
 *
 * The organiser opens check-in when they choose and shows a code that changes every few seconds,
 * 5 by default (on a projector or their own phone). A student scans it in the app and gets the
 * event's points.
 *
 * What the QR holds is "LV1.<session id>.<time slot>.<signature>":
 *   - the time slot is the clock divided into steps (config/checkin.php), and the signature is an
 *     HMAC of the session and the slot with the application key and the session's secret, so nobody
 *     can make a code or work out the next one;
 *   - a code is accepted while it is showing, and for a margin of 1 or 2 seconds after the next one
 *     appears, which allows for the time a phone needs to read it and send it (5 + 2 = 7 seconds at
 *     most with the default settings). A photo sent to an absent friend is dead by then;
 *   - pausing hides the code and stops acceptance, stopping ends the session for good.
 *
 * A scan never gives points by itself. It goes through the same checks as every other QR code: an
 * accepted student, an event that is active and inside its points window, no self-crediting, and
 * the ledger's single credit per student and event.
 */
class LiveAttendanceQr
{
    public const PREFIX = 'LV1';

    /** Seconds one code is shown: the default, and the range the setting is held to (config/checkin.php). */
    public const DEFAULT_STEP = 5;

    public const MIN_STEP = 3;

    public const MAX_STEP = 8;

    /** Seconds the previous code still works after a new one appears: the default, and the most allowed. */
    public const DEFAULT_MARGIN = 2;

    public const MAX_MARGIN = 2;

    public function __construct(private PointLedger $ledger) {}

    /**
     * How many seconds one code is shown (the setting, held between 3 and 8).
     */
    public function step(): int
    {
        return $this->setting('step_seconds', self::DEFAULT_STEP, self::MIN_STEP, self::MAX_STEP);
    }

    /**
     * How many seconds the previous code still works after a new one appears (held between 0 and 2).
     */
    public function margin(): int
    {
        return $this->setting('margin_seconds', self::DEFAULT_MARGIN, 0, self::MAX_MARGIN);
    }

    /**
     * The session that has not been stopped, if there is one.
     */
    public function session(Event $event): ?EventCheckinSession
    {
        return EventCheckinSession::query()
            ->where('event_id', $event->id)
            ->whereNull('closed_at')
            ->latest('id')
            ->first();
    }

    /**
     * Whether a code is being shown and scans are accepted.
     */
    public function isOpen(Event $event): bool
    {
        return $this->session($event)?->isOpen() === true;
    }

    /**
     * Start showing the code, or show it again after a pause.
     *
     * @throws EventActionRefused
     */
    public function open(User $actor, Event $event): EventCheckinSession
    {
        $this->authorise($actor, $event);

        if (! $event->qr_enabled) {
            throw new EventActionRefused('qr_disabled', 'QR codes are not enabled for this event.');
        }

        if ($event->isOnHold() || ! in_array($event->status, [Event::STATUS_PUBLISHED, Event::STATUS_COMPLETED], true)) {
            throw new EventActionRefused('inactive', 'This event is no longer active.', 409);
        }

        return DB::transaction(function () use ($actor, $event) {
            // One session at a time: the event row is the lock.
            Event::query()->whereKey($event->id)->lockForUpdate()->first();

            $session = $this->session($event);

            if ($session === null) {
                return EventCheckinSession::create([
                    'event_id' => $event->id,
                    'issuer_id' => $actor->id,
                    'secret' => Str::random(48),
                    'opened_at' => now(),
                ]);
            }

            if ($session->isPaused()) {
                $session->forceFill(['paused_at' => null])->save();
            }

            return $session;
        });
    }

    /**
     * Hide the code and stop accepting scans until it is shown again.
     *
     * @throws EventActionRefused
     */
    public function pause(User $actor, Event $event): ?EventCheckinSession
    {
        $this->authorise($actor, $event);

        $session = $this->session($event);

        if ($session !== null && ! $session->isPaused()) {
            $session->forceFill(['paused_at' => now()])->save();
        }

        return $session;
    }

    /**
     * End check-in. Codes shown so far stop working at once.
     *
     * @throws EventActionRefused
     */
    public function stop(User $actor, Event $event): void
    {
        $this->authorise($actor, $event);

        $this->session($event)?->forceFill(['closed_at' => now()])->save();
    }

    /**
     * What the organiser's screen needs: the code that is showing now and how long it lasts,
     * and how many accepted students have checked in.
     *
     * @return array<string, mixed>
     *
     * @throws EventActionRefused
     */
    public function state(User $actor, Event $event): array
    {
        $this->authorise($actor, $event);

        $session = $this->session($event);
        $open = $session?->isOpen() === true;

        return [
            'open' => $open,
            'paused' => $session?->isPaused() === true,
            'code' => $open ? $this->payload($session) : null,
            'url' => $open ? route('qr.show', ['code' => $this->payload($session)]) : null,
            'image' => $open ? route('events.checkin.image', $event) : null,
            'step_seconds' => $this->step(),
            // How long the previous code still works after the change, so the screen (Task #81) never keeps
            // showing a code that scans no longer accept.
            'margin_seconds' => $this->margin(),
            'seconds_left' => $open ? $this->step() - (now()->getTimestamp() % $this->step()) : null,
            // The screen's countdown (Task #81) runs to the moment the code changes, not to the next whole second.
            'ms_left' => $open ? $this->msLeft() : null,
            'checked_in' => $this->checkedIn($event),
            'accepted' => $this->accepted($event),
            // Why a scan would be refused right now (not started, past the points end date, on hold), so the
            // organiser sees it on the screen (Task #81). Null while scans count.
            'refusal' => $this->ledger->refusalFor($event)?->getMessage(),
        ];
    }

    /**
     * What a student's page needs to say "Check-in is open".
     *
     * @return array{open: bool, accepted: bool, credited: bool, step_seconds: int}
     */
    public function statusFor(User $student, Event $event): array
    {
        return [
            'open' => $this->isOpen($event),
            'accepted' => EventApplication::query()
                ->where('event_id', $event->id)->where('student_id', $student->id)
                ->where('status', EventApplication::ACCEPTED)->exists(),
            'credited' => PointTransaction::query()
                ->where('event_id', $event->id)->where('student_id', $student->id)
                ->where('type', PointTransaction::CREDIT)->exists(),
            'step_seconds' => $this->step(),
        ];
    }

    /**
     * The text stored in the QR image for the current time slot.
     */
    public function payload(EventCheckinSession $session, ?int $slot = null): string
    {
        $slot ??= $this->slot();

        return implode('.', [self::PREFIX, $session->id, $slot, $this->signature($session, $slot)]);
    }

    public function looksLikeLiveCode(string $text): bool
    {
        return str_starts_with(trim($text), self::PREFIX.'.');
    }

    /**
     * Check in a student with a scanned code.
     *
     * @return array{outcome: string, message: string, event: string, points: int, category: string, step: ?int, total_steps: ?int}
     *
     * @throws EventActionRefused
     */
    public function redeem(User $student, string $payload): array
    {
        $invalid = new EventActionRefused('invalid', 'Invalid QR code.');

        if (! preg_match('/^'.self::PREFIX.'\.(\d+)\.(\d+)\.([a-f0-9]{32})$/', trim($payload), $m)) {
            throw $invalid;
        }

        [, $sessionId, $slot, $signature] = $m;
        $session = EventCheckinSession::query()->with(['event.owningTopic', 'issuer'])->find((int) $sessionId);

        if ($session === null || ! hash_equals($this->signature($session, (int) $slot), $signature)) {
            throw $invalid;
        }

        $event = $session->event;

        if ($session->isClosed()) {
            throw new EventActionRefused('closed', 'Check-in for this event has ended.', 409);
        }

        if ($session->isPaused()) {
            throw new EventActionRefused('paused', 'Check-in is paused. Wait until the organiser shows the code again.', 409);
        }

        $step = $this->step();
        $seconds = now()->getTimestamp();
        $current = intdiv($seconds, $step);

        // A code from the future cannot be real: it is made up.
        if ((int) $slot > $current) {
            throw $invalid;
        }

        // The code that is showing, or the one before it but only in the first seconds of the new
        // step: the time a phone needs to read the code and send it, never a whole extra step.
        $showing = (int) $slot === $current;
        $justReplaced = (int) $slot === $current - 1 && $seconds % $step < $this->margin();

        if (! $showing && ! $justReplaced) {
            throw new EventActionRefused('expired', 'This code has expired. Scan the code that is showing now.', 409);
        }

        if ($student->is($session->issuer)) {
            throw new EventActionRefused('own_code', 'You cannot check in with a code that you are showing yourself.', 403);
        }

        if (! $event->qr_enabled || $event->isOnHold() || ! in_array($event->status, [Event::STATUS_PUBLISHED, Event::STATUS_COMPLETED], true)) {
            throw new EventActionRefused('inactive', 'This event is no longer active.', 409);
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
            $row = $this->ledger->credit($event, $student, $session->issuer, PointLedger::QR_LIVE);
        } catch (EventActionRefused $refused) {
            if ($refused->reason === 'already_credited') {
                throw new EventActionRefused('already_claimed', 'You have already checked in. Your points for this event are credited.', 409);
            }

            throw $refused;
        }

        return [
            'outcome' => 'credited',
            'message' => "Check-in recorded. {$row->points} points credited.",
            'event' => $event->title,
            'points' => $row->points,
            'category' => $row->category,
            'step' => null,
            'total_steps' => null,
        ];
    }

    private function authorise(User $actor, Event $event): void
    {
        if (! Gate::forUser($actor)->allows('manageQr', $event)) {
            throw new EventActionRefused('forbidden', 'You cannot manage QR codes for this event.', 403);
        }
    }

    /** The current step of the clock. */
    private function slot(): int
    {
        return intdiv(now()->getTimestamp(), $this->step());
    }

    /**
     * A setting from config/checkin.php, held inside its range. Anything that is not a number
     * (an empty line in .env) falls back to the default.
     */
    private function setting(string $key, int $default, int $min, int $max): int
    {
        $value = config("checkin.{$key}");

        return is_numeric($value) ? max($min, min($max, (int) $value)) : $default;
    }

    private function signature(EventCheckinSession $session, int $slot): string
    {
        return substr(hash_hmac('sha256', "{$session->id}.{$slot}", config('app.key').'|'.$session->secret), 0, 32);
    }

    /** Milliseconds until the code changes. */
    private function msLeft(): int
    {
        $step = $this->step() * 1000;

        return $step - (now()->getTimestampMs() % $step);
    }

    /**
     * Accepted students with points for this event, by any route. The screen counts how many accepted
     * students have checked in (Task #81), so someone credited only by an import is not one of them.
     */
    private function checkedIn(Event $event): int
    {
        return PointTransaction::query()
            ->where('event_id', $event->id)
            ->where('type', PointTransaction::CREDIT)
            ->whereIn('student_id', EventApplication::query()
                ->select('student_id')
                ->where('event_id', $event->id)
                ->where('status', EventApplication::ACCEPTED))
            ->distinct()
            ->count('student_id');
    }

    private function accepted(Event $event): int
    {
        return EventApplication::query()
            ->where('event_id', $event->id)
            ->where('status', EventApplication::ACCEPTED)
            ->count();
    }
}

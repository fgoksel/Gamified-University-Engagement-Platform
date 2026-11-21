<?php

namespace App\Services;

use App\Models\User;

/**
 * The student's QR passport (Task #52, UC-1.7 / UC-2.3).
 *
 * The student shows it; an organiser scans it with "Scan attendance" (Task #43).
 * What the QR holds is "CEP1.<user id>.<expiry>.<signature>":
 *   - the signature is an HMAC with the application key, so nobody can make a
 *     passport for someone else, or change the id in it;
 *   - it changes every 7 seconds (Task #100, decisions of 27 October): the clock is cut into
 *     7-second slots and a code belongs to one slot. It is accepted while it is showing and
 *     for 2 seconds after the next one appears, which allows for the time a camera needs to
 *     read it and send it, like the live attendance code (LiveAttendanceQr). A screenshot sent
 *     to a friend is dead by then.
 */
class QrPassport
{
    public const PREFIX = 'CEP1';

    /** How often the passport changes, in seconds (Task #100). */
    public const REFRESH = 7;

    /** Seconds a code still works after the next one appears. */
    public const MARGIN = 2;

    /**
     * The passport code for this student in the current 7-second slot. Asking again in the
     * same slot gives the same code.
     */
    public function issue(User $student): string
    {
        $expires = $this->slotEnd() + self::MARGIN;

        return implode('.', [self::PREFIX, $student->id, $expires, $this->signature($student->id, $expires)]);
    }

    /**
     * Seconds until the passport changes, so the page fetches the next code right then.
     */
    public function secondsToNext(): int
    {
        return max(1, $this->slotEnd() - now()->getTimestamp());
    }

    /**
     * The moment the current slot ends and the next code appears (a Unix timestamp).
     */
    private function slotEnd(): int
    {
        return (intdiv(now()->getTimestamp(), self::REFRESH) + 1) * self::REFRESH;
    }

    /**
     * Whether the text looks like a passport code at all (signed or not).
     */
    public function looksLikePassport(string $text): bool
    {
        return str_contains($text, self::PREFIX.'.');
    }

    /**
     * The student a scanned passport belongs to.
     *
     * @return array{student: ?User, problem: ?string} problem is "invalid" or "expired"
     */
    public function resolve(string $text): array
    {
        // Tolerate surrounding text, e.g. a scanner that adds a prefix or a newline.
        if (! preg_match('/'.self::PREFIX.'\.(\d+)\.(\d+)\.([a-f0-9]{32})/', $text, $m)) {
            return ['student' => null, 'problem' => 'invalid'];
        }

        [, $id, $expires, $signature] = $m;

        if (! hash_equals($this->signature((int) $id, (int) $expires), $signature)) {
            return ['student' => null, 'problem' => 'invalid'];
        }

        if ((int) $expires < now()->getTimestamp()) {
            return ['student' => null, 'problem' => 'expired'];
        }

        $student = User::query()->find((int) $id);

        return $student === null
            ? ['student' => null, 'problem' => 'invalid']
            : ['student' => $student, 'problem' => null];
    }

    private function signature(int $userId, int $expires): string
    {
        return substr(hash_hmac('sha256', "passport|{$userId}|{$expires}", (string) config('app.key')), 0, 32);
    }
}

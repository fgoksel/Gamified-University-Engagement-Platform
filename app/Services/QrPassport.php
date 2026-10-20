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
 *   - it is valid for a short time only, so a screenshot sent to a friend stops
 *     working within minutes. The passport page draws a fresh one every minute.
 */
class QrPassport
{
    public const PREFIX = 'CEP1';

    /** How long one passport code stays valid, in seconds. */
    public const LIFETIME = 120;

    /** How often the passport page draws a new code, in seconds. */
    public const REFRESH = 60;

    /**
     * A fresh passport code for this student.
     */
    public function issue(User $student): string
    {
        $expires = now()->addSeconds(self::LIFETIME)->getTimestamp();

        return implode('.', [self::PREFIX, $student->id, $expires, $this->signature($student->id, $expires)]);
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

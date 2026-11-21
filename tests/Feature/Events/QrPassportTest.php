<?php

namespace Tests\Feature\Events;

use App\Enums\Capability;
use App\Models\EventApplication;
use App\Models\User;
use App\Services\QrPassport;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Task #52: the student's QR passport and how "Scan attendance" reads it.
 */
class QrPassportTest extends EventTestCase
{
    private User $organiser;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        // An event that is running now, so points can be credited.
        $this->event->update([
            'event_starts_at' => now()->subHour(),
            'event_ends_at' => now()->addHour(),
            'application_deadline' => now()->subDay(),
            'completion_deadline' => now()->addWeek(),
        ]);
        $this->organiser = $this->staff([Capability::PointsCredit], name: 'Organiser');
        $this->student = $this->student('Sam Student', 'SAM001');
        $this->accepted($this->student);
    }

    private function scan(string $identifier)
    {
        return $this->actingAs($this->organiser)
            ->postJson("/events/{$this->event->id}/credit", ['identifier' => $identifier])
            ->assertOk();
    }

    public function test_the_student_sees_a_fresh_signed_passport(): void
    {
        $this->actingAs($this->student)->get('/passport')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Passport/Show')
                ->where('passport.name', 'Sam Student')
                ->where('passport.neptun_code', 'SAM001')
                ->where('passport.refresh_every', 7)
                ->where('passport.next_in', fn ($seconds) => $seconds >= 1 && $seconds <= 7)
                ->where('passport.image', fn ($image) => str_starts_with($image, 'data:image/svg+xml;base64,')
                    && str_contains(base64_decode(substr($image, 26)), '<svg')));
    }

    public function test_teachers_have_no_passport_page(): void
    {
        $this->actingAs($this->organiser)->get('/passport')->assertRedirect('/');
    }

    public function test_a_valid_passport_credits_the_student(): void
    {
        $this->scan(app(QrPassport::class)->issue($this->student))
            ->assertJson(['status' => 'credited', 'student' => ['id' => $this->student->id]]);

        $this->assertDatabaseHas('point_transactions', [
            'student_id' => $this->student->id,
            'event_id' => $this->event->id,
            'source' => 'passport',
        ]);
    }

    public function test_an_expired_passport_is_refused(): void
    {
        $code = app(QrPassport::class)->issue($this->student);
        $this->travel(QrPassport::REFRESH + QrPassport::MARGIN + 1)->seconds();

        $this->scan($code)->assertJson(['status' => 'passport_expired']);
        $this->assertDatabaseCount('point_transactions', 0);
    }

    public function test_the_passport_changes_every_7_seconds_and_the_old_code_stops_working(): void
    {
        // Task #100: at the start of a 7-second slot.
        $this->travelTo(now()->setTimestamp(intdiv(now()->getTimestamp(), 7) * 7 + 7));
        $passports = app(QrPassport::class);
        $first = $passports->issue($this->student);

        // The same code for the whole slot, and the page knows when the next one comes.
        $this->travel(6)->seconds();
        $this->assertSame($first, $passports->issue($this->student));
        $this->assertSame(1, $passports->secondsToNext());

        // A new slot gives a new code.
        $this->travel(1)->seconds();
        $second = $passports->issue($this->student);
        $this->assertNotSame($first, $second);
        $this->assertSame(7, $passports->secondsToNext());

        // The old code still works for 2 seconds, for the camera to read and send it ...
        $this->travel(2)->seconds();
        $this->assertNull(app(QrPassport::class)->resolve($first)['problem']);

        // ... and not after that.
        $this->travel(1)->seconds();
        $this->assertSame('expired', app(QrPassport::class)->resolve($first)['problem']);
        $this->scan($first)->assertJson(['status' => 'passport_expired']);
        $this->scan($second)->assertJson(['status' => 'credited']);
    }

    public function test_a_scan_shows_who_the_student_is_and_whether_they_are_accepted(): void
    {
        // Task #100: name, Neptun code, profile photo and where they stand for this event.
        Storage::fake('public');
        $this->student->forceFill(['avatar' => 'avatars/sam.png'])->save();

        $this->scan(app(QrPassport::class)->issue($this->student))
            ->assertJson(['status' => 'credited', 'student' => [
                'name' => 'Sam Student',
                'neptun_code' => 'SAM001',
                'photo' => Storage::disk('public')->url('avatars/sam.png'),
                'application' => 'accepted',
                'accepted' => true,
            ]]);

        // Someone on the waitlist is shown as not accepted, and gets nothing.
        $waiting = $this->student('Wanda Waiting', 'WAI001');
        EventApplication::create(['event_id' => $this->event->id, 'student_id' => $waiting->id, 'status' => EventApplication::WAITLISTED]);
        $this->scan(app(QrPassport::class)->issue($waiting))
            ->assertJson(['status' => 'not_accepted', 'student' => [
                'name' => 'Wanda Waiting',
                'photo' => null,
                'application' => 'waitlisted',
                'accepted' => false,
            ]]);

        // Someone who never applied.
        $stranger = $this->student('Stan Stranger', 'STA001');
        $this->scan(app(QrPassport::class)->issue($stranger))
            ->assertJson(['status' => 'not_accepted', 'student' => ['application' => null, 'accepted' => false]]);
    }

    public function test_an_undone_application_is_shown_as_never_applied_and_a_withdrawal_as_withdrawn(): void
    {
        // Applied and undid it (Task #94): the scanner says they never applied.
        $undid = $this->student('Una Undo', 'UNA001');
        EventApplication::create(['event_id' => $this->event->id, 'student_id' => $undid->id, 'status' => EventApplication::WITHDRAWN, 'undone_at' => now()]);
        $this->scan(app(QrPassport::class)->issue($undid))
            ->assertJson(['status' => 'not_accepted', 'student' => ['name' => 'Una Undo', 'application' => null, 'accepted' => false]]);

        // Withdrew in the usual way: they left the event.
        $left = $this->student('Wim Withdrawn', 'WIM001');
        EventApplication::create(['event_id' => $this->event->id, 'student_id' => $left->id, 'status' => EventApplication::WITHDRAWN]);
        $this->scan(app(QrPassport::class)->issue($left))
            ->assertJson(['status' => 'not_accepted', 'student' => ['name' => 'Wim Withdrawn', 'application' => 'withdrawn', 'accepted' => false]]);
    }

    public function test_a_changed_or_made_up_passport_is_refused(): void
    {
        $other = $this->student('Other', 'OTH001');
        $this->accepted($other);

        $code = app(QrPassport::class)->issue($this->student);
        // Swap in someone else's id: the signature no longer matches.
        $forged = preg_replace('/^CEP1\.\d+\./', "CEP1.{$other->id}.", $code);

        $this->scan($forged)->assertJson(['status' => 'passport_invalid']);
        $this->scan('CEP1.'.$other->id.'.'.(now()->addHour()->getTimestamp()).'.'.str_repeat('a', 32))
            ->assertJson(['status' => 'passport_invalid']);
        $this->assertDatabaseCount('point_transactions', 0);
    }

    public function test_guessable_codes_are_refused_from_the_camera(): void
    {
        foreach ([
            "https://example.test/qr/passport/{$this->student->id}",
            (string) $this->student->id,
            json_encode(['user_id' => $this->student->id]),
        ] as $guess) {
            $this->scan($guess)->assertJson(['status' => 'passport_invalid']);
        }

        $this->assertDatabaseCount('point_transactions', 0);
    }

    public function test_a_passport_cannot_credit_its_owner_when_they_scan_themselves(): void
    {
        // An organiser who is also accepted as a participant scans their own passport.
        $this->organiser->forceFill(['neptun_code' => 'ORG001'])->save();
        $this->accepted($this->organiser);

        $this->scan(app(QrPassport::class)->issue($this->organiser))
            ->assertJson(['status' => 'self_credit']);
    }
}

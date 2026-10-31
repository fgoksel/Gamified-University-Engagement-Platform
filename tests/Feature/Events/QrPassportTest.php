<?php

namespace Tests\Feature\Events;

use App\Enums\Capability;
use App\Models\User;
use App\Services\QrPassport;
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
                ->where('passport.refresh_every', QrPassport::REFRESH)
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
        $this->travel(QrPassport::LIFETIME + 1)->seconds();

        $this->scan($code)->assertJson(['status' => 'passport_expired']);
        $this->assertDatabaseCount('point_transactions', 0);
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

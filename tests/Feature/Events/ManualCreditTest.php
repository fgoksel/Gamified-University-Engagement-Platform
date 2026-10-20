<?php

namespace Tests\Feature\Events;

use App\Enums\Capability;
use App\Models\PointTransaction;

/**
 * Task #42: the Point Crediting tab in checklist mode (UC-1.7).
 */
class ManualCreditTest extends EventTestCase
{
    public function test_ticking_students_credits_each_once_and_reports_the_rest(): void
    {
        $organiser = $this->staff([Capability::PointsCredit]);
        $a = $this->student();
        $b = $this->student();
        $outsider = $this->student();
        $this->accepted($a);
        $this->accepted($b);

        $response = $this->actingAs($organiser)->postJson("/events/{$this->event->id}/credit/bulk", [
            'student_ids' => [$a->id, $b->id, $outsider->id],
        ])->assertOk();

        $response->assertJsonPath('credited', [$a->id, $b->id]);
        $this->assertStringContainsString('not registered', $response->json("refused.{$outsider->id}"));
        $response->assertJsonPath('credited_count', 2);
        $this->assertSame(2, PointTransaction::query()->where('event_id', $this->event->id)->where('source', 'manual')->count());
    }

    public function test_a_second_submit_never_credits_anybody_twice(): void
    {
        $organiser = $this->staff([Capability::PointsCredit]);
        $student = $this->student();
        $this->accepted($student);

        $this->actingAs($organiser)->postJson("/events/{$this->event->id}/credit/bulk", ['student_ids' => [$student->id]])
            ->assertOk()->assertJsonPath('credited', [$student->id]);

        $again = $this->actingAs($organiser)->postJson("/events/{$this->event->id}/credit/bulk", ['student_ids' => [$student->id]])
            ->assertOk();
        $again->assertJsonPath('credited', []);
        $this->assertNotEmpty($again->json("refused.{$student->id}"));
        $this->assertSame(1, PointTransaction::query()->where('event_id', $this->event->id)->count());
    }

    public function test_only_accepted_students_are_credited(): void
    {
        $organiser = $this->staff([Capability::PointsCredit]);
        $pending = $this->student();
        $waiting = $this->student();
        $this->accepted($pending, status: 'pending');
        $this->accepted($waiting, status: 'waitlisted');

        $this->actingAs($organiser)->postJson("/events/{$this->event->id}/credit/bulk", ['student_ids' => [$pending->id, $waiting->id]])
            ->assertOk()->assertJsonPath('credited', []);
        $this->assertSame(0, PointTransaction::count());
    }

    public function test_an_organiser_cannot_tick_themselves(): void
    {
        $organiser = $this->staff([Capability::PointsCredit]);
        $this->accepted($organiser);

        $response = $this->actingAs($organiser)->postJson("/events/{$this->event->id}/credit/bulk", ['student_ids' => [$organiser->id]])
            ->assertOk();

        $response->assertJsonPath('credited', []);
        $this->assertSame(0, PointTransaction::count());
    }

    public function test_the_event_state_and_window_are_enforced(): void
    {
        $organiser = $this->staff([Capability::PointsCredit]);
        $student = $this->student();

        foreach ([['status' => 'draft'], ['status' => 'on_hold'], ['status' => 'cancelled'], ['event_starts_at' => now()->addDay(), 'event_ends_at' => now()->addDay()->addHours(2)]] as $attributes) {
            $event = $this->makeEvent($attributes);
            $this->accepted($student, $event);

            $this->actingAs($organiser)->postJson("/events/{$event->id}/credit/bulk", ['student_ids' => [$student->id]])
                ->assertStatus(422)->assertJsonValidationErrors(['event']);
        }

        $this->assertSame(0, PointTransaction::count());
    }

    public function test_only_people_with_the_credit_capability_at_the_topic_can_use_it(): void
    {
        $student = $this->student();
        $this->accepted($student);
        $payload = ['student_ids' => [$student->id]];

        $this->actingAs($this->staff([Capability::EventsCreate]))->postJson("/events/{$this->event->id}/credit/bulk", $payload)->assertForbidden();
        $this->actingAs($this->staff([Capability::PointsCredit], $this->university->children()->create(['title' => 'Elsewhere'])))
            ->postJson("/events/{$this->event->id}/credit/bulk", $payload)->assertForbidden();
        $this->actingAs($this->student())->postJson("/events/{$this->event->id}/credit/bulk", $payload)->assertStatus(403);
        $this->assertSame(0, PointTransaction::count());
    }

    public function test_the_request_is_validated(): void
    {
        $organiser = $this->staff([Capability::PointsCredit]);

        $this->actingAs($organiser)->postJson("/events/{$this->event->id}/credit/bulk", [])->assertUnprocessable();
        $this->actingAs($organiser)->postJson("/events/{$this->event->id}/credit/bulk", ['student_ids' => []])->assertUnprocessable();
        $this->actingAs($organiser)->postJson("/events/{$this->event->id}/credit/bulk", ['student_ids' => [1, 1]])->assertUnprocessable();
        $this->actingAs($organiser)->postJson("/events/{$this->event->id}/credit/bulk", ['student_ids' => range(1, 501)])->assertUnprocessable();
    }
}

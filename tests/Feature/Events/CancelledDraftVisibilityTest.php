<?php

namespace Tests\Feature\Events;

use App\Enums\Capability;
use App\Enums\UserRole;
use App\Models\Event;
use App\Models\Semester;
use App\Models\Topic;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Topics\TopicTestCase;

/**
 * An event cancelled before it was published was never announced, so only its staff may
 * open it, also by direct link. One cancelled after publishing stays visible as before.
 */
class CancelledDraftVisibilityTest extends TopicTestCase
{
    private Topic $course;

    private User $student;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->course = $this->topic('Databases', $this->topic('University'));

        Semester::create([
            'name' => '2026/27 autumn',
            'starts_at' => now()->subMonth()->toDateString(),
            'ends_at' => now()->addMonths(3)->toDateString(),
            'status' => 'active',
        ]);

        $this->student = $this->makeUser(UserRole::Student);
        $this->assign($this->student, $this->role('Student', [Capability::View, Capability::EventsView]), $this->course);

        $this->manager = $this->makeUser(UserRole::Teacher);
        $this->assign($this->manager, $this->role('Organiser', [Capability::View, Capability::EventsManage]), $this->course);
    }

    private function cancelled(?string $publishedAt): Event
    {
        return Event::factory()->create([
            'owning_topic_id' => $this->course->id,
            'audience_topic_id' => $this->course->id,
            'status' => Event::STATUS_CANCELLED,
            'published_at' => $publishedAt,
            'is_hidden' => false,
            'event_starts_at' => now()->addDays(5),
            'event_ends_at' => now()->addDays(5)->addHours(2),
            'application_deadline' => now()->addDays(4),
            'completion_deadline' => now()->addDays(12),
        ]);
    }

    public function test_a_student_gets_not_found_for_a_cancelled_draft_by_direct_link(): void
    {
        $event = $this->cancelled(null);

        $this->actingAs($this->student)->get("/events/{$event->id}")->assertNotFound();
    }

    public function test_a_student_can_open_an_event_published_before_it_was_cancelled(): void
    {
        $event = $this->cancelled(now()->subDay()->toDateTimeString());

        $this->actingAs($this->student)->get("/events/{$event->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Events/Show')
                ->where('event.status', Event::STATUS_CANCELLED));
    }

    public function test_a_manager_can_open_both(): void
    {
        foreach ([null, now()->subDay()->toDateTimeString()] as $publishedAt) {
            $event = $this->cancelled($publishedAt);

            $this->actingAs($this->manager)->get("/events/{$event->id}")->assertOk();
        }
    }
}

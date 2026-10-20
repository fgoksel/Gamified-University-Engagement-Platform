<?php

namespace Tests\Feature\Events;

use App\Enums\Capability;
use App\Enums\UserRole;
use App\Models\Event;
use App\Models\EventApplication;
use App\Models\PointTransaction;
use App\Models\Semester;
use App\Models\Topic;
use App\Models\TopicTag;
use App\Models\User;
use App\Notifications\EventApplicationDecisionNotification;
use App\Services\EventApplicationService;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Topics\TopicTestCase;

/**
 * Task #41: organizer applicants list, selection mode, rejection (UC-1.6).
 */
class ApplicantSelectionTest extends TopicTestCase
{
    private Topic $course;

    private Topic $otherCourse;

    private User $teacher;

    private User $otherTeacher;

    private Semester $semester;

    private TopicTag $tag;

    protected function setUp(): void
    {
        parent::setUp();

        $university = $this->topic('University');
        $faculty = $this->topic('Faculty of Informatics', $university);
        $this->course = $this->topic('Databases', $faculty);
        $this->otherCourse = $this->topic('Programming', $faculty);

        $this->teacher = $this->makeUser(UserRole::Teacher, 'Teacher One');
        $this->assign($this->teacher, $this->role('Teacher', [Capability::View, Capability::Create]), $this->course);

        $this->otherTeacher = $this->makeUser(UserRole::Teacher, 'Teacher Two');
        $this->assign($this->otherTeacher, $this->role('Teacher B', [Capability::View, Capability::Create]), $this->otherCourse);

        $this->semester = Semester::create([
            'name' => '2026/27 autumn',
            'starts_at' => now()->subMonth()->toDateString(),
            'ends_at' => now()->addMonths(3)->toDateString(),
            'status' => 'active',
        ]);

        $this->tag = TopicTag::create(['name' => 'SQL', 'category' => 'scientific', 'is_active' => true]);
    }

    private function event(array $overrides = []): Event
    {
        return Event::create([
            'title' => 'SQL Night',
            'description' => 'Practice.',
            'category' => 'scientific',
            'topic_tag_id' => $this->tag->id,
            'created_by_id' => $this->teacher->id,
            'owning_topic_id' => $this->course->id,
            'audience_topic_id' => $this->course->id,
            'semester_id' => $this->semester->id,
            'capacity' => 2,
            'application_type' => 'teacher_select',
            'points_value' => 10,
            'event_starts_at' => now()->addDays(5),
            'event_ends_at' => now()->addDays(5)->addHours(2),
            'application_deadline' => now()->addDays(3),
            'completion_deadline' => now()->addDays(6),
            'status' => Event::STATUS_PUBLISHED,
            'published_at' => now(),
            ...$overrides,
        ]);
    }

    private function applicant(Event $event, string $neptun): EventApplication
    {
        $student = $this->makeUser(UserRole::Student, "Student {$neptun}");
        $student->update(['neptun_code' => $neptun]);
        $this->assign($student, $this->role('Student', [Capability::View, Capability::EventsView, Capability::EventsApply]), $this->course);
        $this->travel(1)->seconds();

        return app(EventApplicationService::class)->apply($student, $event);
    }

    public function test_list_is_in_arrival_order_with_points_by_category(): void
    {
        $event = $this->event();
        $first = $this->applicant($event, 'AAA111');
        $second = $this->applicant($event, 'BBB222');

        foreach ([['academic', 30], ['sports', 15], ['sports', -5]] as [$category, $points]) {
            PointTransaction::create([
                'student_id' => $first->student_id,
                'event_id' => $this->event(['title' => "Past {$category}{$points}"])->id,
                'semester_id' => $this->semester->id,
                'credited_by_id' => $this->teacher->id,
                'points' => $points,
                'type' => $points > 0 ? 'credit' : 'reversal',
                'source' => 'manual',
                'category' => $category,
                'reversal_of_id' => null,
            ]);
        }

        $this->actingAs($this->teacher)
            ->get(route('events.applicants', $event))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Events/Applicants')
                ->where('applicants.0.id', $first->id)
                ->where('applicants.1.id', $second->id)
                ->where('applicants.0.points.academic', 30)
                ->where('applicants.0.points.sports', 10)
                ->where('applicants.0.points.total', 40)
                ->where('applicants.1.points.total', 0)
            );
    }

    public function test_teacher_of_another_branch_is_refused_everywhere(): void
    {
        $event = $this->event();
        $application = $this->applicant($event, 'AAA111');

        $this->actingAs($this->otherTeacher)->get(route('events.applicants', $event))->assertRedirect('/')->assertSessionHas('error');
        $this->actingAs($this->otherTeacher)
            ->post(route('events.applicants.select', $event), ['selected' => [$application->id]])
            ->assertRedirect('/')->assertSessionHas('error');
        $this->actingAs($this->otherTeacher)
            ->post(route('events.applicants.reject', [$event, $application]))
            ->assertRedirect('/')->assertSessionHas('error');

        $this->assertSame(EventApplication::PENDING, $application->refresh()->status);
    }

    public function test_student_cannot_open_the_applicants_page(): void
    {
        $event = $this->event();
        $application = $this->applicant($event, 'AAA111');

        $this->actingAs($application->student)->get(route('events.applicants', $event))->assertRedirect('/')->assertSessionHas('error');
    }

    public function test_saving_selection_accepts_ticked_and_notifies_only_new_acceptances(): void
    {
        Notification::fake();
        $event = $this->event(['capacity' => 2]);
        $a = $this->applicant($event, 'AAA111');
        $b = $this->applicant($event, 'BBB222');
        $c = $this->applicant($event, 'CCC333');

        $this->actingAs($this->teacher)
            ->post(route('events.applicants.select', $event), ['selected' => [$a->id, $c->id]])
            ->assertSessionHas('success');

        $this->assertSame(EventApplication::ACCEPTED, $a->refresh()->status);
        $this->assertSame(EventApplication::PENDING, $b->refresh()->status);
        $this->assertSame(EventApplication::ACCEPTED, $c->refresh()->status);
        $this->assertSame($this->teacher->id, $a->decided_by_id);
        $this->assertDatabaseHas('event_application_transitions', [
            'event_application_id' => $a->id,
            'from_status' => 'pending',
            'to_status' => 'accepted',
            'actor_id' => $this->teacher->id,
        ]);

        Notification::assertSentTo($a->student, EventApplicationDecisionNotification::class,
            fn ($n) => $n->decision === 'accepted');
        Notification::assertSentTo($c->student, EventApplicationDecisionNotification::class);
        Notification::assertNotSentTo($b->student, EventApplicationDecisionNotification::class);

        // Saving the same selection again changes and notifies nobody.
        Notification::fake();
        $this->actingAs($this->teacher)
            ->post(route('events.applicants.select', $event), ['selected' => [$a->id, $c->id]]);
        Notification::assertNothingSent();
    }

    public function test_unticking_an_accepted_student_returns_them_to_pending(): void
    {
        $event = $this->event();
        $a = $this->applicant($event, 'AAA111');

        $this->actingAs($this->teacher)->post(route('events.applicants.select', $event), ['selected' => [$a->id]]);
        $this->actingAs($this->teacher)->post(route('events.applicants.select', $event), ['selected' => []]);

        $a->refresh();
        $this->assertSame(EventApplication::PENDING, $a->status);
        $this->assertNull($a->decided_at);
    }

    public function test_a_student_taken_out_of_the_selection_is_told_and_nobody_else_is(): void
    {
        $event = $this->event(['capacity' => 2]);
        $a = $this->applicant($event, 'AAA111');
        $b = $this->applicant($event, 'BBB222');
        $this->actingAs($this->teacher)->post(route('events.applicants.select', $event), ['selected' => [$a->id, $b->id]]);

        Notification::fake();
        $this->actingAs($this->teacher)->post(route('events.applicants.select', $event), ['selected' => [$b->id]]);

        Notification::assertSentTo($a->student, EventApplicationDecisionNotification::class,
            fn ($n) => $n->decision === EventApplicationDecisionNotification::REMOVED && $n->eventId === $event->id);
        Notification::assertNotSentTo($b->student, EventApplicationDecisionNotification::class);
        Notification::assertCount(1);
    }

    public function test_more_ticks_than_capacity_is_refused_without_the_expand_flag(): void
    {
        $event = $this->event(['capacity' => 1]);
        $a = $this->applicant($event, 'AAA111');
        $b = $this->applicant($event, 'BBB222');

        $this->actingAs($this->teacher)
            ->post(route('events.applicants.select', $event), ['selected' => [$a->id, $b->id]])
            ->assertSessionHasErrors('capacity');

        $this->assertSame(1, $event->refresh()->capacity);
        $this->assertSame(EventApplication::PENDING, $a->refresh()->status);
        $this->assertSame(EventApplication::PENDING, $b->refresh()->status);
    }

    public function test_expand_flag_raises_capacity_to_selected_count_and_commits(): void
    {
        $event = $this->event(['capacity' => 1]);
        $a = $this->applicant($event, 'AAA111');
        $b = $this->applicant($event, 'BBB222');

        $this->actingAs($this->teacher)
            ->post(route('events.applicants.select', $event), [
                'selected' => [$a->id, $b->id],
                'expand_capacity' => true,
            ])
            ->assertSessionHas('success');

        $this->assertSame(2, $event->refresh()->capacity);
        $this->assertSame(EventApplication::ACCEPTED, $a->refresh()->status);
        $this->assertSame(EventApplication::ACCEPTED, $b->refresh()->status);
    }

    public function test_capacity_is_never_lowered_by_a_selection(): void
    {
        $event = $this->event(['capacity' => 5]);
        $a = $this->applicant($event, 'AAA111');

        $this->actingAs($this->teacher)
            ->post(route('events.applicants.select', $event), ['selected' => [$a->id], 'expand_capacity' => true]);

        $this->assertSame(5, $event->refresh()->capacity);
    }

    public function test_selection_is_refused_for_first_come_events(): void
    {
        $event = $this->event(['application_type' => 'fcfs']);
        $a = $this->applicant($event, 'AAA111');

        $this->actingAs($this->teacher)
            ->post(route('events.applicants.select', $event), ['selected' => [$a->id]])
            ->assertSessionHasErrors('event');

        $this->assertSame(EventApplication::ACCEPTED, $a->refresh()->status);
    }

    public function test_foreign_application_ids_abort_the_whole_save(): void
    {
        $event = $this->event();
        $other = $this->event(['title' => 'Other']);
        $mine = $this->applicant($event, 'AAA111');
        $foreign = $this->applicant($other, 'BBB222');

        $this->actingAs($this->teacher)
            ->post(route('events.applicants.select', $event), ['selected' => [$mine->id, $foreign->id]])
            ->assertSessionHasErrors('selected');

        $this->assertSame(EventApplication::PENDING, $mine->refresh()->status);
        $this->assertSame(EventApplication::PENDING, $foreign->refresh()->status);
    }

    public function test_rejection_is_final_audited_and_notified(): void
    {
        Notification::fake();
        $event = $this->event();
        $a = $this->applicant($event, 'AAA111');

        $this->actingAs($this->teacher)
            ->post(route('events.applicants.reject', [$event, $a]))
            ->assertSessionHas('success');

        $this->assertSame(EventApplication::REJECTED, $a->refresh()->status);
        $this->assertDatabaseHas('event_application_transitions', [
            'event_application_id' => $a->id,
            'to_status' => 'rejected',
            'actor_id' => $this->teacher->id,
        ]);
        Notification::assertSentTo($a->student, EventApplicationDecisionNotification::class,
            fn ($n) => $n->decision === 'rejected');

        // Final: the student cannot re-apply, and the organizer cannot reject twice.
        $this->actingAs($this->teacher)
            ->post(route('events.applicants.reject', [$event, $a]))
            ->assertSessionHasErrors('application');
        $this->actingAs($a->student)
            ->postJson(route('events.apply', $event))
            ->assertStatus(422);
    }

    public function test_credited_student_cannot_be_rejected_or_unselected(): void
    {
        $event = $this->event();
        $a = $this->applicant($event, 'AAA111');
        $this->actingAs($this->teacher)->post(route('events.applicants.select', $event), ['selected' => [$a->id]]);
        $a->refresh()->update(['scored_at' => now()]);

        $this->actingAs($this->teacher)
            ->post(route('events.applicants.reject', [$event, $a]))
            ->assertSessionHasErrors('application');

        $this->actingAs($this->teacher)->post(route('events.applicants.select', $event), ['selected' => []]);
        $this->assertSame(EventApplication::ACCEPTED, $a->refresh()->status);
    }

    public function test_review_is_refused_when_the_event_is_not_published(): void
    {
        $event = $this->event(['status' => Event::STATUS_ON_HOLD]);
        $a = $this->applicant($this->event(), 'AAA111');

        $this->actingAs($this->teacher)
            ->post(route('events.applicants.select', $event), ['selected' => []])
            ->assertSessionHasErrors('event');
        $this->assertNotNull($a);
    }

    public function test_rejecting_through_another_events_url_is_not_found(): void
    {
        $event = $this->event();
        $other = $this->event(['title' => 'Other']);
        $a = $this->applicant($other, 'AAA111');

        $this->actingAs($this->teacher)
            ->post(route('events.applicants.reject', [$event, $a]))
            ->assertNotFound();
    }

    public function test_organizer_cannot_approve_their_own_application(): void
    {
        $event = $this->event();
        $this->teacher->update(['neptun_code' => 'TCH001']);
        // The organiser is also allowed to apply here, as a student would be (Task #65).
        $this->assign($this->teacher, $this->role('Participant', [Capability::View, Capability::EventsView, Capability::EventsApply]), $this->course);
        $application = app(EventApplicationService::class)->apply($this->teacher, $event);

        $this->actingAs($this->teacher)
            ->post(route('events.applicants.select', $event), ['selected' => [$application->id]])
            ->assertSessionHasErrors('selected');

        $this->assertSame(EventApplication::PENDING, $application->refresh()->status);
    }
}

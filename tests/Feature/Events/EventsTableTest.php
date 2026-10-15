<?php

namespace Tests\Feature\Events;

use App\Enums\UserRole;
use App\Models\Event;
use App\Models\EventApplication;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Topics\TopicTestCase;

/**
 * Task #6: the events and event applications tables, linked to topics.
 */
class EventsTableTest extends TopicTestCase
{
    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();

        $topic = $this->topic('Databases', $this->topic('Faculty'));
        $this->event = Event::factory()->create([
            'owning_topic_id' => $topic->id,
            'audience_topic_id' => $topic->id,
        ]);
    }

    private function student(): User
    {
        $student = $this->makeUser(UserRole::Student);
        $student->forceFill(['neptun_code' => strtoupper(substr(md5(uniqid()), 0, 6))])->save();

        return $student;
    }

    private function apply(User $student, ?Event $event = null, string $status = EventApplication::ACCEPTED): EventApplication
    {
        return EventApplication::create([
            'event_id' => ($event ?? $this->event)->id,
            'student_id' => $student->id,
            'status' => $status,
            'applied_at' => now(),
        ]);
    }

    public function test_the_tables_and_key_columns_exist(): void
    {
        foreach (['events', 'event_applications', 'event_application_transitions'] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }

        $this->assertTrue(Schema::hasColumns('events', [
            'owning_topic_id', 'audience_topic_id', 'topic_tag_id', 'created_by_id', 'semester_id', 'capacity',
            'application_type', 'points_value', 'event_starts_at', 'event_ends_at', 'application_deadline',
            'completion_deadline', 'status', 'is_hidden', 'reveal_at', 'qr_enabled', 'cancelled_at',
        ]));
        $this->assertTrue(Schema::hasColumns('event_applications', [
            'event_id', 'student_id', 'status', 'applied_at', 'decided_by_id', 'decided_at', 'withdrawn_at', 'completed_at', 'scored_at',
        ]));
        $this->assertFalse(Schema::hasColumn('events', 'deleted_at'), 'Deleting an event is permanent, not a soft delete.');
        $this->assertFalse(Schema::hasTable('event_target_courses'));
        $this->assertFalse(Schema::hasTable('student_course_enrollments'));
    }

    public function test_an_event_is_linked_to_its_owning_and_audience_topics(): void
    {
        $this->assertSame('Databases', $this->event->owningTopic->title);
        $this->assertSame('Databases', $this->event->audienceTopic->title);
        $this->assertInstanceOf(Semester::class, $this->event->semester);

        $invitationOnly = Event::factory()->create(['owning_topic_id' => $this->event->owning_topic_id, 'audience_topic_id' => null]);
        $this->assertNull($invitationOnly->audienceTopic);
    }

    public function test_the_owning_topic_is_required_and_must_exist(): void
    {
        $this->expectException(QueryException::class);
        Event::factory()->create(['owning_topic_id' => 999999]);
    }

    public function test_on_hold_is_a_status_so_a_published_query_never_returns_it(): void
    {
        $held = Event::factory()->create(['owning_topic_id' => $this->event->owning_topic_id, 'status' => 'on_hold']);

        $this->assertFalse(Schema::hasColumn('events', 'archived_at'));
        $this->assertTrue($held->fresh()->isOnHold());
        $this->assertFalse($this->event->fresh()->isOnHold());
        $this->assertSame([$this->event->id], Event::where('status', 'published')->pluck('id')->all());
        $this->assertSame([$held->id], Event::where('status', 'on_hold')->pluck('id')->all());

        foreach (['draft', 'published', 'on_hold', 'completed', 'cancelled'] as $status) {
            $held->update(['status' => $status]);
            $this->assertSame($status, $held->fresh()->status);
        }

        $this->expectException(QueryException::class);
        DB::table('events')->where('id', $held->id)->update(['status' => 'archived']);
    }

    public function test_an_application_has_one_of_the_five_states_and_a_history_table_exists(): void
    {
        foreach (['pending', 'accepted', 'waitlisted', 'rejected', 'withdrawn'] as $status) {
            $this->assertSame($status, $this->apply($this->student(), status: $status)->fresh()->status);
        }

        $this->assertSame($this->event->id, EventApplication::first()->event->id);
        $this->assertSame(5, $this->event->applications()->count());

        $this->expectException(QueryException::class);
        $this->apply($this->student(), status: 'credited');
    }

    public function test_a_student_has_one_application_record_per_event(): void
    {
        $student = $this->student();
        $this->apply($student);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->apply($student, status: EventApplication::PENDING);
    }

    public function test_an_event_with_applications_cannot_be_deleted(): void
    {
        $this->apply($this->student());

        $this->expectException(QueryException::class);
        $this->event->delete();
    }

    public function test_an_event_without_applications_can_be_deleted_for_good(): void
    {
        $this->event->delete();

        $this->assertDatabaseMissing('events', ['id' => $this->event->id]);
    }

    public function test_a_topic_that_owns_an_event_cannot_be_removed_from_the_database(): void
    {
        $this->expectException(QueryException::class);
        DB::table('topics')->where('id', $this->event->owning_topic_id)->delete();
    }
}

<?php

namespace Tests\Feature\Events;

use App\Enums\Capability;
use App\Enums\UserRole;
use App\Exceptions\EventActionRefused;
use App\Imports\NeptunCodeFile;
use App\Models\Event;
use App\Models\EventApplication;
use App\Models\PointTransaction;
use App\Models\RoleAssignment;
use App\Models\Topic;
use App\Models\User;
use App\Notifications\PointsCreditedNotification;
use App\Services\NotificationFeed;
use App\Services\PointLedger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Task #85: give an event's points to an imported list of students (26 Oct, rec. 07:08).
 * Team decisions of 27 Oct: one column of Neptun codes; students not assigned to the
 * event's area are skipped and listed with the reason; importing again adds only new
 * students; a surprise event is credited this way, and students get a notification.
 */
class EventPointsImportTest extends EventTestCase
{
    private User $organiser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organiser = $this->staff([Capability::PointsCredit], name: 'Olga Organiser');
    }

    /**
     * A student account with the student role at a topic (the course unless said otherwise).
     */
    private function member(string $neptun, ?Topic $at = null, string $status = 'active'): User
    {
        $student = $this->makeUser(UserRole::Student, "Student {$neptun}", $status);
        $student->forceFill(['neptun_code' => $neptun])->save();
        $this->assign($student, $this->studentRole(), $at ?? $this->course);

        return $student;
    }

    private function csv(string ...$lines): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('list.csv', implode("\n", $lines)."\n");
    }

    private function check(UploadedFile $file, ?Event $event = null, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->organiser)->post(
            '/events/'.($event ?? $this->event)->id.'/points-import/preview',
            ['file' => $file],
            ['Accept' => 'application/json'],
        );
    }

    private function give(UploadedFile $file, ?Event $event = null, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->organiser)->post(
            '/events/'.($event ?? $this->event)->id.'/points-import',
            ['file' => $file],
            ['Accept' => 'application/json'],
        );
    }

    /**
     * @return array<int, string> line => reason
     */
    private function reasons(TestResponse $response, string $list): array
    {
        return collect($response->json($list))->pluck('reason', 'line')->all();
    }

    public function test_the_check_sorts_every_row_with_its_reason_and_saves_nothing(): void
    {
        $this->member('ANNA01');
        $bela = $this->member('BELA02');
        app(PointLedger::class)->creditImported($this->event, $bela, $this->organiser);
        $this->member('FACU03', $this->faculty);
        $teacher = $this->makeUser(UserRole::Teacher, 'A teacher');
        $teacher->forceFill(['neptun_code' => 'TEAC04'])->save();
        $this->member('INVI05', status: 'invited');
        $this->member('GONE06', status: 'inactive');

        $response = $this->check($this->csv(
            'Neptun Code', 'anna01', 'BELA02', 'NOPE07', 'ANNA01', 'Total: 9', 'FACU03', 'TEAC04', 'INVI05', 'GONE06',
        ))->assertOk();

        $response->assertJsonPath('refusal', null)
            ->assertJsonCount(1, 'credit')
            ->assertJsonPath('credit.0', ['line' => 2, 'neptun_code' => 'ANNA01', 'name' => 'Student ANNA01']);
        $this->assertSame([3 => 'Already has the points for this event.'], $this->reasons($response, 'already'));
        $this->assertSame([
            4 => 'No student account has this Neptun code.',
            5 => 'Listed already on row 2.',
            6 => 'This is not a Neptun code: it should be 6 letters or digits.',
            7 => 'Not assigned to Databases, the area of this event.',
            8 => 'No student account has this Neptun code.',
            9 => 'Has not activated their account yet. Import them again once they have.',
            10 => 'Their account is deactivated.',
        ], $this->reasons($response, 'skipped'));

        // Only Bela's points from before.
        $this->assertSame(1, PointTransaction::count());
    }

    public function test_giving_the_points_credits_each_listed_student_once_and_tells_them(): void
    {
        Notification::fake();
        $anna = $this->member('ANNA01');
        $bela = $this->member('BELA02');
        $this->accepted($bela);
        $cili = $this->member('CILI03');
        $this->accepted($cili, status: EventApplication::PENDING);

        $response = $this->give($this->csv('Neptun Code', 'ANNA01', 'BELA02', 'CILI03', 'NOPE07'))->assertOk();

        $response->assertJsonPath('message', '3 students received 10 points each.')
            ->assertJsonPath('credited_count', 3)
            ->assertJsonCount(3, 'credited')
            ->assertJsonCount(0, 'already')
            ->assertJsonPath('skipped.0.neptun_code', 'NOPE07');

        foreach ([$anna, $bela, $cili] as $student) {
            $row = PointTransaction::query()->where('student_id', $student->id)->sole();
            $this->assertSame(
                [$this->event->id, 10, PointLedger::IMPORT, 'academic', $this->organiser->id],
                [$row->event_id, $row->points, $row->source, $row->category, $row->credited_by_id],
            );
        }

        // An accepted application shows the credit too; a pending one is left for the organiser to decide.
        $this->assertNotNull(EventApplication::query()->where('student_id', $bela->id)->value('scored_at'));
        $this->assertNull(EventApplication::query()->where('student_id', $cili->id)->value('scored_at'));

        Notification::assertCount(3);
        Notification::assertSentTo(
            [$anna, $bela, $cili],
            PointsCreditedNotification::class,
            fn (PointsCreditedNotification $sent) => $sent->eventId === $this->event->id && $sent->points === 10 && $sent->category === 'academic',
        );
    }

    public function test_importing_the_same_list_again_adds_only_the_new_students(): void
    {
        Notification::fake();
        $this->member('ANNA01');
        $bela = $this->member('BELA02');

        $this->give($this->csv('Neptun Code', 'ANNA01'))->assertOk()->assertJsonCount(1, 'credited');

        $again = $this->give($this->csv('Neptun Code', 'ANNA01', 'BELA02'))->assertOk();
        $again->assertJsonCount(1, 'credited')
            ->assertJsonPath('credited.0.neptun_code', 'BELA02')
            ->assertJsonPath('already.0.neptun_code', 'ANNA01')
            ->assertJsonPath('message', '1 student received 10 points.');

        $this->give($this->csv('Neptun Code', 'ANNA01', 'BELA02'))->assertOk()
            ->assertJsonCount(0, 'credited')
            ->assertJsonPath('message', 'Nobody received points: everyone on the list was skipped or already had them.');

        $this->assertSame(2, PointTransaction::count());
        Notification::assertSentToTimes($bela, PointsCreditedNotification::class, 1);
        Notification::assertCount(2);
    }

    public function test_only_students_assigned_to_the_event_area_itself_receive_the_points(): void
    {
        // Run by the course, announced to the faculty: the faculty's own students count.
        $event = $this->makeEvent(['audience_topic_id' => $this->faculty->id]);
        $this->member('FACU01', $this->faculty);
        $this->member('COUR02', $this->course);
        $this->member('UNIV03', $this->university);
        $left = $this->member('LEFT04', $this->faculty);
        RoleAssignment::query()->where('user_id', $left->id)->update(['ended_at' => now()]);

        $response = $this->give($this->csv('Neptun Code', 'FACU01', 'COUR02', 'UNIV03', 'LEFT04'), $event)->assertOk();

        $response->assertJsonCount(1, 'credited')->assertJsonPath('credited.0.neptun_code', 'FACU01');
        $this->assertSame([
            3 => 'Not assigned to Faculty, the area of this event.',
            4 => 'Not assigned to Faculty, the area of this event.',
            5 => 'Not assigned to Faculty, the area of this event.',
        ], $this->reasons($response, 'skipped'));
    }

    public function test_an_event_announced_to_nobody_takes_the_students_of_its_own_topic(): void
    {
        $event = $this->makeEvent(['audience_topic_id' => null]);
        $this->member('COUR01', $this->course);
        $this->member('FACU02', $this->faculty);

        $response = $this->give($this->csv('Neptun Code', 'COUR01', 'FACU02'), $event)->assertOk();

        $response->assertJsonCount(1, 'credited')->assertJsonPath('credited.0.neptun_code', 'COUR01');
        $this->assertSame([3 => 'Not assigned to Databases, the area of this event.'], $this->reasons($response, 'skipped'));
    }

    public function test_a_role_in_the_area_counts_only_when_it_includes_view_events(): void
    {
        // The same rule as for seeing an event announced there (Task #71).
        $looker = $this->makeUser(UserRole::Student, 'Student LOOK01');
        $looker->forceFill(['neptun_code' => 'LOOK01'])->save();
        $this->assign($looker, $this->role('Topic reader', [Capability::View]), $this->course);
        $this->member('VIEW02');

        $response = $this->give($this->csv('Neptun Code', 'LOOK01', 'VIEW02'))->assertOk();

        $response->assertJsonCount(1, 'credited')->assertJsonPath('credited.0.neptun_code', 'VIEW02');
        $this->assertSame([2 => 'Their role in Databases does not include "View events".'], $this->reasons($response, 'skipped'));
        $this->assertRefused('not_in_area', fn () => app(PointLedger::class)->creditImported($this->event, $looker, $this->organiser));
    }

    public function test_a_surprise_event_is_credited_without_any_application(): void
    {
        $surprise = $this->makeEvent(['is_hidden' => true, 'reveal_at' => now()->addMonth()]);
        $student = $this->member('SURP01');

        $this->give($this->csv('Neptun Code', 'SURP01'), $surprise)->assertOk()->assertJsonCount(1, 'credited');

        $this->assertSame(0, EventApplication::count());
        $this->assertSame(10, app(PointLedger::class)->totals($student)['total']);
    }

    public function test_a_completed_event_still_takes_points_until_its_points_end_date(): void
    {
        $event = $this->makeEvent([
            'status' => Event::STATUS_COMPLETED,
            'event_starts_at' => now()->subDays(2),
            'event_ends_at' => now()->subDays(2)->addHours(2),
            'completion_deadline' => now()->addDay(),
        ]);
        $this->member('DONE01');

        $this->give($this->csv('Neptun Code', 'DONE01'), $event)->assertOk()->assertJsonCount(1, 'credited');
    }

    public function test_nothing_is_given_while_the_event_takes_no_points(): void
    {
        $this->member('WAIT01');
        $cases = [
            [['status' => Event::STATUS_ON_HOLD], 'This event is on hold. Points cannot be credited until it is restored.'],
            [['status' => Event::STATUS_CANCELLED], 'This event is no longer active.'],
            [['status' => Event::STATUS_DRAFT], 'This event is no longer active.'],
            [['event_starts_at' => now()->addDay(), 'event_ends_at' => now()->addDay()->addHours(2), 'completion_deadline' => now()->addDays(2)], 'The event has not started yet.'],
            [['event_starts_at' => now()->subDays(3), 'event_ends_at' => now()->subDays(3)->addHours(2), 'completion_deadline' => now()->subDay()], "This event's completion deadline has passed."],
        ];

        foreach ($cases as [$attributes, $message]) {
            $event = $this->makeEvent($attributes);

            // The check still shows who would receive the points, and why it cannot happen now.
            $this->check($this->csv('Neptun Code', 'WAIT01'), $event)->assertOk()
                ->assertJsonPath('refusal', $message)
                ->assertJsonCount(1, 'credit');
            $this->give($this->csv('Neptun Code', 'WAIT01'), $event)->assertStatus(409)->assertJsonPath('message', $message);
        }

        $this->assertSame(0, PointTransaction::count());
    }

    public function test_only_people_who_may_credit_points_for_the_event_can_use_it(): void
    {
        $manager = $this->staff([Capability::EventsCreate, Capability::EventsManage]);
        $student = $this->member('STUD01');
        $elsewhere = $this->staff([Capability::PointsCredit], $this->topic('Another branch'));

        foreach ([$manager, $student, $elsewhere] as $person) {
            // A refused page visit goes back to the start page with a message (bootstrap/app.php).
            $this->actingAs($person)->get("/events/{$this->event->id}/points-import")
                ->assertRedirect('/')->assertSessionHas('error');
            $this->actingAs($person)->getJson("/events/{$this->event->id}/points-import")->assertForbidden();
            $this->check($this->csv('Neptun Code', 'STUD01'), as: $person)->assertForbidden();
            $this->give($this->csv('Neptun Code', 'STUD01'), as: $person)->assertForbidden();
        }

        $this->assertSame(0, PointTransaction::count());
    }

    public function test_a_student_tutor_who_may_credit_cannot_give_points_to_themselves(): void
    {
        $tutor = $this->member('TUTO01');
        $this->assign($tutor, $this->role('Tutor', [Capability::View, Capability::EventsView, Capability::PointsCredit]), $this->course);
        $this->member('PEER02');

        $response = $this->give($this->csv('Neptun Code', 'TUTO01', 'PEER02'), as: $tutor)->assertOk();

        $response->assertJsonCount(1, 'credited')->assertJsonPath('credited.0.neptun_code', 'PEER02');
        $this->assertSame([2 => 'You cannot give points to yourself.'], $this->reasons($response, 'skipped'));
    }

    public function test_whoever_helps_run_the_event_is_skipped_such_as_a_student_tutor(): void
    {
        // A student of the course whom a teacher also made its tutor (team rule).
        $tutor = $this->member('TUTO01');
        $this->assign($tutor, $this->role('Tutor', [Capability::View, Capability::EventsView, Capability::PointsCredit]), $this->course);
        // A role above the course that lets them create its events counts as well.
        $helper = $this->member('HELP02');
        $this->assign($helper, $this->role('Event helper', [Capability::View, Capability::EventsView, Capability::EventsCreate]), $this->faculty);
        $this->member('PEER03');
        $reason = 'Helps run this event, for example as a student tutor, so cannot receive its points.';

        $check = $this->check($this->csv('Neptun Code', 'TUTO01', 'HELP02', 'PEER03'))->assertOk();
        $check->assertJsonCount(1, 'credit')->assertJsonPath('credit.0.neptun_code', 'PEER03');
        $this->assertSame([2 => $reason, 3 => $reason], $this->reasons($check, 'skipped'));

        $given = $this->give($this->csv('Neptun Code', 'TUTO01', 'HELP02', 'PEER03'))->assertOk();
        $given->assertJsonCount(1, 'credited')->assertJsonPath('credited.0.neptun_code', 'PEER03');
        $this->assertSame([2 => $reason, 3 => $reason], $this->reasons($given, 'skipped'));
        $this->assertSame(1, PointTransaction::count());
    }

    public function test_only_an_accepted_application_is_marked_as_credited(): void
    {
        $statuses = [EventApplication::ACCEPTED, EventApplication::PENDING, EventApplication::WAITLISTED, EventApplication::REJECTED, EventApplication::WITHDRAWN];
        $applications = [];

        foreach ($statuses as $index => $status) {
            $applications[$status] = $this->accepted($this->member('APPL0'.($index + 1)), status: $status);
        }

        $this->give($this->csv('Neptun Code', 'APPL01', 'APPL02', 'APPL03', 'APPL04', 'APPL05'))->assertOk()
            ->assertJsonCount(5, 'credited');

        // The points are theirs either way, but a rejected or withdrawn application must not look credited on My events.
        foreach ($applications as $status => $application) {
            $application->refresh();
            $this->assertSame($status, $application->status);
            $this->assertSame($status === EventApplication::ACCEPTED, $application->isCredited(), $status);
            $this->assertSame($status === EventApplication::ACCEPTED, $application->completed_at !== null, $status);
        }
    }

    public function test_points_that_were_taken_back_are_not_given_again(): void
    {
        $student = $this->member('BACK01');
        $ledger = app(PointLedger::class);
        $ledger->reverse($ledger->creditImported($this->event, $student, $this->organiser), $this->admin, 'Given by mistake');

        $response = $this->check($this->csv('Neptun Code', 'BACK01'))->assertOk();

        $this->assertSame([2 => 'Their points for this event were taken back, so they cannot be given again.'], $this->reasons($response, 'already'));
        $this->assertSame(0, $ledger->totals($student)['total']);
    }

    public function test_the_ledger_checks_the_account_the_area_and_the_single_credit_again_when_it_writes(): void
    {
        $ledger = app(PointLedger::class);
        $outside = $this->member('OUTS01', $this->faculty);
        $inside = $this->member('INSI02');

        $this->assertRefused('not_in_area', fn () => $ledger->creditImported($this->event, $outside, $this->organiser));
        // A teacher who sees the course's events is not one of its students.
        $this->assertRefused('not_a_student', fn () => $ledger->creditImported($this->event, $this->staff([]), $this->organiser));

        $ledger->creditImported($this->event, $inside, $this->organiser);
        $this->assertRefused('already_credited', fn () => $ledger->creditImported($this->event, $inside, $this->organiser));

        // A scan later on adds nothing either, even after the student applied and was accepted.
        $this->accepted($inside);
        $this->assertRefused('already_credited', fn () => $ledger->credit($this->event, $inside, $this->organiser, PointLedger::PASSPORT));

        $this->assertSame(1, PointTransaction::count());
    }

    public function test_files_that_cannot_be_used_are_refused_with_a_clear_message(): void
    {
        $this->member('ANNA01');
        $url = "/events/{$this->event->id}/points-import/preview";

        $this->actingAs($this->organiser)->post($url, [], ['Accept' => 'application/json'])
            ->assertJsonValidationErrors(['file' => 'Choose a file.']);
        $this->check($this->csv('Name', 'Anna'))
            ->assertJsonValidationErrors(['file' => 'The first line of the file must name the columns, and one of them must be "Neptun Code".']);
        $this->check($this->csv('Neptun Code'))
            ->assertJsonValidationErrors(['file' => 'The file has no Neptun codes below its first line.']);
        $this->check(UploadedFile::fake()->createWithContent('empty.csv', ''))
            ->assertJsonValidationErrors(['file' => 'The file is empty.']);
        $this->check(UploadedFile::fake()->createWithContent('list.xlsx', "Neptun Code\nANNA01\n"))
            ->assertJsonValidationErrors(['file' => 'The file must be a CSV file (.csv or .txt).']);
        $this->check($this->csv('Neptun Code', ...array_fill(0, NeptunCodeFile::MAX_ROWS + 1, 'ANNA01')))
            ->assertJsonValidationErrors(['file' => 'The file has more than 5,000 rows. Split it and import the parts one after the other.']);

        // The giving step reads the file the same way.
        $this->give($this->csv('Name', 'Anna'))->assertJsonValidationErrors('file');
        $this->assertSame(0, PointTransaction::count());
    }

    public function test_exports_with_more_columns_semicolons_a_byte_order_mark_or_a_hungarian_heading_are_read(): void
    {
        $this->member('ANNA01');
        $this->member('BELA02');
        $this->member('CSIL03');

        // A Moodle export: commas, more columns, a row without a code.
        $moodle = $this->check($this->csv('First name,Last name,Neptun Code,Email', 'Anna,Nagy,anna01,anna@example.test', 'No,Code,,nobody@example.test'))->assertOk();
        $moodle->assertJsonPath('credit.0.neptun_code', 'ANNA01');
        $this->assertSame([3 => 'The Neptun code is empty.'], $this->reasons($moodle, 'skipped'));

        // Excel in Hungarian: semicolons, Windows line ends and a byte order mark.
        $excel = UploadedFile::fake()->createWithContent('list.csv', "\u{FEFF}Név;Neptun Code\r\nBéla;BELA02\r\n");
        $this->check($excel)->assertOk()->assertJsonPath('credit.0.neptun_code', 'BELA02');

        // A Neptun export with its Hungarian heading, separated by tabs, saved as text.
        $neptun = UploadedFile::fake()->createWithContent('export.txt', "Neptun kód\tNév\nCSIL03\tCsilla\n");
        $this->check($neptun)->assertOk()->assertJsonPath('credit.0.neptun_code', 'CSIL03');
    }

    public function test_the_page_shows_the_area_and_whether_points_can_be_given_now(): void
    {
        $this->actingAs($this->organiser)->get("/events/{$this->event->id}/points-import")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Events/PointsImport')
                ->where('event.title', $this->event->title)
                ->where('event.area', 'Databases')
                ->where('event.points', 10)
                ->where('event.is_surprise', false)
                ->where('refusal', null)
                ->where('creditedCount', 0)
                ->where('maxRows', NeptunCodeFile::MAX_ROWS));

        $held = $this->makeEvent(['status' => Event::STATUS_ON_HOLD]);
        $this->actingAs($this->organiser)->get("/events/{$held->id}/points-import")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('refusal', 'This event is on hold. Points cannot be credited until it is restored.'));
    }

    public function test_the_notification_says_what_was_received_and_links_the_event_only_when_the_student_may_open_it(): void
    {
        $anna = $this->member('ANNA01');
        $surprise = $this->makeEvent([
            'title' => 'Grade 5 in Programming 1',
            'points_value' => 20,
            'is_hidden' => true,
            'reveal_at' => now()->addMonth(),
        ]);

        $this->give($this->csv('Neptun Code', 'ANNA01'))->assertOk();
        $this->give($this->csv('Neptun Code', 'ANNA01'), $surprise)->assertOk();

        $feed = app(NotificationFeed::class);
        $shown = $anna->notifications()->get()->map(fn ($notification) => $feed->present($notification))->keyBy('title');

        $this->assertSame(
            "The organizer gave you the points of {$this->event->title}. They count under Academic.",
            $shown['You received 10 points']['body'],
        );
        $this->assertSame("/events/{$this->event->id}", $shown['You received 10 points']['link']);

        // The surprise event stays hidden from her for now, so there is nothing to open yet.
        $this->assertSame('The organizer gave you the points of Grade 5 in Programming 1. They count under Academic.', $shown['You received 20 points']['body']);
        $this->assertNull($shown['You received 20 points']['link']);
    }

    private function assertRefused(string $reason, callable $action): void
    {
        try {
            $action();
        } catch (EventActionRefused $refused) {
            $this->assertSame($reason, $refused->reason);

            return;
        }

        $this->fail("Expected the ledger to refuse with {$reason}.");
    }
}

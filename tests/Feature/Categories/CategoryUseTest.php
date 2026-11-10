<?php

namespace Tests\Feature\Categories;

use App\Enums\Capability;
use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Event;
use App\Models\PointTransaction;
use App\Models\Semester;
use App\Models\SubjectArea;
use App\Models\TopicTag;
use App\Models\TopicTagRequest;
use App\Models\User;
use App\Notifications\PointsCreditedNotification;
use App\Services\ApplicantReviewService;
use App\Services\CategoryList;
use App\Services\EventEditor;
use App\Services\PointCalculationService;
use App\Services\PointLedger;
use App\Services\TopicTagRequestService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Events\EventTestCase;

/**
 * Task #116 (D10, N6): an event needs neither a tag nor a category, and a category added to the
 * list works for tags, tag requests, events, points, the filters and the totals without a code
 * change. A retired category stays where it is and is offered for nothing new.
 */
class CategoryUseTest extends EventTestCase
{
    private User $organiser;

    private TopicTag $sql;

    protected function setUp(): void
    {
        parent::setUp();

        Semester::query()->exists() || Semester::create(['name' => 'Autumn', 'starts_at' => now()->subMonth()->toDateString(), 'ends_at' => now()->addMonths(3)->toDateString(), 'status' => 'active']);
        $this->organiser = $this->staff([Capability::EventsCreate, Capability::EventsManage, Capability::PointsCredit, Capability::ApplicationsManage]);
        $this->sql = TopicTag::create(['name' => 'SQL', 'category' => 'academic', 'is_active' => true]);
    }

    /**
     * The Event Form as it is posted.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function form(array $overrides = []): array
    {
        return [
            'action' => 'publish',
            'title' => 'Open lab',
            'description' => 'Come and build things.',
            'topic_tag_id' => null,
            'category' => null,
            'owning_topic_id' => $this->course->id,
            'audience_topic_id' => $this->course->id,
            'capacity' => 20,
            'application_type' => 'fcfs',
            'points_value' => 4,
            'event_starts_at' => now()->addDays(3)->format('Y-m-d\TH:i'),
            'event_ends_at' => now()->addDays(3)->addHours(2)->format('Y-m-d\TH:i'),
            'application_deadline' => now()->addDays(2)->format('Y-m-d\TH:i'),
            'completion_deadline' => now()->addDays(10)->format('Y-m-d\TH:i'),
            'is_hidden' => false,
            'qr_enabled' => false,
            ...$overrides,
        ];
    }

    private function create(array $overrides = []): Event
    {
        $this->actingAs($this->organiser)->post('/events', $this->form($overrides))->assertSessionHasNoErrors();

        return Event::query()->latest('id')->firstOrFail();
    }

    /**
     * The edit form of an event as it is, with some fields changed.
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function editForm(Event $event, array $changes = []): array
    {
        $event->refresh();

        return [
            'version' => app(EventEditor::class)->version($event),
            'title' => $event->title,
            'description' => $event->description,
            'topic_tag_id' => $event->topic_tag_id,
            'category' => $event->category,
            'owning_topic_id' => $event->owning_topic_id,
            'audience_topic_id' => $event->audience_topic_id,
            'capacity' => $event->capacity,
            'application_type' => $event->application_type,
            'points_value' => $event->points_value,
            'event_starts_at' => $event->event_starts_at->format('Y-m-d\TH:i'),
            'event_ends_at' => $event->event_ends_at->format('Y-m-d\TH:i'),
            'application_deadline' => $event->application_deadline->format('Y-m-d\TH:i'),
            'completion_deadline' => $event->completion_deadline->format('Y-m-d\TH:i'),
            'is_hidden' => $event->is_hidden,
            'qr_enabled' => $event->qr_enabled,
            ...$changes,
        ];
    }

    private function upcoming(array $data = []): Event
    {
        return $this->makeEvent([
            'event_starts_at' => now()->addDays(5)->startOfHour(),
            'event_ends_at' => now()->addDays(5)->startOfHour()->addHours(2),
            'application_deadline' => now()->addDays(4)->startOfHour(),
            'completion_deadline' => now()->addDays(12)->startOfHour(),
            ...$data,
        ]);
    }

    private function credit(Event $event, User $student): PointTransaction
    {
        return app(PointLedger::class)->credit($event, $student, $this->organiser, PointLedger::PASSPORT);
    }

    private function retire(string $slug): void
    {
        Category::query()->where('slug', $slug)->update(['retired_at' => now()]);
        app(CategoryList::class)->flush();
    }

    public function test_an_event_is_published_with_no_tag_and_no_category(): void
    {
        $event = $this->create();

        $this->assertSame(Event::STATUS_PUBLISHED, $event->status);
        $this->assertNull($event->topic_tag_id);
        $this->assertNull($event->category);

        // A draft without a tag can be published later too.
        $draft = $this->create(['action' => 'draft', 'title' => 'Draft lab']);
        $this->actingAs($this->organiser)->post("/events/{$draft->id}/publish")->assertSessionHas('success');
        $this->assertSame(Event::STATUS_PUBLISHED, $draft->refresh()->status);
    }

    public function test_an_event_may_have_a_category_without_a_tag_and_a_tag_brings_its_own(): void
    {
        $this->assertSame('sports', $this->create(['category' => 'sports'])->category);

        // The tag decides: its category is stored, whatever else was sent.
        $tagged = $this->create(['title' => 'SQL night', 'topic_tag_id' => $this->sql->id, 'category' => 'sports']);
        $this->assertSame($this->sql->id, $tagged->topic_tag_id);
        $this->assertSame('academic', $tagged->category);
    }

    public function test_only_tags_and_categories_in_use_can_be_chosen_for_a_new_event(): void
    {
        $choir = TopicTag::create(['name' => 'Choir', 'category' => 'community', 'is_active' => true]);
        $old = TopicTag::create(['name' => 'Old tag', 'category' => 'academic', 'is_active' => false]);
        $this->retire('community');

        $this->actingAs($this->organiser)->get('/events/create')
            ->assertInertia(fn (Assert $page) => $page
                ->has('tags', 1)
                ->where('tags.0.name', 'SQL'));

        $this->actingAs($this->organiser)->post('/events', $this->form(['category' => 'community']))
            ->assertSessionHasErrors(['category' => __('events.validation.this_category_is_no_longer')]);
        $this->actingAs($this->organiser)->post('/events', $this->form(['topic_tag_id' => $choir->id]))
            ->assertSessionHasErrors('topic_tag_id');
        $this->actingAs($this->organiser)->post('/events', $this->form(['topic_tag_id' => $old->id]))
            ->assertSessionHasErrors('topic_tag_id');
        $this->actingAs($this->organiser)->post('/events', $this->form(['category' => 'nothing_like_it']))
            ->assertSessionHasErrors('category');

        $this->assertSame(1, Event::query()->count());
    }

    public function test_a_new_category_works_everywhere_without_a_code_change(): void
    {
        // The system admin adds it, a teacher asks for a tag under it, and the tag is approved.
        $this->actingAs($this->admin)->post('/categories', ['name' => 'Robotics', 'color' => 'teal'])->assertSessionHasNoErrors();
        $this->actingAs($this->organiser)->post('/topic-tag-requests', [
            'proposed_name' => 'Arduino', 'category' => 'robotics', 'justification' => 'Hardware club.',
        ])->assertSessionHasNoErrors();
        $tag = app(TopicTagRequestService::class)->approve(TopicTagRequest::query()->firstOrFail(), $this->admin);
        $this->assertSame('robotics', $tag->category);

        // An event with that tag, and its points.
        $event = $this->create(['title' => 'Robot race', 'topic_tag_id' => $tag->id]);
        $this->assertSame('robotics', $event->category);
        $student = $this->student('Anna Nagy', 'ANNA01');
        $this->assign($student, $this->studentRole(), $this->course);
        $this->accepted($student, $event);
        $event->update(['event_starts_at' => now()->subHour(), 'application_deadline' => now()->subDay()]);
        $this->assertSame('robotics', $this->credit($event, $student)->category);

        // The totals have a place for it, next to the four.
        $points = app(PointCalculationService::class);
        $this->assertSame(['total' => 4, 'academic' => 0, 'scientific' => 0, 'sports' => 0, 'community' => 0, 'robotics' => 4], $points->studentTotals($student->id));
        $row = $points->areaStandings($this->university->id)[0];
        $this->assertSame(4, $row['robotics']);
        $this->assertSame(4, $row['points']);
        $this->assertSame(4, $points->standings($this->university->id, 'robotics')[0]['points']);

        // The filters take it: "Where am I?", the event list, and the applicants' points.
        $this->actingAs($student)->get('/leaderboards?'.http_build_query(['area' => $this->course->id, 'category' => 'robotics']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('rankings.filters.category', 'robotics')
                ->where('rankings.result.you.points', 4));
        $this->actingAs($student)->get('/events?'.http_build_query(['category' => 'robotics', 'when' => 'any']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.category', 'robotics')
                ->has('events.data', 1)
                ->where('events.data.0.id', $event->id));
        $applicant = app(ApplicantReviewService::class)->applicants($event)->first();
        $this->assertSame(4, $applicant['points']['robotics']);
        $this->assertSame(4, $applicant['points']['total']);
    }

    public function test_points_of_an_event_without_a_category_count_in_the_total_only(): void
    {
        $student = $this->student();
        $plain = $this->makeEvent(['category' => null, 'topic_tag_id' => null, 'points_value' => 6]);
        $sql = $this->makeEvent(['category' => 'academic', 'topic_tag_id' => $this->sql->id, 'points_value' => 4]);
        $this->accepted($student, $plain);
        $this->accepted($student, $sql);

        $this->assertNull($this->credit($plain, $student)->category);
        $this->credit($sql, $student);

        $points = app(PointCalculationService::class);
        $this->assertSame(['total' => 10, 'academic' => 4, 'scientific' => 0, 'sports' => 0, 'community' => 0], $points->studentTotals($student->id));
        $row = $points->areaStandings($this->course->id)[0];
        $this->assertSame(10, $row['points']);
        $this->assertSame(4, $row['academic']);
        $this->assertSame(10, app(ApplicantReviewService::class)->applicants($plain)->first()['points']['total']);

        // A reversal of the points without a category takes them off the total again.
        app(PointLedger::class)->reverse(PointTransaction::query()->where('event_id', $plain->id)->firstOrFail(), $this->admin, 'Wrong student');
        $this->assertSame(4, $points->studentTotals($student->id)['total']);
    }

    public function test_the_points_message_names_the_category_as_it_is_now_or_none(): void
    {
        $student = $this->student();
        $notify = fn (?string $category) => $student->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => PointsCreditedNotification::class,
            'data' => (new PointsCreditedNotification($this->event->id, 'Open lab', 3, $category))->toArray($student),
        ]);
        $notify('sports');
        Category::query()->where('slug', 'sports')->first()->update(['name' => 'Sport and games']);
        $notify(null);

        $bodies = collect($this->actingAs($student)->get('/notifications')->inertiaPage()['props']['notifications']['data'])->pluck('body');

        $this->assertContains(__('notifications.feed.points.body_with_category', ['event' => 'Open lab', 'category' => 'Sport and games']), $bodies);
        $this->assertContains(__('notifications.feed.points.body', ['event' => 'Open lab']), $bodies);
    }

    public function test_editing_keeps_a_retired_category_but_cannot_choose_one(): void
    {
        $event = $this->upcoming(['category' => 'community', 'topic_tag_id' => null]);
        $this->retire('community');

        // Saving other changes keeps it.
        $this->actingAs($this->organiser)->put("/events/{$event->id}", $this->editForm($event, ['title' => 'Renamed']))->assertSessionHasNoErrors();
        $this->assertSame('community', $event->refresh()->category);
        $this->assertSame('Renamed', $event->title);

        // The edit page still offers it for this event.
        $this->actingAs($this->organiser)->get("/events/{$event->id}/edit")
            ->assertInertia(fn (Assert $page) => $page->where('event.category', 'community'));

        // Another event cannot take it, and once changed it cannot come back.
        $other = $this->upcoming(['category' => 'sports']);
        $this->actingAs($this->organiser)->put("/events/{$other->id}", $this->editForm($other, ['category' => 'community']))
            ->assertSessionHasErrors(['category' => __('events.editor.this_category_is_no_longer')]);
        $this->actingAs($this->organiser)->put("/events/{$event->id}", $this->editForm($event, ['category' => 'sports']))->assertSessionHasNoErrors();
        $this->actingAs($this->organiser)->put("/events/{$event->id}", $this->editForm($event, ['category' => 'community']))->assertSessionHasErrors('category');

        // Tag and category can both be taken off.
        $tagged = $this->upcoming(['category' => 'academic', 'topic_tag_id' => $this->sql->id]);
        $this->actingAs($this->organiser)->put("/events/{$tagged->id}", $this->editForm($tagged, ['topic_tag_id' => null, 'category' => null]))->assertSessionHasNoErrors();
        $this->assertNull($tagged->refresh()->topic_tag_id);
        $this->assertNull($tagged->category);
    }

    public function test_a_page_opened_before_the_category_field_existed_keeps_the_category(): void
    {
        $event = $this->upcoming(['category' => 'sports', 'topic_tag_id' => null]);
        $form = $this->editForm($event, ['title' => 'Kept']);
        unset($form['category']);

        $this->actingAs($this->organiser)->put("/events/{$event->id}", $form)->assertSessionHasNoErrors();
        $this->assertSame('sports', $event->refresh()->category);
    }

    public function test_credited_points_lock_the_category_with_the_tag(): void
    {
        $event = $this->upcoming(['category' => 'academic', 'topic_tag_id' => $this->sql->id]);
        $student = $this->student();
        $this->accepted($student, $event);
        $event->update(['event_starts_at' => now()->subHour(), 'application_deadline' => now()->subDay()]);
        $this->credit($event, $student);

        $this->assertArrayHasKey('category', app(EventEditor::class)->locks($event->refresh()));
        $this->actingAs($this->organiser)->put("/events/{$event->id}", $this->editForm($event, ['category' => 'sports', 'topic_tag_id' => null]))
            ->assertSessionHasErrors(['category', 'topic_tag_id']);
        $this->actingAs($this->organiser)->put("/events/{$event->id}", $this->editForm($event, ['capacity' => 40]))->assertSessionHasNoErrors();

        $this->assertSame('academic', $event->refresh()->category);
        $this->assertSame('academic', PointTransaction::query()->where('event_id', $event->id)->value('category'));
    }

    public function test_the_change_log_names_the_category(): void
    {
        $event = $this->upcoming(['category' => 'academic', 'topic_tag_id' => null]);
        $this->actingAs($this->organiser)->put("/events/{$event->id}", $this->editForm($event, ['category' => 'sports']))->assertSessionHasNoErrors();

        $this->actingAs($this->organiser)->get("/events/{$event->id}/edit")
            ->assertInertia(fn (Assert $page) => $page
                ->where('history.0.changes.0', ['field' => 'category', 'old' => 'Academic', 'new' => 'Sports']));
    }

    public function test_tag_requests_and_approvals_need_a_category_in_use(): void
    {
        $this->retire('community');

        $this->actingAs($this->organiser)->post('/topic-tag-requests', [
            'proposed_name' => 'Choir', 'category' => 'community', 'justification' => 'We sing.',
        ])->assertSessionHasErrors(['category' => __('tag_requests.flash.please_select_a_valid_category')]);

        // A request sent before the category was retired is not approved into it.
        $request = TopicTagRequest::create([
            'proposed_name' => 'Choir', 'category' => 'community', 'justification' => 'We sing.',
            'status' => 'pending', 'requested_by_id' => $this->organiser->id,
        ]);

        try {
            app(TopicTagRequestService::class)->approve($request, $this->admin);
            $this->fail('A tag was approved into a retired category.');
        } catch (ValidationException $e) {
            $this->assertSame(__('tag_requests.service.the_category_is_retired', ['category' => 'Community']), $e->errors()['category'][0]);
        }

        $this->assertSame('pending', $request->refresh()->status);
        $this->assertDatabaseMissing('topic_tags', ['name' => 'Choir']);
    }

    public function test_a_new_category_shows_with_its_name_in_the_sidebar_of_teachers(): void
    {
        $this->actingAs($this->admin)->post('/categories', ['name' => 'Robotics', 'color' => 'teal'])->assertSessionHasNoErrors();
        SubjectArea::create(['title' => 'Mechatronics', 'code' => 'MECH', 'category' => 'robotics', 'is_active' => true]);

        $teacher = $this->makeUser(UserRole::Teacher);
        $this->actingAs($teacher)->get('/')
            ->assertInertia(fn (Assert $page) => $page
                ->where('subjectAreaTree.groups.0.areas.0.category', 'robotics')
                ->where('subjectAreaTree.groups.0.areas.0.categoryLabel', 'Robotics'));
    }
}

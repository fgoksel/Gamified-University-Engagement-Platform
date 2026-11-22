<?php

namespace Tests\Feature\Events;

use App\Enums\Capability;
use App\Models\Event;
use App\Models\EventApplication;
use App\Models\User;
use App\Support\EventListView;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Task #121 (G18, N8): "My events" can be searched by title and sorted, for the organizer and for
 * the student. The choice is in the address (?q= and ?sort=), so a reload or a shared link keeps it.
 */
class MyEventsSearchSortTest extends EventTestCase
{
    private User $organiser;

    protected function setUp(): void
    {
        parent::setUp();

        // The event every test case starts with would only get in the way of the lists below.
        $this->event->delete();
        $this->organiser = $this->staff([Capability::EventsCreate, Capability::EventsManage]);
    }

    /**
     * An event that is still ahead, so it is under "Upcoming".
     *
     * @param  array<string, mixed>  $data
     */
    private function ahead(string $title, int $days = 1, array $data = []): Event
    {
        return $this->makeEvent([
            'title' => $title,
            'created_by_id' => $this->organiser->id,
            'event_starts_at' => now()->addDays($days),
            'event_ends_at' => now()->addDays($days)->addHours(2),
            ...$data,
        ]);
    }

    /**
     * The props of the page "My events" for this person, as the browser gets them.
     *
     * @return array<string, mixed>
     */
    private function page(User $user, string $query = ''): array
    {
        $props = [];

        $this->actingAs($user)->get('/my-events'.$query)->assertOk()->assertInertia(function (Assert $page) use (&$props) {
            $props = $page->toArray()['props'];
        });

        return $props;
    }

    /** The titles of the organizer's list, in the order shown. */
    private function titles(string $query = '', ?User $user = null): array
    {
        return array_column($this->page($user ?? $this->organiser, $query)['events'], 'title');
    }

    // ---------------------------------------------------------------- organizer: search

    public function test_the_search_finds_events_by_part_of_the_title_whatever_the_case(): void
    {
        $this->ahead('SQL workshop', 1);
        $this->ahead('Indexing lab', 2);
        $this->ahead('Advanced sql night', 3);

        $this->assertSame(['SQL workshop', 'Indexing lab', 'Advanced sql night'], $this->titles());
        $this->assertSame(['SQL workshop', 'Advanced sql night'], $this->titles('?q=SQL'));
        $this->assertSame(['SQL workshop', 'Advanced sql night'], $this->titles('?q=sql'));
        $this->assertSame(['Indexing lab'], $this->titles('?q=DEX'));
        $this->assertSame([], $this->titles('?q=chemistry'));

        // The search is what the page shows back in the box.
        $this->assertSame('sql', $this->page($this->organiser, '?q=sql')['q']);
        $this->assertSame('', $this->page($this->organiser)['q']);
    }

    public function test_the_search_reads_percent_and_underscore_literally(): void
    {
        $this->ahead('100% sure', 1);
        $this->ahead('Plain', 2);
        $this->ahead('snake_case talk', 3);

        $this->assertSame(['100% sure'], $this->titles('?q=%25'));
        $this->assertSame(['snake_case talk'], $this->titles('?q=_'));
    }

    public function test_the_search_is_trimmed_and_cut_and_only_a_text_counts(): void
    {
        $this->ahead('SQL workshop');

        $this->assertSame('sql', $this->page($this->organiser, '?q=%20%20sql%20')['q']);
        $this->assertSame(EventListView::MAX_SEARCH, mb_strlen($this->page($this->organiser, '?q='.str_repeat('a', 150))['q']));
        // Something other than a text in the address is no search at all.
        $this->assertSame(['SQL workshop'], $this->titles('?q[]=nothing'));
    }

    public function test_the_search_stays_inside_the_tab_and_inside_what_the_person_runs(): void
    {
        $this->ahead('SQL for beginners');
        $this->ahead('SQL draft', 2, ['status' => Event::STATUS_DRAFT, 'published_at' => null]);
        $this->ahead('SQL removed', 3, ['status' => Event::STATUS_REMOVED]);

        // An event of an area this person has nothing to do with is never found, whatever the title.
        $elsewhere = $this->topic('Chemistry', $this->faculty);
        $this->ahead('SQL in chemistry', 4, ['owning_topic_id' => $elsewhere->id, 'audience_topic_id' => $elsewhere->id]);

        $this->assertSame(['SQL for beginners'], $this->titles('?q=sql'));
        $this->assertSame(['SQL draft'], $this->titles('?filter=drafts&q=sql'));
        $this->assertSame([], $this->titles('?filter=past&q=sql'));
    }

    public function test_the_tabs_count_what_matches_the_search_and_the_page_still_knows_there_are_events(): void
    {
        $this->ahead('SQL for beginners');
        $this->ahead('SQL draft', 2, ['status' => Event::STATUS_DRAFT, 'published_at' => null]);
        $this->ahead('Python night', 3);
        $this->ahead('Python draft', 4, ['status' => Event::STATUS_DRAFT, 'published_at' => null]);
        $this->ahead('Python seminar', 5, ['status' => Event::STATUS_ON_HOLD]);

        $all = $this->page($this->organiser);
        $this->assertSame(['upcoming' => 2, 'drafts' => 2, 'past' => 0, 'on_hold' => 1, 'cancelled' => 0], $all['counts']);
        $this->assertTrue($all['hasEvents']);

        $sql = $this->page($this->organiser, '?q=sql');
        $this->assertSame(['upcoming' => 1, 'drafts' => 1, 'past' => 0, 'on_hold' => 0, 'cancelled' => 0], $sql['counts']);
        $this->assertTrue($sql['hasEvents']);

        // A search nobody matches empties every tab, and the page still has the box to change it.
        $none = $this->page($this->organiser, '?q=chemistry');
        $this->assertSame(['upcoming' => 0, 'drafts' => 0, 'past' => 0, 'on_hold' => 0, 'cancelled' => 0], $none['counts']);
        $this->assertTrue($none['hasEvents']);

        // Without any event the page says so.
        $this->assertFalse($this->page($this->staff([Capability::EventsCreate], $this->topic('Empty area', $this->faculty)))['hasEvents']);
    }

    // ---------------------------------------------------------------- organizer: sort

    public function test_a_tab_keeps_its_own_order_until_another_one_is_picked(): void
    {
        $this->ahead('Beta', 3);
        $this->ahead('alpha', 1);
        $this->ahead('Gamma', 2);

        // Soonest first while the events are ahead.
        $this->assertSame(['alpha', 'Gamma', 'Beta'], $this->titles());
        $page = $this->page($this->organiser);
        $this->assertSame('date_asc', $page['sort']);
        $this->assertFalse($page['sortChosen']);

        // Most recent first once they are over, so the past tab starts from the latest.
        $this->ahead('Old one', -10, ['status' => Event::STATUS_COMPLETED]);
        $this->ahead('Older one', -20, ['status' => Event::STATUS_COMPLETED]);
        $past = $this->page($this->organiser, '?filter=past');
        $this->assertSame(['Old one', 'Older one'], array_column($past['events'], 'title'));
        $this->assertSame('date_desc', $past['sort']);
        $this->assertFalse($past['sortChosen']);
    }

    public function test_the_four_orders(): void
    {
        $this->ahead('Beta', 3);
        $this->ahead('alpha', 1);
        $this->ahead('Gamma', 2);

        $this->assertSame(['alpha', 'Gamma', 'Beta'], $this->titles('?sort=date_asc'));
        $this->assertSame(['Beta', 'Gamma', 'alpha'], $this->titles('?sort=date_desc'));
        // By title, "alpha" is before "Beta" although a capital sorts first in the alphabet of the machine.
        $this->assertSame(['alpha', 'Beta', 'Gamma'], $this->titles('?sort=title_asc'));
        $this->assertSame(['Gamma', 'Beta', 'alpha'], $this->titles('?sort=title_desc'));

        $chosen = $this->page($this->organiser, '?sort=title_desc');
        $this->assertSame('title_desc', $chosen['sort']);
        $this->assertTrue($chosen['sortChosen']);

        // A chosen order holds on another tab, and with a search.
        $this->ahead('Delta draft', 4, ['status' => Event::STATUS_DRAFT, 'published_at' => null]);
        $this->ahead('Cobra draft', 5, ['status' => Event::STATUS_DRAFT, 'published_at' => null]);
        $this->assertSame(['Delta draft', 'Cobra draft'], $this->titles('?filter=drafts&sort=title_desc'));
        $this->assertSame(['Gamma', 'Beta', 'alpha'], $this->titles('?q=a&sort=title_desc'));
        $this->assertSame(['Beta'], $this->titles('?q=ta&sort=title_desc'));
    }

    public function test_events_with_the_same_title_or_time_keep_a_steady_order(): void
    {
        $first = $this->ahead('Same', 1, ['event_starts_at' => now()->addDay()->startOfHour(), 'event_ends_at' => now()->addDay()->startOfHour()->addHour()]);
        $second = $this->ahead('Same', 1, ['event_starts_at' => now()->addDay()->startOfHour(), 'event_ends_at' => now()->addDay()->startOfHour()->addHour()]);

        foreach (['date_asc', 'date_desc', 'title_asc', 'title_desc'] as $sort) {
            $ids = array_column($this->page($this->organiser, "?sort={$sort}")['events'], 'id');

            $this->assertSame([$first->id, $second->id], $ids, $sort);
        }
    }

    public function test_an_order_that_is_not_offered_is_ignored(): void
    {
        $this->ahead('Beta', 2);
        $this->ahead('alpha', 1);

        foreach (['?sort=applicants', '?sort=', '?sort[]=title_asc', '?sort=TITLE_ASC', '?sort=applied'] as $query) {
            $page = $this->page($this->organiser, $query);

            $this->assertSame(['alpha', 'Beta'], array_column($page['events'], 'title'), $query);
            $this->assertSame('date_asc', $page['sort'], $query);
            $this->assertFalse($page['sortChosen'], $query);
        }
    }

    // ---------------------------------------------------------------- student

    /**
     * @param  array<string, mixed>  $data
     */
    private function applied(User $student, string $title, int $days, int $appliedDaysAgo, string $status = EventApplication::ACCEPTED, array $data = []): EventApplication
    {
        $event = $this->ahead($title, $days, $data);

        return EventApplication::create([
            'event_id' => $event->id,
            'student_id' => $student->id,
            'status' => $status,
            'applied_at' => now()->subDays($appliedDaysAgo),
        ]);
    }

    /** The titles of a student's list, in the order shown. */
    private function mine(User $student, string $query = ''): array
    {
        return array_column(array_column($this->page($student, $query)['applications'], 'event'), 'title');
    }

    public function test_a_student_finds_applications_by_the_title_of_the_event(): void
    {
        $anna = $this->student('Anna');
        $other = $this->student('Other');
        $this->applied($anna, 'SQL workshop', 1, 3);
        $this->applied($anna, 'Indexing lab', 2, 2);
        $this->applied($anna, 'Advanced sql night', 3, 1, EventApplication::WITHDRAWN);
        $this->applied($other, 'SQL for somebody else', 1, 1);

        $this->assertSame(['Advanced sql night', 'Indexing lab', 'SQL workshop'], $this->mine($anna));
        $this->assertSame(['Advanced sql night', 'SQL workshop'], $this->mine($anna, '?q=sql'));
        $this->assertSame(['Indexing lab'], $this->mine($anna, '?q=DEX'));
        $this->assertSame([], $this->mine($anna, '?q=chemistry'));
        $this->assertSame('sql', $this->page($anna, '?q=%20sql')['q']);
    }

    public function test_the_student_list_keeps_the_latest_application_first_and_sorts_on_request(): void
    {
        $anna = $this->student('Anna');
        $this->applied($anna, 'Beta', 3, 1);   // applied yesterday
        $this->applied($anna, 'alpha', 1, 3);  // applied three days ago
        $this->applied($anna, 'Gamma', 2, 2);

        $this->assertSame(['Beta', 'Gamma', 'alpha'], $this->mine($anna));
        $this->assertSame('applied', $this->page($anna)['sort']);

        $this->assertSame(['Beta', 'Gamma', 'alpha'], $this->mine($anna, '?sort=applied'));
        $this->assertSame(['alpha', 'Gamma', 'Beta'], $this->mine($anna, '?sort=date_asc'));
        $this->assertSame(['Beta', 'Gamma', 'alpha'], $this->mine($anna, '?sort=date_desc'));
        $this->assertSame(['alpha', 'Beta', 'Gamma'], $this->mine($anna, '?sort=title_asc'));
        $this->assertSame(['Gamma', 'Beta', 'alpha'], $this->mine($anna, '?sort=title_desc'));
        $this->assertSame('title_asc', $this->page($anna, '?sort=title_asc')['sort']);

        // Search and order work together.
        $this->assertSame(['Gamma', 'Beta', 'alpha'], $this->mine($anna, '?q=a&sort=title_desc'));
        $this->assertSame(['Gamma'], $this->mine($anna, '?q=am&sort=title_desc'));
    }

    public function test_an_order_that_is_not_offered_is_ignored_for_a_student_too(): void
    {
        $anna = $this->student('Anna');
        $this->applied($anna, 'Beta', 3, 1);
        $this->applied($anna, 'alpha', 1, 3);

        foreach (['?sort=applicants', '?sort=', '?sort[]=title_asc', '?sort=DATE_ASC'] as $query) {
            $page = $this->page($anna, $query);

            $this->assertSame(['Beta', 'alpha'], array_column(array_column($page['applications'], 'event'), 'title'), $query);
            $this->assertSame('applied', $page['sort'], $query);
        }
    }

    public function test_a_search_with_no_match_does_not_look_like_having_applied_to_nothing(): void
    {
        $anna = $this->student('Anna');
        $this->applied($anna, 'SQL workshop', 1, 1);

        $miss = $this->page($anna, '?q=chemistry');
        $this->assertSame([], $miss['applications']);
        $this->assertTrue($miss['hasApplications']);

        // A student with nothing to show, or whose only application is for an event that was removed, has applied to nothing.
        $this->assertFalse($this->page($this->student('Ben'))['hasApplications']);

        $cora = $this->student('Cora');
        $this->applied($cora, 'Gone', 1, 1, EventApplication::ACCEPTED, ['status' => Event::STATUS_REMOVED]);
        $this->assertFalse($this->page($cora)['hasApplications']);
    }

    public function test_the_choice_stays_in_the_address_after_leaving_an_event(): void
    {
        $anna = $this->student('Anna');
        $application = $this->applied($anna, 'SQL workshop', 3, 1);

        // Leaving goes back to the page it was asked from, with its search and order.
        $this->actingAs($anna)->from('/my-events?q=sql&sort=title_asc')
            ->post("/events/{$application->event_id}/withdraw")
            ->assertRedirect('/my-events?q=sql&sort=title_asc');
    }
}

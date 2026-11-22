<?php

namespace Tests\Feature\Badges;

use App\Enums\Capability;
use App\Models\Badge;
use App\Models\BadgeAward;
use App\Models\Event;
use App\Models\User;
use App\Services\PointLedger;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Events\EventTestCase;

/**
 * Task #103 (N10): the badge card of the event's edit page. Whoever may edit the event makes,
 * changes and removes the badge; everyone else gets "not found" or "forbidden", like the page itself.
 */
class EventBadgeCardTest extends EventTestCase
{
    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = $this->staff([Capability::EventsCreate, Capability::EventsManage, Capability::PointsCredit], null, 'Mira Manager');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function save(array $data, ?User $as = null, ?Event $event = null): TestResponse
    {
        return $this->actingAs($as ?? $this->manager)->put('/events/'.($event ?? $this->event)->id.'/badge', $data);
    }

    private function take(?User $as = null, ?Event $event = null): TestResponse
    {
        return $this->actingAs($as ?? $this->manager)->delete('/events/'.($event ?? $this->event)->id.'/badge');
    }

    /** A student who got the event's points before there was a badge. */
    private function credited(): User
    {
        $student = $this->student();
        $this->assign($student, $this->studentRole(), $this->course);
        $this->accepted($student);
        app(PointLedger::class)->credit($this->event, $student, $this->manager, PointLedger::PASSPORT);

        return $student;
    }

    public function test_the_manager_makes_the_badge(): void
    {
        $this->save(['name' => 'Database Pro', 'description' => '  Finished the workshop  '])
            ->assertSessionHas('success', 'The badge is saved.')
            ->assertSessionHasNoErrors();

        $badge = Badge::query()->where('event_id', $this->event->id)->sole();

        $this->assertSame('Database Pro', $badge->name);
        $this->assertSame('Finished the workshop', $badge->description);
        $this->assertSame($this->manager->id, $badge->created_by_id);
    }

    public function test_a_new_badge_goes_to_the_students_already_credited_and_the_page_says_how_many(): void
    {
        $this->credited();
        $this->credited();

        $this->save(['name' => 'Database Pro'])
            ->assertSessionHas('success', 'The badge is saved. 2 students who already got the points have it now.');

        $this->assertSame(2, BadgeAward::query()->count());
    }

    public function test_saving_again_changes_the_badge_for_everyone_who_holds_it_and_gives_it_to_no_one_new(): void
    {
        $this->save(['name' => 'Database Pro']);
        $holder = $this->credited();
        $this->save(['name' => 'Database Master', 'description' => 'Top of the class'])
            ->assertSessionHas('success', 'The badge is saved.');

        $badge = Badge::query()->sole();

        $this->assertSame('Database Master', $badge->name);
        $this->assertSame('Top of the class', $badge->description);
        $this->assertSame([$holder->id], BadgeAward::query()->pluck('student_id')->all());
    }

    public function test_an_empty_description_is_stored_as_none(): void
    {
        $this->save(['name' => 'Database Pro', 'description' => 'Something']);
        $this->save(['name' => 'Database Pro', 'description' => ''])->assertSessionHasNoErrors();

        $this->assertNull(Badge::query()->sole()->description);
    }

    public function test_the_name_is_required_and_both_fields_have_a_limit(): void
    {
        $cases = [
            'no name' => [['name' => ''], 'name'],
            'a name that is too long' => [['name' => str_repeat('a', Badge::NAME_MAX + 1)], 'name'],
            'a description that is too long' => [['name' => 'Fine', 'description' => str_repeat('a', Badge::DESCRIPTION_MAX + 1)], 'description'],
        ];

        foreach ($cases as [$data, $field]) {
            $this->save($data)->assertSessionHasErrors($field);
        }

        $this->assertSame(0, Badge::query()->count());

        // Exactly at the limits is fine.
        $this->save(['name' => str_repeat('a', Badge::NAME_MAX), 'description' => str_repeat('b', Badge::DESCRIPTION_MAX)])->assertSessionHasNoErrors();
        $this->assertSame(1, Badge::query()->count());
    }

    public function test_the_name_gets_a_readable_message(): void
    {
        $this->save(['name' => ''])->assertSessionHasErrors(['name' => 'Give the badge a name.']);
    }

    public function test_people_who_do_not_run_the_event_do_not_learn_that_it_exists(): void
    {
        // Not signed in: sent to the login page, before anything else is asked.
        $this->put("/events/{$this->event->id}/badge", ['name' => 'Sneaky'])->assertRedirect('/login');
        $this->delete("/events/{$this->event->id}/badge")->assertRedirect('/login');

        $student = $this->student();
        $this->assign($student, $this->studentRole(), $this->course);
        $elsewhere = $this->staff([Capability::EventsManage, Capability::EventsCreate], $this->topic('Another branch'));

        foreach ([$student, $elsewhere] as $person) {
            $this->save(['name' => 'Sneaky'], $person)->assertNotFound();
            $this->take($person)->assertNotFound();
        }

        $this->assertSame(0, Badge::query()->count());
    }

    public function test_staff_who_may_not_edit_the_event_are_refused(): void
    {
        $badge = Badge::create(['event_id' => $this->event->id, 'name' => 'Database Pro']);
        $credit = $this->staff([Capability::PointsCredit]);

        // A page that is refused sends a signed-in person back to the start page with a message
        // (bootstrap/app.php); a request that expects JSON gets the 403 itself.
        $this->save(['name' => 'Renamed'], $credit)->assertRedirect('/')->assertSessionHas('error');
        $this->take($credit)->assertRedirect('/')->assertSessionHas('error');
        $this->actingAs($credit)->putJson("/events/{$this->event->id}/badge", ['name' => 'Renamed'])->assertForbidden();
        $this->actingAs($credit)->deleteJson("/events/{$this->event->id}/badge")->assertForbidden();

        $this->assertSame('Database Pro', $badge->fresh()->name);
        $this->assertSame(1, Badge::query()->count());
    }

    public function test_an_event_that_can_no_longer_be_changed_takes_no_badge(): void
    {
        $cancelled = Event::factory()->cancelled()->create(['owning_topic_id' => $this->course->id]);

        $this->save(['name' => 'Too late'], event: $cancelled)->assertSessionHasErrors(['badge' => 'This event can no longer be changed, so its badge cannot change either.']);

        $this->assertSame(0, Badge::query()->count());
    }

    public function test_removing_the_badge_takes_it_from_the_holders_and_says_how_many_lost_it(): void
    {
        $this->save(['name' => 'Database Pro']);
        $this->credited();

        $this->take()->assertSessionHas('success', 'The badge is removed. 1 student lost it.');

        $this->assertSame(0, Badge::query()->count());
        $this->assertSame(0, BadgeAward::query()->count());

        $this->take()->assertSessionHas('success', 'The badge is removed.');
    }

    public function test_the_edit_page_tells_the_badge_and_how_many_hold_it(): void
    {
        $this->actingAs($this->manager)->get("/events/{$this->event->id}/edit")
            ->assertInertia(fn (Assert $page) => $page->component('Events/Edit')->where('badge', null));

        $this->save(['name' => 'Database Pro', 'description' => 'Finished the workshop']);
        $this->credited();

        $this->actingAs($this->manager)->get("/events/{$this->event->id}/edit")
            ->assertInertia(fn (Assert $page) => $page
                ->component('Events/Edit')
                ->where('badge.name', 'Database Pro')
                ->where('badge.description', 'Finished the workshop')
                ->where('badge.holders', 1));
    }
}

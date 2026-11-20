<?php

namespace Tests\Feature\Leaderboards;

use App\Enums\Capability;
use App\Enums\UserRole;
use App\Models\Leaderboard;
use App\Models\PointTransaction;
use App\Models\Topic;
use App\Models\TopicAuditEvent;
use App\Models\User;
use App\Services\TopicService;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Events\EventTestCase;

/**
 * Task #132: in an area with no leaderboard, a student still sees their own place through
 * "Where am I?" (#112), unless someone who manages the area turns it off for that area. The
 * professor: "These are different types of motivation and that should be decided by the organizer
 * of the area" (7 November, D1, 18:39), and the own place is "not a leaderboard" (18:52-19:15).
 */
class OwnPlaceTest extends EventTestCase
{
    private User $anna;

    private User $bela;

    protected function setUp(): void
    {
        parent::setUp();

        // University > Faculty > Databases (from EventTestCase); both students in the faculty.
        $this->anna = $this->member('Anna Nagy', 'ANNA01');
        $this->bela = $this->member('Bela Kiss', 'BELA02');
        $this->credit($this->anna, 10);
        $this->credit($this->bela, 30);
    }

    private function member(string $name, string $neptun): User
    {
        $student = $this->student($name, $neptun);
        $this->assign($student, $this->role("Student {$neptun}", [Capability::View, Capability::EventsView]), $this->faculty);

        return $student;
    }

    private function credit(User $student, int $points): PointTransaction
    {
        $event = $this->makeEvent(['points_value' => $points]);

        return PointTransaction::create([
            'student_id' => $student->id,
            'event_id' => $event->id,
            'semester_id' => $event->semester_id,
            'credited_by_id' => $this->admin->id,
            'points' => $points,
            'type' => PointTransaction::CREDIT,
            'source' => 'import',
            'category' => 'academic',
        ]);
    }

    /** Someone who manages the leaderboards of $at and everything below it. */
    private function organiser(Topic $at, string $name = 'Olga Organiser'): User
    {
        return $this->staff([Capability::LeaderboardsManage], $at, $name);
    }

    private function switchTo(User $user, Topic $topic, mixed $shown): TestResponse
    {
        return $this->actingAs($user)->put("/topics/{$topic->id}/own-place", ['shown' => $shown]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function placeIn(User $student, Topic $area): ?array
    {
        return $this->actingAs($student)->get('/leaderboards?area='.$area->id)
            ->assertOk()
            ->inertiaPage()['props']['rankings']['result'];
    }

    public function test_by_default_a_student_sees_their_own_place_in_an_area_without_a_leaderboard(): void
    {
        $this->assertTrue($this->faculty->fresh()->show_own_place);
        $this->assertSame(0, Leaderboard::count());

        $this->assertSame(
            ['hidden' => false, 'total' => 2, 'you' => ['rank' => 2, 'points' => 10, 'to_next' => 20, 'next_rank' => 1]],
            $this->placeIn($this->anna, $this->faculty),
        );
    }

    public function test_with_own_places_off_the_students_of_the_area_see_none(): void
    {
        $this->switchTo($this->organiser($this->faculty), $this->faculty, false)
            ->assertRedirect("/topics/{$this->faculty->id}?tab=leaderboards")
            ->assertSessionHas('success', __('areas.flash.own_place_hidden', ['title' => 'Faculty']));

        // Nothing about the ranking is sent, not even how many students have points there.
        $this->assertSame(['hidden' => true, 'total' => null, 'you' => null], $this->placeIn($this->anna, $this->faculty));
        $this->assertSame(['hidden' => true, 'total' => null, 'you' => null], $this->placeIn($this->bela, $this->faculty));

        $response = $this->actingAs($this->anna)->get('/leaderboards?area='.$this->faculty->id);
        $rankings = json_encode($response->inertiaPage()['props']['rankings']);
        foreach (['Bela', 'BELA02', '"rank"', '"points"'] as $kept) {
            $this->assertStringNotContainsString($kept, $rankings);
        }
        $this->assertStringNotContainsString('BELA02', $response->getContent());
    }

    public function test_turning_it_on_again_shows_the_places_again(): void
    {
        $organiser = $this->organiser($this->faculty);
        $this->switchTo($organiser, $this->faculty, false);

        $this->switchTo($organiser, $this->faculty, true)
            ->assertSessionHas('success', __('areas.flash.own_place_shown', ['title' => 'Faculty']));

        $this->assertSame(2, $this->placeIn($this->anna, $this->faculty)['you']['rank']);
    }

    public function test_it_is_for_that_area_only(): void
    {
        $organiser = $this->organiser($this->university);

        $this->switchTo($organiser, $this->faculty, false);
        $this->assertTrue($this->placeIn($this->anna, $this->faculty)['hidden']);
        $this->assertSame(['rank' => 2, 'points' => 10, 'to_next' => 20, 'next_rank' => 1], $this->placeIn($this->anna, $this->course)['you']);

        $this->switchTo($organiser, $this->faculty, true);
        $this->switchTo($organiser, $this->course, false);
        $this->assertTrue($this->placeIn($this->anna, $this->course)['hidden']);
        $this->assertFalse($this->placeIn($this->anna, $this->faculty)['hidden']);
    }

    public function test_an_area_with_a_leaderboard_always_shows_own_places(): void
    {
        $this->switchTo($this->organiser($this->faculty), $this->faculty, false);
        Leaderboard::create(['topic_id' => $this->faculty->id, 'name' => 'Faculty total', 'visibility' => Leaderboard::AREA, 'created_by_id' => $this->admin->id]);

        // The leaderboard lists the places anyway.
        $this->assertSame(['hidden' => false, 'total' => 2, 'you' => ['rank' => 2, 'points' => 10, 'to_next' => 20, 'next_rank' => 1]], $this->placeIn($this->anna, $this->faculty));

        // A leaderboard of an area below does not count for this one.
        Leaderboard::query()->delete();
        Leaderboard::create(['topic_id' => $this->course->id, 'name' => 'Course', 'visibility' => Leaderboard::AREA, 'created_by_id' => $this->admin->id]);
        $this->assertTrue($this->placeIn($this->anna, $this->faculty)['hidden']);
    }

    public function test_the_organiser_of_the_area_or_above_and_the_system_admin_can_change_it(): void
    {
        $this->switchTo($this->organiser($this->faculty, 'Here'), $this->faculty, false)->assertRedirect();
        $this->assertFalse($this->faculty->fresh()->show_own_place);

        $this->switchTo($this->organiser($this->university, 'Above'), $this->faculty, true)->assertRedirect();
        $this->assertTrue($this->faculty->fresh()->show_own_place);

        $this->switchTo($this->admin, $this->faculty, false)->assertRedirect();
        $this->assertFalse($this->faculty->fresh()->show_own_place);
    }

    public function test_nobody_else_can_change_it(): void
    {
        $viewer = $this->makeUser(UserRole::Teacher, 'Vera Viewer');
        $this->assign($viewer, $this->viewer(), $this->faculty);
        $eventManager = $this->staff([Capability::EventsManage, Capability::PointsCredit], $this->faculty, 'Erik Events');
        $below = $this->organiser($this->course, 'Below');

        // Refused: back home with a message (bootstrap/app.php), and a 403 for JSON requests.
        foreach ([$viewer, $eventManager, $this->anna] as $refused) {
            $this->switchTo($refused, $this->faculty, false)->assertRedirect('/')->assertSessionHas('error', __('errors.no_permission'));
        }
        $this->actingAs($viewer)->putJson("/topics/{$this->faculty->id}/own-place", ['shown' => false])->assertForbidden();
        // An organiser of an area below cannot even open the faculty: it looks like a missing one.
        $this->switchTo($below, $this->faculty, false)->assertNotFound();
        $this->actingAs($this->organiser($this->faculty))->put('/topics/999999/own-place', ['shown' => false])->assertNotFound();

        $this->assertTrue($this->faculty->fresh()->show_own_place);
        $this->assertSame(0, TopicAuditEvent::query()->where('action', 'like', 'topic.own_place%')->count());
    }

    public function test_an_archived_area_cannot_be_changed(): void
    {
        $organiser = $this->organiser($this->university);
        app(TopicService::class)->archive($this->admin, $this->faculty->fresh());

        $this->switchTo($organiser, $this->faculty, false)->assertNotFound();
        $this->assertTrue($this->faculty->fresh()->show_own_place);
    }

    public function test_the_value_must_be_on_or_off(): void
    {
        $organiser = $this->organiser($this->faculty);

        $this->actingAs($organiser)->put("/topics/{$this->faculty->id}/own-place", [])->assertSessionHasErrors('shown');
        $this->switchTo($organiser, $this->faculty, 'maybe')->assertSessionHasErrors('shown');

        $this->assertTrue($this->faculty->fresh()->show_own_place);
    }

    public function test_each_change_is_in_the_audit_trail_and_setting_it_twice_changes_nothing(): void
    {
        $organiser = $this->organiser($this->faculty);

        $this->switchTo($organiser, $this->faculty, false);
        $this->switchTo($organiser, $this->faculty, false);
        $this->switchTo($organiser, $this->faculty, true);

        $audit = TopicAuditEvent::query()->where('action', 'like', 'topic.own_place%')->orderBy('id')->get();
        $this->assertSame(['topic.own_place_hidden', 'topic.own_place_shown'], $audit->pluck('action')->all());
        $this->assertSame([$organiser->id, $organiser->id], $audit->pluck('actor_id')->all());
        $this->assertSame([$this->faculty->id, $this->faculty->id], $audit->pluck('topic_id')->all());
        $this->assertSame(['show_own_place' => true], $audit[0]->before);
        $this->assertSame(['show_own_place' => false], $audit[0]->after);
    }

    public function test_the_leaderboards_tab_says_whether_own_places_show(): void
    {
        $organiser = $this->organiser($this->faculty);

        $this->actingAs($organiser)->get("/topics/{$this->faculty->id}?tab=leaderboards")
            ->assertInertia(fn (Assert $page) => $page->where('selected.show_own_place', true)->where('selected.can.manage_leaderboards', true));

        $this->switchTo($organiser, $this->faculty, false);

        $this->actingAs($organiser)->get("/topics/{$this->faculty->id}?tab=leaderboards")
            ->assertInertia(fn (Assert $page) => $page->where('selected.show_own_place', false));
        // Only the Leaderboards tab needs it.
        $this->actingAs($organiser)->get("/topics/{$this->faculty->id}")
            ->assertInertia(fn (Assert $page) => $page->where('selected.show_own_place', null));
    }

    public function test_a_new_area_shows_own_places(): void
    {
        $area = $this->topic('New course', $this->faculty);

        $this->assertTrue($area->show_own_place);
        $this->assertTrue($area->fresh()->show_own_place);
    }
}

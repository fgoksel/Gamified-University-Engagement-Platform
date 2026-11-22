<?php

namespace Tests\Feature\Badges;

use App\Enums\Capability;
use App\Enums\UserRole;
use App\Models\Badge;
use App\Models\BadgeAward;
use App\Models\Event;
use App\Models\User;
use App\Services\BadgeAwards;
use App\Services\PointLedger;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Events\EventTestCase;

/**
 * Task #103 (N10): the student sees their badges on Home and in the profile. Only their own: a
 * badge is shown to the person who holds it.
 */
class BadgeDisplayTest extends EventTestCase
{
    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->student = $this->student('Anna Nagy');
        $this->assign($this->student, $this->studentRole(), $this->course);
    }

    /** A badge of an event, held by the student since some days ago. */
    private function held(User $student, string $name, int $daysAgo = 0, ?string $description = null): Badge
    {
        $event = $this->makeEvent(['title' => "Event of {$name}"]);
        $badge = Badge::create(['event_id' => $event->id, 'name' => $name, 'description' => $description]);
        BadgeAward::create(['badge_id' => $badge->id, 'student_id' => $student->id, 'awarded_at' => now()->subDays($daysAgo)]);

        return $badge;
    }

    private function home(User $as): TestResponse
    {
        return $this->actingAs($as)->get('/');
    }

    private function profile(User $as): TestResponse
    {
        return $this->actingAs($as)->get('/profile');
    }

    public function test_home_lists_the_badges_the_student_holds_newest_first(): void
    {
        $this->held($this->student, 'First steps', daysAgo: 3);
        $this->held($this->student, 'Database Pro', daysAgo: 1, description: 'Finished the workshop');

        $this->home($this->student)->assertInertia(fn (Assert $page) => $page
            ->component('Home')
            ->where('badges.total', 2)
            ->has('badges.latest', 2)
            ->where('badges.latest.0.name', 'Database Pro')
            ->where('badges.latest.0.description', 'Finished the workshop')
            ->where('badges.latest.0.event', 'Event of Database Pro')
            ->where('badges.latest.0.at', now()->subDay()->translatedFormat('D j M Y'))
            ->where('badges.latest.1.name', 'First steps')
            ->where('badges.latest.1.description', null));
    }

    public function test_home_shows_only_the_latest_few_but_counts_them_all(): void
    {
        foreach (range(1, BadgeAwards::HOME_LIMIT + 2) as $number) {
            $this->held($this->student, "Badge {$number}", daysAgo: BadgeAwards::HOME_LIMIT + 3 - $number);
        }

        $this->home($this->student)->assertInertia(fn (Assert $page) => $page
            ->where('badges.total', BadgeAwards::HOME_LIMIT + 2)
            ->has('badges.latest', BadgeAwards::HOME_LIMIT)
            ->where('badges.latest.0.name', 'Badge '.(BadgeAwards::HOME_LIMIT + 2)));
    }

    public function test_a_student_without_badges_has_an_empty_list(): void
    {
        $this->home($this->student)->assertInertia(fn (Assert $page) => $page
            ->where('badges.total', 0)
            ->where('badges.latest', []));
    }

    public function test_the_profile_lists_every_badge_the_student_holds(): void
    {
        foreach (range(1, BadgeAwards::HOME_LIMIT + 2) as $number) {
            $this->held($this->student, "Badge {$number}", daysAgo: BadgeAwards::HOME_LIMIT + 3 - $number);
        }

        $this->profile($this->student)->assertInertia(fn (Assert $page) => $page
            ->component('Profile/Edit')
            ->has('badges', BadgeAwards::HOME_LIMIT + 2)
            ->where('badges.0.name', 'Badge '.(BadgeAwards::HOME_LIMIT + 2))
            ->where('badges.'.(BadgeAwards::HOME_LIMIT + 1).'.name', 'Badge 1'));
    }

    public function test_a_student_without_badges_still_has_the_section_with_nothing_in_it(): void
    {
        $this->profile($this->student)->assertInertia(fn (Assert $page) => $page->where('badges', []));
    }

    public function test_only_students_have_a_badge_section_in_the_profile(): void
    {
        $teacher = $this->makeUser(UserRole::Teacher, 'Tamas Teacher');

        $this->profile($teacher)->assertInertia(fn (Assert $page) => $page->component('Profile/Edit')->where('badges', null));
    }

    public function test_a_student_never_sees_the_badges_of_another(): void
    {
        $other = $this->student('Bela Kiss');
        $this->held($other, 'Secret badge');

        $this->home($this->student)->assertInertia(fn (Assert $page) => $page->where('badges.total', 0)->where('badges.latest', []));
        $this->profile($this->student)->assertInertia(fn (Assert $page) => $page->where('badges', []));
        $this->assertStringNotContainsString('Secret badge', (string) $this->home($this->student)->getContent());
    }

    public function test_the_badge_of_a_surprise_event_shows_once_the_student_got_its_points(): void
    {
        $organiser = $this->staff([Capability::PointsCredit], null, 'Olga Organiser');
        $surprise = $this->makeEvent(['title' => 'Surprise quiz', 'is_hidden' => true, 'qr_enabled' => false]);
        Badge::create(['event_id' => $surprise->id, 'name' => 'Quick thinker']);

        // Before the points, the student knows nothing of it.
        $this->home($this->student)->assertInertia(fn (Assert $page) => $page->where('badges.total', 0));

        app(PointLedger::class)->creditImported($surprise, $this->student, $organiser);

        $this->home($this->student)->assertInertia(fn (Assert $page) => $page
            ->where('badges.total', 1)
            ->where('badges.latest.0.name', 'Quick thinker')
            ->where('badges.latest.0.event', 'Surprise quiz'));
    }

    public function test_the_badges_are_not_part_of_the_points_on_home(): void
    {
        $this->held($this->student, 'Database Pro');

        $this->home($this->student)->assertInertia(fn (Assert $page) => $page
            ->where('points.total', 0)
            ->where('badges.total', 1));
    }
}

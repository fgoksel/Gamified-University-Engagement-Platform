<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Notifications\EventApplicationDecisionNotification;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Task #53: in-app notifications about the person's applications.
 */
class NotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function student(): User
    {
        $student = User::factory()->create(['status' => 'active', 'must_change_password' => false]);
        $student->assignRole(UserRole::Student);

        return $student;
    }

    private int $minute = 0;

    private function notify(User $user, string $decision, string $title = 'SQL Workshop'): string
    {
        $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => EventApplicationDecisionNotification::class,
            'data' => ['decision' => $decision, 'event_id' => 1, 'event_title' => $title],
            // Each one a minute after the last, so "newest first" is unambiguous.
            'created_at' => now()->subHour()->addMinutes(++$this->minute),
        ]);

        return $user->notifications()->latest()->first()->id;
    }

    public function test_the_page_lists_only_my_notifications_with_readable_text(): void
    {
        $me = $this->student();
        $other = $this->student();
        $this->notify($me, EventApplicationDecisionNotification::ACCEPTED, 'SQL Workshop');
        $this->notify($me, EventApplicationDecisionNotification::REJECTED, 'Chess Night');
        $this->notify($me, EventApplicationDecisionNotification::REMOVED, 'Hack Day');
        $this->notify($other, EventApplicationDecisionNotification::ACCEPTED, 'Secret Event');

        $this->actingAs($me)->get('/notifications')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Notifications/Index')
                ->has('notifications.data', 3)
                ->where('notifications.data.0.title', 'You were removed from the selection for Hack Day')
                ->where('notifications.data.1.title', 'Your application was not accepted')
                ->where('notifications.data.2.title', 'You were selected for SQL Workshop')
                ->where('notifications.data.2.read', false)
                ->where('notifications.data.2.link', '/my-events'));
    }

    public function test_the_menu_badge_counts_unread_ones(): void
    {
        $me = $this->student();
        $this->notify($me, EventApplicationDecisionNotification::ACCEPTED);
        $this->notify($me, EventApplicationDecisionNotification::REJECTED);

        $this->actingAs($me)->get('/my-events')
            ->assertInertia(fn (Assert $page) => $page->where('notifications.unread', 2));

        $me->notifications()->first()->markAsRead();

        $this->actingAs($me)->get('/my-events')
            ->assertInertia(fn (Assert $page) => $page->where('notifications.unread', 1));
    }

    public function test_reading_one_marks_only_that_one(): void
    {
        $me = $this->student();
        $first = $this->notify($me, EventApplicationDecisionNotification::ACCEPTED);
        $this->notify($me, EventApplicationDecisionNotification::REJECTED);

        $this->actingAs($me)->from('/notifications')->post("/notifications/{$first}/read")
            ->assertRedirect('/notifications');

        $this->assertNotNull($me->notifications()->whereKey($first)->first()->read_at);
        $this->assertSame(1, $me->unreadNotifications()->count());
    }

    public function test_nobody_can_read_someone_elses_notification(): void
    {
        $me = $this->student();
        $other = $this->student();
        $theirs = $this->notify($other, EventApplicationDecisionNotification::ACCEPTED);

        $this->actingAs($me)->post("/notifications/{$theirs}/read")->assertNotFound();
        $this->assertSame(1, $other->unreadNotifications()->count());
    }

    public function test_mark_all_as_read_touches_only_my_notifications(): void
    {
        $me = $this->student();
        $other = $this->student();
        $this->notify($me, EventApplicationDecisionNotification::ACCEPTED);
        $this->notify($me, EventApplicationDecisionNotification::REJECTED);
        $this->notify($other, EventApplicationDecisionNotification::ACCEPTED);

        $this->actingAs($me)->post('/notifications/read-all')->assertRedirect();

        $this->assertSame(0, $me->unreadNotifications()->count());
        $this->assertSame(1, $other->unreadNotifications()->count());
    }

    public function test_guests_and_admins_cannot_use_the_page(): void
    {
        $this->get('/notifications')->assertRedirect('/login');
        $this->post('/notifications/read-all')->assertRedirect('/login');

        $admin = User::factory()->create(['status' => 'active', 'must_change_password' => false]);
        $admin->assignRole(UserRole::Admin);
        $this->actingAs($admin)->get('/notifications')->assertRedirect();
    }

    public function test_an_empty_page_is_fine(): void
    {
        $this->actingAs($this->student())->get('/notifications')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('notifications.data', 0));
    }
}

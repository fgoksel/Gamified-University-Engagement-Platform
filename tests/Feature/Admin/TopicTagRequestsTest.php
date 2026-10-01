<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Filament\Pages\TopicTagRequests;
use App\Models\TopicTag;
use App\Models\TopicTagRequest;
use App\Models\User;
use App\Notifications\TopicTagRequestApprovedNotification;
use App\Notifications\TopicTagRequestRejectedNotification;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Tests for Topic Tag Requests Queue — UC-1.9, UC-3.4, and Technical Specification Table 22.
 */
class TopicTagRequestsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->admin = User::factory()->create([
            'name' => 'System Admin',
            'status' => 'active',
            'must_change_password' => false,
        ])->assignRole(UserRole::Admin);

        $this->teacher = User::factory()->create([
            'name' => 'Dr. Kovács István',
            'email' => 'kovacs.istvan@pte.hu',
            'status' => 'active',
            'must_change_password' => false,
        ])->assignRole(UserRole::Teacher);
    }

    private function createPendingRequest(string $name = 'Robotics Workshop', string $category = 'scientific'): TopicTagRequest
    {
        return TopicTagRequest::create([
            'proposed_name' => $name,
            'category' => $category,
            'justification' => 'Hands-on competition and lab preparation.',
            'status' => 'pending',
            'requested_by_id' => $this->teacher->id,
        ]);
    }

    public function test_admin_can_view_topic_tag_requests_page_and_table(): void
    {
        $tagRequest = $this->createPendingRequest();

        Livewire::actingAs($this->admin)
            ->test(TopicTagRequests::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$tagRequest])
            ->assertSee('Robotics Workshop')
            ->assertSee('Dr. Kovács István')
            ->assertSee('Scientific');
    }

    public function test_navigation_badge_shows_pending_requests_count(): void
    {
        $this->createPendingRequest('Tag One');
        $this->createPendingRequest('Tag Two');

        $this->assertSame('2', TopicTagRequests::getNavigationBadge());
    }

    public function test_admin_can_approve_pending_topic_tag_request_per_uc_3_4(): void
    {
        Notification::fake();

        $tagRequest = $this->createPendingRequest('Machine Learning Seminar', 'academic');

        Livewire::actingAs($this->admin)
            ->test(TopicTagRequests::class)
            ->callTableAction('approve', $tagRequest)
            ->assertHasNoTableActionErrors();

        // TopicTag created in database
        $this->assertDatabaseHas('topic_tags', [
            'name' => 'Machine Learning Seminar',
            'category' => 'academic',
            'description' => 'Hands-on competition and lab preparation.',
            'is_active' => 1,
        ]);

        $createdTag = TopicTag::where('name', 'Machine Learning Seminar')->firstOrFail();

        // Request updated
        $fresh = $tagRequest->fresh();
        $this->assertSame('approved', $fresh->status);
        $this->assertSame($this->admin->id, $fresh->resolved_by_id);
        $this->assertSame($createdTag->id, $fresh->resolved_topic_tag_id);

        // Teacher notified
        Notification::assertSentTo(
            $this->teacher,
            TopicTagRequestApprovedNotification::class,
            fn (TopicTagRequestApprovedNotification $notification) => $notification->proposedName === 'Machine Learning Seminar'
        );
    }

    public function test_approving_request_with_already_existing_tag_name_fails_with_spec_message(): void
    {
        Notification::fake();

        TopicTag::create([
            'name' => 'Duplicate Tag Name',
            'category' => 'sports',
            'is_active' => true,
        ]);

        $tagRequest = $this->createPendingRequest('Duplicate Tag Name', 'sports');

        Livewire::actingAs($this->admin)
            ->test(TopicTagRequests::class)
            ->callTableAction('approve', $tagRequest);

        $this->assertSame('pending', $tagRequest->fresh()->status);
        Notification::assertNothingSent();
    }

    public function test_admin_can_reject_pending_topic_tag_request_with_reason_per_uc_3_4(): void
    {
        Notification::fake();

        $tagRequest = $this->createPendingRequest('LAN Party Overload', 'community');

        Livewire::actingAs($this->admin)
            ->test(TopicTagRequests::class)
            ->callTableAction('reject', $tagRequest, [
                'reason' => 'Similar tag "Volunteer Work" should be used instead.',
            ])
            ->assertHasNoTableActionErrors();

        $fresh = $tagRequest->fresh();
        $this->assertSame('rejected', $fresh->status);
        $this->assertSame($this->admin->id, $fresh->resolved_by_id);
        $this->assertSame('Similar tag "Volunteer Work" should be used instead.', $fresh->rejection_reason);

        // No new TopicTag created
        $this->assertDatabaseMissing('topic_tags', ['name' => 'LAN Party Overload']);

        // Teacher notified with rejection reason
        Notification::assertSentTo(
            $this->teacher,
            TopicTagRequestRejectedNotification::class,
            fn (TopicTagRequestRejectedNotification $notification) => $notification->proposedName === 'LAN Party Overload'
                && $notification->reason === 'Similar tag "Volunteer Work" should be used instead.'
        );
    }

    public function test_approve_and_reject_actions_are_hidden_for_already_resolved_requests(): void
    {
        $approvedRequest = TopicTagRequest::create([
            'proposed_name' => 'Already Approved',
            'category' => 'scientific',
            'justification' => 'Justification',
            'status' => 'approved',
            'requested_by_id' => $this->teacher->id,
            'resolved_by_id' => $this->admin->id,
        ]);

        Livewire::actingAs($this->admin)
            ->test(TopicTagRequests::class)
            ->assertTableActionHidden('approve', $approvedRequest)
            ->assertTableActionHidden('reject', $approvedRequest);
    }

    // ─── Technical Specification Table 22 API Endpoints ────────────────────────

    public function test_api_admin_can_approve_topic_tag_request_via_post_route(): void
    {
        Notification::fake();

        $tagRequest = $this->createPendingRequest('AI Ethics Summit', 'scientific');

        $response = $this->actingAs($this->admin)
            ->post("/admin/topic-tag-requests/{$tagRequest->id}/approve");

        $response->assertSessionHasNoErrors();
        $this->assertSame('approved', $tagRequest->fresh()->status);
        $this->assertDatabaseHas('topic_tags', ['name' => 'AI Ethics Summit']);

        Notification::assertSentTo($this->teacher, TopicTagRequestApprovedNotification::class);
    }

    public function test_api_admin_can_reject_topic_tag_request_via_post_route(): void
    {
        Notification::fake();

        $tagRequest = $this->createPendingRequest('Gaming Tournament', 'sports');

        $response = $this->actingAs($this->admin)
            ->post("/admin/topic-tag-requests/{$tagRequest->id}/reject", [
                'reason' => 'Please use the University Championship category.',
            ]);

        $response->assertSessionHasNoErrors();
        $this->assertSame('rejected', $tagRequest->fresh()->status);
        $this->assertSame('Please use the University Championship category.', $tagRequest->fresh()->rejection_reason);

        Notification::assertSentTo($this->teacher, TopicTagRequestRejectedNotification::class);
    }

    public function test_api_teacher_cannot_approve_or_reject_requests(): void
    {
        $tagRequest = $this->createPendingRequest('Test Tag', 'academic');

        $this->actingAs($this->teacher)
            ->post("/admin/topic-tag-requests/{$tagRequest->id}/approve")
            ->assertRedirect('/');

        $this->actingAs($this->teacher)
            ->post("/admin/topic-tag-requests/{$tagRequest->id}/reject")
            ->assertRedirect('/');

        $this->actingAs($this->teacher)
            ->postJson("/admin/topic-tag-requests/{$tagRequest->id}/approve")
            ->assertForbidden();

        $this->actingAs($this->teacher)
            ->postJson("/admin/topic-tag-requests/{$tagRequest->id}/reject")
            ->assertForbidden();
    }

    public function test_student_cannot_access_topic_tag_requests_management(): void
    {
        $student = User::factory()->create([
            'status' => 'active',
            'must_change_password' => false,
        ])->assignRole(UserRole::Student);

        $this->actingAs($student)
            ->get('/admin/topic-tag-requests')
            ->assertRedirect('/');
    }
}

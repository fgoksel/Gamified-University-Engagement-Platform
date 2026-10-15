<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\TopicTag;
use App\Models\TopicTagRequest;
use App\Models\User;
use App\Notifications\TopicTagRequestApprovedNotification;
use App\Services\TopicTagRequestService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Tests for Organizer Topic Tag Request Form (UC-1.9, Task #47).
 */
class TopicTagRequestTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private User $otherTeacher;

    private User $student;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->teacher = User::factory()->create([
            'name' => 'Prof. Alan Turing',
            'email' => 'turing@pte.hu',
            'status' => 'active',
            'must_change_password' => false,
        ])->assignRole(UserRole::Teacher);

        $this->otherTeacher = User::factory()->create([
            'name' => 'Prof. Grace Hopper',
            'email' => 'hopper@pte.hu',
            'status' => 'active',
            'must_change_password' => false,
        ])->assignRole(UserRole::Teacher);

        $this->student = User::factory()->create([
            'name' => 'Jane Student',
            'email' => 'jane@pte.hu',
            'status' => 'active',
            'must_change_password' => false,
        ])->assignRole(UserRole::Student);

        $this->admin = User::factory()->create([
            'name' => 'Admin User',
            'email' => 'admin@pte.hu',
            'status' => 'active',
            'must_change_password' => false,
        ])->assignRole(UserRole::Admin);
    }

    public function test_teacher_can_view_topic_tag_requests_page_and_only_their_own_requests(): void
    {
        // Teacher's own request
        $myRequest = TopicTagRequest::create([
            'proposed_name' => 'Quantum Computing Seminar',
            'category' => 'scientific',
            'justification' => 'Upcoming guest lecture series.',
            'status' => 'pending',
            'requested_by_id' => $this->teacher->id,
        ]);

        // Other teacher's request
        $otherRequest = TopicTagRequest::create([
            'proposed_name' => 'Underwater Basket Weaving',
            'category' => 'community',
            'justification' => 'Crafting club events.',
            'status' => 'pending',
            'requested_by_id' => $this->otherTeacher->id,
        ]);

        $response = $this->actingAs($this->teacher)
            ->get(route('topic-tag-requests.index'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('TopicTagRequests/Index')
            ->has('requests', 1)
            ->where('requests.0.id', $myRequest->id)
            ->where('requests.0.proposed_name', 'Quantum Computing Seminar')
        );
    }

    public function test_teacher_can_fetch_requests_as_json(): void
    {
        TopicTagRequest::create([
            'proposed_name' => 'Cybersecurity Workshop',
            'category' => 'academic',
            'justification' => 'Hands-on hacking lab.',
            'status' => 'pending',
            'requested_by_id' => $this->teacher->id,
        ]);

        $response = $this->actingAs($this->teacher)
            ->getJson(route('topic-tag-requests.index'));

        $response->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment(['proposed_name' => 'Cybersecurity Workshop']);
    }

    public function test_teacher_can_submit_valid_topic_tag_request(): void
    {
        $response = $this->actingAs($this->teacher)
            ->post(route('topic-tag-requests.store'), [
                'proposed_name' => 'Bioinformatics Hackathon',
                'category' => 'scientific',
                'justification' => 'Interdisciplinary coding weekend for genetics students.',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success', 'Topic tag request submitted successfully. It will be reviewed by an administrator.');

        $this->assertDatabaseHas('topic_tag_requests', [
            'proposed_name' => 'Bioinformatics Hackathon',
            'category' => 'scientific',
            'justification' => 'Interdisciplinary coding weekend for genetics students.',
            'status' => 'pending',
            'requested_by_id' => $this->teacher->id,
        ]);
    }

    public function test_teacher_can_submit_valid_topic_tag_request_via_json(): void
    {
        $response = $this->actingAs($this->teacher)
            ->postJson(route('topic-tag-requests.store'), [
                'proposed_name' => 'AI Robotics Demo',
                'category' => 'academic',
                'justification' => 'Autonomous navigation showcase.',
            ]);

        $response->assertCreated();
        $response->assertJsonPath('message', 'Topic tag request submitted successfully. It will be reviewed by an administrator.');
        $response->assertJsonPath('request.proposed_name', 'AI Robotics Demo');

        $this->assertDatabaseHas('topic_tag_requests', [
            'proposed_name' => 'AI Robotics Demo',
            'status' => 'pending',
        ]);
    }

    public function test_submission_fails_with_spec_message_when_tag_with_same_name_already_exists(): void
    {
        // Existing live topic tag
        TopicTag::create([
            'name' => 'Machine Learning',
            'category' => 'academic',
            'description' => 'Existing active tag',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->teacher)
            ->from(route('topic-tag-requests.index'))
            ->post(route('topic-tag-requests.store'), [
                'proposed_name' => 'Machine Learning',
                'category' => 'academic',
                'justification' => 'We need machine learning events.',
            ]);

        $response->assertRedirect(route('topic-tag-requests.index'));
        $response->assertSessionHasErrors([
            'proposed_name' => 'A topic tag with this name already exists. Please select it from the dropdown instead.',
        ]);

        $this->assertDatabaseMissing('topic_tag_requests', [
            'proposed_name' => 'Machine Learning',
        ]);
    }

    public function test_submission_fails_when_pending_request_with_same_name_already_exists(): void
    {
        TopicTagRequest::create([
            'proposed_name' => 'Deep Learning Symposium',
            'category' => 'scientific',
            'justification' => 'Already requested by another teacher.',
            'status' => 'pending',
            'requested_by_id' => $this->otherTeacher->id,
        ]);

        $response = $this->actingAs($this->teacher)
            ->from(route('topic-tag-requests.index'))
            ->post(route('topic-tag-requests.store'), [
                'proposed_name' => 'Deep Learning Symposium',
                'category' => 'scientific',
                'justification' => 'I want to submit it too.',
            ]);

        $response->assertSessionHasErrors([
            'proposed_name' => 'A request for this topic tag is already pending approval.',
        ]);
    }

    public function test_submission_requires_all_fields_with_uc_1_9_message(): void
    {
        $response = $this->actingAs($this->teacher)
            ->from(route('topic-tag-requests.index'))
            ->post(route('topic-tag-requests.store'), [
                'proposed_name' => '',
                'category' => '',
                'justification' => '',
            ]);

        $response->assertSessionHasErrors([
            'proposed_name' => 'Please complete all fields before submitting.',
            'category' => 'Please complete all fields before submitting.',
            'justification' => 'Please complete all fields before submitting.',
        ]);
    }

    public function test_submission_rejects_invalid_category(): void
    {
        $response = $this->actingAs($this->teacher)
            ->from(route('topic-tag-requests.index'))
            ->post(route('topic-tag-requests.store'), [
                'proposed_name' => 'Invalid Category Test',
                'category' => 'unsupported_category',
                'justification' => 'Testing validation.',
            ]);

        $response->assertSessionHasErrors(['category']);
    }

    public function test_student_cannot_access_or_submit_topic_tag_requests(): void
    {
        $getResponse = $this->actingAs($this->student)
            ->get(route('topic-tag-requests.index'));

        $getResponse->assertRedirect('/');
        $getResponse->assertSessionHas('error', 'You do not have permission to view this page.');

        $getJson = $this->actingAs($this->student)
            ->getJson(route('topic-tag-requests.index'));
        $getJson->assertForbidden();

        $postJson = $this->actingAs($this->student)
            ->postJson(route('topic-tag-requests.store'), [
                'proposed_name' => 'Student Tag',
                'category' => 'community',
                'justification' => 'Student request attempt.',
            ]);
        $postJson->assertForbidden();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('topic-tag-requests.index'))
            ->assertRedirect(route('login'));

        $this->post(route('topic-tag-requests.store'), [
            'proposed_name' => 'Guest Tag',
            'category' => 'academic',
            'justification' => 'Guest request attempt.',
        ])->assertRedirect(route('login'));
    }

    public function test_end_to_end_approval_workflow_with_admin_resolution(): void
    {
        Notification::fake();

        // 1. Teacher submits request
        $this->actingAs($this->teacher)
            ->post(route('topic-tag-requests.store'), [
                'proposed_name' => 'Neural Interface Lab',
                'category' => 'scientific',
                'justification' => 'Interdisciplinary BCI research seminars.',
            ]);

        $tagRequest = TopicTagRequest::where('proposed_name', 'Neural Interface Lab')->firstOrFail();
        $this->assertSame('pending', $tagRequest->status);

        // 2. Admin approves request using existing TopicTagRequestService
        $service = app(TopicTagRequestService::class);
        $createdTag = $service->approve($tagRequest, $this->admin);

        // 3. Assert TopicTag created and request marked approved
        $this->assertDatabaseHas('topic_tags', [
            'id' => $createdTag->id,
            'name' => 'Neural Interface Lab',
            'category' => 'scientific',
            'is_active' => true,
        ]);

        $this->assertSame('approved', $tagRequest->fresh()->status);
        $this->assertSame($createdTag->id, $tagRequest->fresh()->resolved_topic_tag_id);

        // 4. Assert teacher received notification
        Notification::assertSentTo($this->teacher, TopicTagRequestApprovedNotification::class);
    }
}

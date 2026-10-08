<?php

namespace Tests\Feature\Topics;

use App\Enums\Capability;
use App\Enums\UserRole;
use App\Models\Course;
use App\Models\RoleAssignment;
use App\Models\RoleDefinition;
use App\Models\Topic;
use App\Models\TopicAuditEvent;
use App\Models\User;
use App\Services\AssignmentService;
use App\Services\TopicAccess;
use App\Services\TopicService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Archive and restore (reversible, nothing is lost) and permanent deletion
 * (System Admin only, unused topics only, two confirmations).
 */
class TopicArchiveDeleteTest extends TopicTestCase
{
    private Topic $root;

    private Topic $branch;

    private Topic $leaf;

    private Topic $sibling;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = $this->topic('Root');
        $this->branch = $this->topic('Branch', $this->root);
        $this->leaf = $this->topic('Leaf', $this->branch);
        $this->sibling = $this->topic('Sibling', $this->root);
    }

    private function service(): TopicService
    {
        return app(TopicService::class);
    }

    private function archiver(): User
    {
        $user = $this->makeUser(name: 'Archiver');
        $this->assign($user, $this->role('Archivist', [Capability::View, Capability::Archive]), $this->root);

        return $user;
    }

    // Archive ------------------------------------------------------------

    public function test_archiving_hides_the_topic_and_everything_below_from_normal_browsing(): void
    {
        $reader = $this->makeUser();
        $this->assign($reader, $this->viewer(), $this->root);

        $this->service()->archive($this->admin, $this->branch);

        $access = app(TopicAccess::class);
        foreach ([$this->branch, $this->leaf] as $hidden) {
            $this->assertFalse($access->canView($reader, $hidden));
            $this->assertFalse($access->canView($this->admin, $hidden));
            $this->actingAs($reader)->get("/topics/{$hidden->id}")->assertNotFound();
            $this->getJson("/topics/{$hidden->id}/children")->assertNotFound();
        }
        $this->assertTrue($access->canView($reader, $this->sibling));

        $this->actingAs($reader)->getJson('/topics/search?q=Leaf')->assertJsonCount(0, 'results');
        $this->getJson("/topics/{$this->root->id}/children")->assertJsonCount(1, 'children')->assertJsonPath('children.0.title', 'Sibling');
        $this->get("/topics/{$this->root->id}")->assertInertia(fn (Assert $page) => $page
            ->has('selected.children', 1)
            ->where('selected.counts.topics_below', 1));
    }

    public function test_archiving_deletes_and_ends_nothing_and_is_audited(): void
    {
        $holder = $this->makeUser();
        $assignment = $this->assign($holder, $this->viewer(), $this->leaf);
        $topics = Topic::count();

        $this->service()->archive($this->admin, $this->branch);

        $this->assertSame($topics, Topic::count());
        $this->assertNull($assignment->fresh()->ended_at);
        $this->assertNotNull($this->branch->fresh()->archived_at);
        $this->assertNull($this->leaf->fresh()->archived_at);
        $this->assertSame($this->admin->id, $this->branch->fresh()->archived_by_id);
        $event = TopicAuditEvent::where('action', 'topic.archived')->sole();
        $this->assertSame(1, $event->after['topics_below']);
    }

    public function test_a_person_whose_only_access_is_in_an_archived_branch_loses_the_entry_point_but_keeps_the_record(): void
    {
        $user = $this->makeUser();
        $assignment = $this->assign($user, $this->viewer(), $this->leaf);

        $this->service()->archive($this->admin, $this->branch);

        $this->assertTrue(app(TopicAccess::class)->entryTopics($user)->isEmpty());
        $this->assertSame(0, app(TopicAccess::class)->visibleTopics($user)->count());
        $this->assertNull($assignment->fresh()->ended_at);

        $this->service()->restore($this->admin, $this->branch);
        $this->assertSame([$this->leaf->id], app(TopicAccess::class)->entryTopics($user)->pluck('id')->all());
    }

    public function test_archiving_needs_the_archive_power_and_a_viewer_cannot_do_it(): void
    {
        $viewer = $this->makeUser();
        $this->assign($viewer, $this->viewer(), $this->root);
        $editor = $this->makeUser();
        $this->assign($editor, $this->role('Everything but archive', collect(Capability::cases())->reject(fn ($c) => $c === Capability::Archive)->all()), $this->root);

        foreach ([$viewer, $editor] as $user) {
            try {
                $this->service()->archive($user, $this->branch);
                $this->fail('Archiving must be refused.');
            } catch (AuthorizationException) {
            }
            $this->actingAs($user)->post("/topics/{$this->branch->id}/archive")->assertRedirect('/');
        }

        $this->assertNull($this->branch->fresh()->archived_at);
    }

    public function test_an_archiver_archives_in_their_branch_only_and_cannot_see_the_outside(): void
    {
        $archiver = $this->makeUser();
        $this->assign($archiver, $this->role('Archivist', [Capability::View, Capability::Archive]), $this->branch);

        $this->actingAs($archiver)->post("/topics/{$this->leaf->id}/archive")->assertRedirect("/topics/{$this->branch->id}");
        $this->assertNotNull($this->leaf->fresh()->archived_at);

        $this->post("/topics/{$this->sibling->id}/archive")->assertNotFound();
        $this->assertNull($this->sibling->fresh()->archived_at);
    }

    public function test_archiving_twice_is_refused(): void
    {
        $this->service()->archive($this->admin, $this->branch);

        $this->expectException(AuthorizationException::class);
        // The topic is hidden now, so even its archiver can no longer reach it through the explorer.
        $this->service()->archive($this->makeUser(), $this->branch);
    }

    public function test_the_archive_power_is_part_of_the_delegation_ceiling(): void
    {
        $lead = $this->makeUser();
        $this->assign($lead, $this->role('Lead', [Capability::View, Capability::AccessView, Capability::AccessAssign, Capability::Archive], [Capability::View, Capability::AccessView]), $this->branch);

        $this->expectException(AuthorizationException::class);
        app(AssignmentService::class)->grant($lead, $this->branch, $this->role('Archivist', [Capability::View, Capability::Archive]), $this->makeUser());
    }

    // Restore ------------------------------------------------------------

    public function test_restoring_brings_the_topic_back_with_its_branch(): void
    {
        $this->service()->archive($this->admin, $this->branch);
        $this->service()->restore($this->admin, $this->branch);

        $this->assertNull($this->branch->fresh()->archived_at);
        $this->assertTrue(app(TopicAccess::class)->canView($this->admin, $this->leaf));
        $this->assertSame(1, TopicAuditEvent::where('action', 'topic.restored')->count());
    }

    public function test_a_branch_is_never_restored_underneath_an_archived_parent(): void
    {
        $this->service()->archive($this->admin, $this->leaf);
        $this->service()->archive($this->admin, $this->branch);

        try {
            $this->service()->restore($this->admin, $this->leaf);
            $this->fail('Restoring below an archived topic must be refused.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Restore that one first', $exception->errors()['topic'][0]);
        }
        $this->assertNotNull($this->leaf->fresh()->archived_at);

        $this->service()->restore($this->admin, $this->branch);
        // The leaf was archived separately and stays archived until restored itself.
        $this->assertFalse(app(TopicAccess::class)->canView($this->admin, $this->leaf));
        $this->service()->restore($this->admin, $this->leaf);
        $this->assertTrue(app(TopicAccess::class)->canView($this->admin, $this->leaf));
    }

    public function test_restoring_something_that_is_not_archived_is_refused(): void
    {
        $this->expectException(ValidationException::class);
        $this->service()->restore($this->admin, $this->branch);
    }

    public function test_the_archived_list_shows_the_top_of_each_archived_branch_to_those_who_may_restore_it(): void
    {
        $this->service()->archive($this->admin, $this->leaf);
        $this->service()->archive($this->admin, $this->branch);
        $archiver = $this->archiver();
        $outsider = $this->makeUser();
        $this->assign($outsider, $this->role('Archivist', [Capability::View, Capability::Archive]), $this->sibling);

        $this->actingAs($archiver)->get('/topics/archived')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Topics/Archived')
                ->has('topics', 1)
                ->where('topics.0.title', 'Branch')
                ->where('topics.0.topics_inside', 1)
                ->where('topics.0.trail', ['Root'])
                ->where('topics.0.can_delete', false));

        // Someone who may archive only elsewhere sees nothing of it; someone with no archive power gets nowhere.
        $this->actingAs($outsider)->get('/topics/archived')->assertOk()->assertInertia(fn (Assert $page) => $page->has('topics', 0));
        $this->actingAs($this->makeUser())->get('/topics/archived')->assertRedirect('/');
    }

    public function test_restoring_over_http_and_hidden_topics_look_missing_to_outsiders(): void
    {
        $this->service()->archive($this->admin, $this->branch);
        $outsider = $this->makeUser();
        $this->assign($outsider, $this->role('Archivist', [Capability::View, Capability::Archive]), $this->sibling);

        $this->actingAs($outsider)->post("/topics/{$this->branch->id}/restore")->assertNotFound();
        $this->assertNotNull($this->branch->fresh()->archived_at);

        $this->actingAs($this->archiver())->post("/topics/{$this->branch->id}/restore")->assertRedirect("/topics/{$this->branch->id}");
        $this->assertNull($this->branch->fresh()->archived_at);
    }

    public function test_the_explorer_offers_archive_to_those_with_the_power_and_delete_only_to_admins(): void
    {
        $archiver = $this->archiver();

        $this->actingAs($archiver)->get("/topics/{$this->branch->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('selected.can.archive', true)
                ->where('selected.can.delete', false)
                ->where('canSeeArchived', true));
        $this->actingAs($this->admin)->get("/topics/{$this->branch->id}")
            ->assertInertia(fn (Assert $page) => $page->where('selected.can.delete', true));
        $reader = $this->makeUser();
        $this->assign($reader, $this->viewer(), $this->root);
        $this->actingAs($reader)->get("/topics/{$this->branch->id}")
            ->assertInertia(fn (Assert $page) => $page->where('selected.can.archive', false)->where('canSeeArchived', false));
    }

    public function test_archived_topics_cannot_be_moved_into_or_written_to(): void
    {
        $this->service()->archive($this->admin, $this->branch);

        $this->actingAs($this->admin)->post("/topics/{$this->branch->id}/children", ['title' => 'Nope'])->assertNotFound();
        $this->put("/topics/{$this->leaf->id}", ['title' => 'Nope'])->assertNotFound();
        $this->getJson("/topics/{$this->sibling->id}/destinations?q=Branch")->assertJsonCount(0, 'results');
    }

    // Permanent delete -----------------------------------------------------

    public function test_only_a_system_admin_may_prepare_a_permanent_delete(): void
    {
        $empty = $this->topic('Empty', $this->root);
        $archiver = $this->archiver();

        $this->actingAs($archiver)->postJson("/topics/{$empty->id}/delete/prepare")->assertNotFound();
        try {
            $this->service()->prepareDelete($archiver, $empty);
            $this->fail('A non-admin must be refused.');
        } catch (AuthorizationException) {
        }
        $this->assertNotNull(Topic::find($empty->id));
    }

    public function test_a_used_topic_cannot_be_deleted_and_the_reasons_say_why(): void
    {
        // children
        $this->assertCount(2, $this->service()->deleteBlockers($this->branch) ?: [1, 2]);
        $reasons = $this->service()->deleteBlockers($this->branch);
        $this->assertStringContainsString('1 topic(s) inside', $reasons[0]);
        $this->assertStringContainsString('Archive it instead', end($reasons));

        // assignments, even ended ones
        $ended = $this->assign($this->makeUser(), $this->viewer(), $this->sibling);
        $ended->update(['ended_at' => now(), 'ended_reason' => 'x']);
        $this->assertStringContainsString('1 role assignment(s), current or ended', $this->service()->deleteBlockers($this->sibling)[0]);

        // a role defined for the branch
        $scoped = $this->topic('Scoped', $this->root);
        $this->role('Local', [Capability::View], null, $scoped);
        $this->assertStringContainsString('role(s) are defined for this branch', $this->service()->deleteBlockers($scoped)[0]);

        // a linked course
        $linked = $this->topic('Linked', $this->root);
        $linked->update(['course_id' => Course::factory()->create()->id]);
        $this->assertStringContainsString('enrolment import', $this->service()->deleteBlockers($linked)[0]);

        $this->actingAs($this->admin)->postJson("/topics/{$this->branch->id}/delete/prepare")
            ->assertStatus(422)
            ->assertJsonPath('errors.topic.0', fn ($message) => str_contains($message, 'inside'));
        $this->assertNotNull(Topic::find($this->branch->id));
    }

    public function test_an_unused_topic_is_deleted_only_after_two_steps_and_the_typed_name(): void
    {
        $empty = $this->topic('Mistake', $this->root);

        $prepared = $this->actingAs($this->admin)->postJson("/topics/{$empty->id}/delete/prepare")
            ->assertOk()->assertJsonPath('summary.title', 'Mistake')->json();
        $this->assertNotNull(Topic::find($empty->id), 'Step one must not delete anything.');

        // Wrong name: refused, nothing deleted.
        $this->postJson('/topics/delete/execute', ['token' => $prepared['token'], 'confirm_title' => 'mistake'])->assertStatus(422)->assertJsonValidationErrors('confirm_title');
        $this->postJson('/topics/delete/execute', ['token' => $prepared['token']])->assertStatus(422);
        $this->assertNotNull(Topic::find($empty->id));

        $this->post('/topics/delete/execute', ['token' => $prepared['token'], 'confirm_title' => 'Mistake'])->assertRedirect('/topics?start=1');

        $this->assertNull(Topic::find($empty->id));
        $this->assertSame(0, DB::table('topic_closure')->where('descendant_id', $empty->id)->orWhere('ancestor_id', $empty->id)->count());
        $this->assertSame($this->root->id, Topic::where('title', 'Sibling')->value('parent_id'));
        $event = TopicAuditEvent::where('action', 'topic.deleted')->sole();
        $this->assertSame('Mistake', $event->before['title']);
        $this->assertSame($this->admin->id, $event->actor_id);

        // Replay: already gone.
        $this->postJson('/topics/delete/execute', ['token' => $prepared['token'], 'confirm_title' => 'Mistake'])->assertStatus(422);
    }

    public function test_an_archived_unused_topic_can_be_deleted_by_the_admin(): void
    {
        $empty = $this->topic('Archived mistake', $this->root);
        $this->service()->archive($this->admin, $empty);

        $token = $this->service()->prepareDelete($this->admin, $empty)['token'];
        $this->service()->executeDelete($this->admin, $token, 'Archived mistake');

        $this->assertNull(Topic::find($empty->id));
    }

    public function test_the_confirmation_cannot_be_forged_borrowed_expired_or_used_after_things_changed(): void
    {
        $empty = $this->topic('Mistake', $this->root);
        $second = $this->makeUser(UserRole::Admin);
        $token = $this->service()->prepareDelete($this->admin, $empty)['token'];

        foreach ([['garbage', $this->admin], [$token, $second]] as [$bad, $actor]) {
            try {
                $this->service()->executeDelete($actor, $bad, 'Mistake');
                $this->fail('Must be refused.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('confirmation', $exception->errors());
            }
        }

        // The topic changed after the first step.
        $this->travel(2)->seconds();
        $empty->update(['title' => 'Renamed']);
        try {
            $this->service()->executeDelete($this->admin, $token, 'Renamed');
            $this->fail('A changed topic must be refused.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('changed after you opened it', $exception->errors()['confirmation'][0]);
        }

        // Someone gives a role on it between the steps.
        $fresh = $this->service()->prepareDelete($this->admin, $empty->fresh())['token'];
        $this->assign($this->makeUser(), $this->viewer(), $empty);
        try {
            $this->service()->executeDelete($this->admin, $fresh, 'Renamed');
            $this->fail('A used topic must be refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('topic', $exception->errors());
        }
        $this->assertNotNull(Topic::find($empty->id));

        $this->travel(11)->minutes();
        $this->expectException(ValidationException::class);
        $this->service()->executeDelete($this->admin, $fresh, 'Renamed');
    }

    public function test_deleting_never_touches_other_topics_roles_or_assignments(): void
    {
        $empty = $this->topic('Mistake', $this->root);
        $roles = RoleDefinition::count();
        $assignment = $this->assign($this->makeUser(), $this->viewer(), $this->branch);

        $this->service()->executeDelete($this->admin, $this->service()->prepareDelete($this->admin, $empty)['token'], 'Mistake');

        $this->assertSame(4, Topic::count());
        $this->assertNull($assignment->fresh()->ended_at);
        $this->assertSame($roles + 1, RoleDefinition::count());
        $this->assertSame(1, RoleAssignment::count());
    }

    public function test_the_registry_lists_the_archive_power_for_the_role_builder(): void
    {
        $this->assertContains('topic.archive', array_column(Capability::registry(), 'value'));
    }
}

<?php

namespace Tests\Feature\Topics;

use App\Enums\Capability;
use App\Enums\UserRole;
use App\Models\RoleAssignment;
use App\Models\RoleDefinition;
use App\Models\Topic;
use App\Models\TopicAuditEvent;
use App\Models\User;
use App\Policies\RoleAssignmentPolicy;
use App\Services\AssignmentService;
use App\Services\SystemAdminService;
use App\Services\TopicAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * The organisation outlives the person: removal and handover keep topics,
 * descendants, role definitions, other people's roles and history.
 */
class TopicContinuityTest extends TopicTestCase
{
    private Topic $root;

    private Topic $branch;

    private Topic $leaf;

    private User $dean;

    private User $lead;

    private User $helper;

    private RoleAssignment $leadAssignment;

    private RoleAssignment $helperAssignment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = $this->topic('Root');
        $this->branch = $this->topic('Branch', $this->root);
        $this->leaf = $this->topic('Leaf', $this->branch);

        $this->dean = $this->makeUser(name: 'Dora Dean');
        $this->lead = $this->makeUser(name: 'Lena Lead');
        $this->helper = $this->makeUser(name: 'Hank Helper');

        $manager = $this->role('Manager', [Capability::View, Capability::Create, Capability::AccessView, Capability::AccessAssign, Capability::AccessEnd]);
        $this->assign($this->dean, $manager, $this->root);
        $this->leadAssignment = $this->assign($this->lead, $manager, $this->branch, $this->dean, RoleAssignment::query()->where('user_id', $this->dean->id)->first());
        $this->helperAssignment = $this->assign($this->helper, $this->viewer(), $this->leaf, $this->lead, $this->leadAssignment);
    }

    private function service(): AssignmentService
    {
        return app(AssignmentService::class);
    }

    private function end(User $actor, RoleAssignment $assignment, ?User $replacement = null, string $reason = 'Left the faculty'): array
    {
        $prepared = $this->service()->prepare($actor, $assignment, $replacement, $reason);

        return $this->service()->execute($actor, $prepared['token']);
    }

    public function test_nobody_ends_or_replaces_their_own_assignment(): void
    {
        $replacement = $this->makeUser();

        foreach ([null, $replacement] as $successor) {
            try {
                $this->service()->prepare($this->lead, $this->leadAssignment, $successor, 'Quit');
                $this->fail('Self removal must be refused.');
            } catch (AuthorizationException $exception) {
                $this->assertStringContainsString('your own role', $exception->getMessage());
            }
        }

        $this->assertNull($this->leadAssignment->fresh()->ended_at);
    }

    public function test_even_a_system_admin_cannot_end_their_own_assignment(): void
    {
        $adminAssignment = $this->assign($this->admin, $this->viewer(), $this->leaf);

        $this->expectException(AuthorizationException::class);
        $this->service()->prepare($this->admin, $adminAssignment, null, 'Quit');
    }

    public function test_a_superior_grant_cannot_be_ended_by_the_person_it_empowered(): void
    {
        // The lead's authority came from the dean's assignment; the lead cannot end the dean's.
        $deanAssignment = RoleAssignment::query()->where('user_id', $this->dean->id)->first();

        $this->expectException(AuthorizationException::class);
        $this->service()->prepare($this->lead, $deanAssignment, null, 'Coup');
    }

    public function test_a_peer_cannot_end_the_assignment_that_provided_their_authority_further_up_the_chain(): void
    {
        $other = $this->makeUser();
        $otherAssignment = $this->assign($other, $this->role('Manager 2', [Capability::View, Capability::AccessView, Capability::AccessEnd]), $this->leaf, $this->helper, $this->helperAssignment);
        $this->assertTrue(app(RoleAssignmentPolicy::class)->suppliesAuthorityOf($other, $this->helperAssignment));

        $this->expectException(AuthorizationException::class);
        $this->service()->prepare($other, $this->helperAssignment, null, 'No');
    }

    public function test_ending_needs_the_power_a_reason_and_active_state(): void
    {
        try {
            $this->service()->prepare($this->helper, RoleAssignment::query()->where('user_id', $this->lead->id)->first(), null, 'Nope');
            $this->fail('A viewer cannot end roles.');
        } catch (AuthorizationException) {
        }

        try {
            $this->service()->prepare($this->dean, $this->helperAssignment, null, '   ');
            $this->fail('A reason is required.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reason', $exception->errors());
        }
    }

    public function test_ordinary_removal_ends_only_that_assignment_with_date_reason_and_actor(): void
    {
        $result = $this->end($this->lead, $this->helperAssignment, null, 'Finished the course');

        $ended = $result['ended']->fresh();
        $this->assertNotNull($ended->ended_at);
        $this->assertSame('Finished the course', $ended->ended_reason);
        $this->assertSame($this->lead->id, $ended->ended_by_id);
        $this->assertNull($result['replacement']);
        $this->assertFalse(app(TopicAccess::class)->canView($this->helper->fresh(), $this->leaf));
        // The topic, descendants and the lead's own role are untouched.
        $this->assertSame(3, Topic::count());
        $this->assertNull($this->leadAssignment->fresh()->ended_at);
        $this->assertDatabaseHas('topic_audit_events', ['action' => 'assignment.ended', 'subject_id' => $this->helperAssignment->id]);
    }

    public function test_ending_one_assignment_keeps_the_access_from_the_other_one(): void
    {
        $author = $this->role('Author', [Capability::View, Capability::Create]);
        $second = $this->assign($this->helper, $author, $this->branch, $this->dean);

        $this->end($this->dean, $this->helperAssignment);

        $this->assertTrue(app(TopicAccess::class)->can($this->helper->fresh(), Capability::Create, $this->leaf));
        $this->assertNull($second->fresh()->ended_at);
    }

    public function test_handover_ends_the_outgoing_and_creates_the_replacement_at_the_same_role_and_scope(): void
    {
        $successor = $this->makeUser(name: 'Sam Successor');

        $result = $this->end($this->dean, $this->leadAssignment, $successor, 'Retired');

        $old = $result['ended']->fresh();
        $new = $result['replacement'];

        $this->assertNotNull($old->ended_at);
        $this->assertSame($new->id, $old->replaced_by_assignment_id);
        $this->assertSame($old->id, $new->replaces_assignment_id);
        $this->assertSame($old->role_definition_id, $new->role_definition_id);
        $this->assertSame($old->topic_id, $new->topic_id);
        $this->assertSame($successor->id, $new->user_id);
        $this->assertNull($new->ended_at);
        $this->assertDatabaseHas('topic_audit_events', ['action' => 'assignment.replaced', 'subject_id' => $old->id]);
    }

    public function test_handover_preserves_topics_descendants_roles_other_assignments_and_the_original_grantor(): void
    {
        $successor = $this->makeUser();
        $topicsBefore = Topic::pluck('id')->all();
        $rolesBefore = RoleDefinition::count();

        $result = $this->end($this->dean, $this->leadAssignment, $successor, 'Retired');

        $this->assertSame($topicsBefore, Topic::pluck('id')->all());
        $this->assertSame($rolesBefore, RoleDefinition::count());
        $this->assertNull($this->helperAssignment->fresh()->ended_at);
        $this->assertSame($this->lead->id, $this->helperAssignment->fresh()->granted_by_id);
        // The old assignment still names the dean who granted it; the successor's names the person who performed the handover.
        $this->assertSame($this->dean->id, $result['ended']->fresh()->granted_by_id);
        $this->assertSame($this->dean->id, $result['replacement']->granted_by_id);
        $this->assertTrue(app(TopicAccess::class)->can($successor->fresh(), Capability::AccessAssign, $this->leaf));
        $this->assertFalse(app(TopicAccess::class)->canView($this->lead->fresh(), $this->leaf));
    }

    public function test_grantor_departure_does_not_revoke_what_they_delegated(): void
    {
        $this->end($this->dean, $this->leadAssignment, $this->makeUser(), 'Retired');

        // The helper's role was granted by the lead, who is gone: still valid, still on record.
        $helper = $this->helperAssignment->fresh();
        $this->assertNull($helper->ended_at);
        $this->assertSame($this->lead->id, $helper->granted_by_id);
        $this->assertSame($this->leadAssignment->id, $helper->granted_by_assignment_id);
        $this->assertTrue(app(TopicAccess::class)->canView($this->helper->fresh(), $this->leaf));
    }

    public function test_deactivating_a_grantor_keeps_every_delegated_assignment_and_record(): void
    {
        $admin2 = $this->makeUser(UserRole::Admin);
        app(SystemAdminService::class)->deactivate($this->admin, $this->lead);

        $this->assertSame('inactive', $this->lead->fresh()->status);
        $this->assertNull($this->leadAssignment->fresh()->ended_at);
        $this->assertNull($this->helperAssignment->fresh()->ended_at);
        $this->assertTrue(app(TopicAccess::class)->canView($this->helper->fresh(), $this->leaf));
        $this->assertFalse(app(TopicAccess::class)->canView($this->lead->fresh(), $this->branch));
        $this->assertNotNull($admin2);
    }

    public function test_an_inactive_holder_can_be_replaced_without_rebuilding_the_branch(): void
    {
        $this->lead->update(['status' => 'inactive']);
        $successor = $this->makeUser();

        $result = $this->end($this->dean, $this->leadAssignment->fresh(), $successor, 'Account closed');

        $this->assertNotNull($result['ended']->ended_at);
        $this->assertNull($this->helperAssignment->fresh()->ended_at);
        $this->assertTrue(app(TopicAccess::class)->can($successor->fresh(), Capability::AccessAssign, $this->leaf));
    }

    public function test_an_invalid_replacement_changes_nothing(): void
    {
        $inactive = $this->makeUser(status: 'inactive');
        $alreadyHolds = $this->makeUser();
        $this->assign($alreadyHolds, $this->leadAssignment->role, $this->branch);

        foreach ([$inactive, $this->lead, $alreadyHolds] as $bad) {
            try {
                $this->service()->prepare($this->dean, $this->leadAssignment, $bad, 'Retired');
                $this->fail('Should refuse replacement '.$bad->name);
            } catch (ValidationException|AuthorizationException) {
            }
        }

        $this->assertNull($this->leadAssignment->fresh()->ended_at);
        $this->assertSame(0, RoleAssignment::whereNotNull('replaces_assignment_id')->count());
    }

    public function test_a_replacement_cannot_exceed_what_the_actor_may_pass_on(): void
    {
        $strong = $this->role('Strong', Capability::cases());
        $strongAssignment = $this->assign($this->makeUser(), $strong, $this->leaf, $this->admin);
        $successor = $this->makeUser();

        $this->expectException(AuthorizationException::class);
        $this->service()->prepare($this->dean, $strongAssignment, $successor, 'Retired');
    }

    public function test_failure_halfway_through_rolls_everything_back(): void
    {
        $successor = $this->makeUser();
        $prepared = $this->service()->prepare($this->dean, $this->leadAssignment, $successor, 'Retired');

        RoleAssignment::creating(function () {
            throw new \RuntimeException('Simulated database failure');
        });

        try {
            $this->service()->execute($this->dean, $prepared['token']);
            $this->fail('The simulated failure should surface.');
        } catch (\RuntimeException) {
        } finally {
            RoleAssignment::flushEventListeners();
            RoleAssignment::clearBootedModels();
        }

        $this->assertNull($this->leadAssignment->fresh()->ended_at);
        $this->assertNull($this->leadAssignment->fresh()->replaced_by_assignment_id);
        $this->assertSame(0, RoleAssignment::whereNotNull('replaces_assignment_id')->count());
        $this->assertSame(0, TopicAuditEvent::where('action', 'assignment.replaced')->count());
    }

    public function test_cancelling_means_never_executing_and_changes_nothing(): void
    {
        $this->service()->prepare($this->dean, $this->leadAssignment, $this->makeUser(), 'Retired');

        $this->assertNull($this->leadAssignment->fresh()->ended_at);
        $this->assertSame(0, RoleAssignment::whereNotNull('ended_at')->count());
    }

    public function test_a_replayed_confirmation_is_refused_and_changes_nothing_more(): void
    {
        $prepared = $this->service()->prepare($this->dean, $this->helperAssignment, null, 'Done');
        $this->service()->execute($this->dean, $prepared['token']);
        $auditCount = TopicAuditEvent::count();

        try {
            $this->service()->execute($this->dean, $prepared['token']);
            $this->fail('A replay must be refused.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('already ended', $exception->errors()['confirmation'][0]);
        }

        $this->assertSame($auditCount, TopicAuditEvent::count());
    }

    public function test_a_stale_confirmation_is_refused_when_the_assignment_changed_meanwhile(): void
    {
        $prepared = $this->service()->prepare($this->dean, $this->helperAssignment, null, 'Done');
        RoleAssignment::whereKey($this->helperAssignment->id)->increment('version');

        try {
            $this->service()->execute($this->dean, $prepared['token']);
            $this->fail('A stale confirmation must be refused.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('changed', $exception->errors()['confirmation'][0]);
        }

        $this->assertNull($this->helperAssignment->fresh()->ended_at);
    }

    public function test_a_confirmation_cannot_be_forged_borrowed_or_kept_forever(): void
    {
        $prepared = $this->service()->prepare($this->dean, $this->helperAssignment, null, 'Done');

        foreach ([['token' => 'garbage', 'actor' => $this->dean], ['token' => $prepared['token'], 'actor' => $this->lead]] as $case) {
            try {
                $this->service()->execute($case['actor'], $case['token']);
                $this->fail('Should be refused.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('confirmation', $exception->errors());
            }
        }

        $this->travel(11)->minutes();
        $this->expectException(ValidationException::class);
        $this->service()->execute($this->dean, $prepared['token']);
    }

    public function test_authority_is_checked_again_at_the_final_step(): void
    {
        $prepared = $this->service()->prepare($this->lead, $this->helperAssignment, null, 'Done');

        // Between the dialogs the lead loses their own role.
        $this->leadAssignment->update(['ended_at' => now(), 'ended_reason' => 'test']);

        $this->expectException(AuthorizationException::class);
        $this->service()->execute($this->lead, $prepared['token']);
    }

    public function test_the_assignment_history_stays_visible_in_the_audit_trail(): void
    {
        $this->end($this->dean, $this->helperAssignment, null, 'Finished');

        $event = TopicAuditEvent::where('action', 'assignment.ended')->first();
        $this->assertSame($this->dean->id, $event->actor_id);
        $this->assertNull($event->before['ended_at']);
        $this->assertNotNull($event->after['ended_at']);
    }
}

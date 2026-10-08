<?php

namespace Tests\Feature\Topics;

use App\Enums\Capability;
use App\Enums\UserRole;
use App\Models\User;
use App\Services\SystemAdminService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * At least one active System Admin always exists, and nobody removes or
 * deactivates themselves.
 */
class SystemAdminContinuityTest extends TopicTestCase
{
    private function service(): SystemAdminService
    {
        return app(SystemAdminService::class);
    }

    private function activeAdmins(): int
    {
        return User::role(UserRole::Admin)->where('status', 'active')->count();
    }

    public function test_the_only_admin_can_appoint_a_second_admin(): void
    {
        $second = $this->makeUser();

        $this->service()->appoint($this->admin, $second);

        $this->assertTrue($second->fresh()->hasRole(UserRole::Admin));
        $this->assertSame(2, $this->activeAdmins());
        $this->assertDatabaseHas('topic_audit_events', ['action' => 'admin.appointed', 'subject_id' => $second->id]);
    }

    public function test_only_an_active_account_can_be_appointed(): void
    {
        $this->expectException(ValidationException::class);
        $this->service()->appoint($this->admin, $this->makeUser(status: 'inactive'));
    }

    public function test_a_non_admin_cannot_appoint_anyone(): void
    {
        $teacher = $this->makeUser();

        $this->expectException(AuthorizationException::class);
        $this->service()->appoint($teacher, $this->makeUser());
    }

    public function test_the_only_admin_cannot_remove_replace_or_deactivate_themselves(): void
    {
        $successor = $this->makeUser();

        foreach ([
            fn () => $this->service()->remove($this->admin, $this->admin, 'x'),
            fn () => $this->service()->replace($this->admin, $this->admin, $successor, 'x'),
            fn () => $this->service()->deactivate($this->admin, $this->admin),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Self removal must be refused.');
            } catch (AuthorizationException) {
            }
        }

        $this->assertSame(1, $this->activeAdmins());
        $this->assertSame('active', $this->admin->fresh()->status);
    }

    public function test_after_appointing_a_second_admin_they_can_remove_the_original(): void
    {
        $second = $this->makeUser();
        $this->service()->appoint($this->admin, $second);

        $this->service()->remove($second->fresh(), $this->admin->fresh(), 'Moved to another department');

        $this->assertFalse($this->admin->fresh()->hasRole(UserRole::Admin));
        $this->assertSame(1, $this->activeAdmins());
    }

    public function test_replacing_an_admin_hands_over_in_one_step_and_needs_a_reason(): void
    {
        $second = $this->makeUser(UserRole::Admin);
        $incoming = $this->makeUser();

        try {
            $this->service()->replace($second, $this->admin, $incoming, '  ');
            $this->fail('A reason is required.');
        } catch (ValidationException) {
        }

        $this->service()->replace($second, $this->admin, $incoming, 'Retired');

        $this->assertFalse($this->admin->fresh()->hasRole(UserRole::Admin));
        $this->assertTrue($incoming->fresh()->hasRole(UserRole::Admin));
        $this->assertSame(2, $this->activeAdmins());
    }

    public function test_an_invalid_incoming_admin_leaves_the_outgoing_admin_in_place(): void
    {
        $second = $this->makeUser(UserRole::Admin);

        try {
            $this->service()->replace($second, $this->admin, $this->makeUser(status: 'inactive'), 'Retired');
            $this->fail('Should be refused.');
        } catch (ValidationException) {
        }

        $this->assertTrue($this->admin->fresh()->hasRole(UserRole::Admin));
        $this->assertSame(2, $this->activeAdmins());
    }

    public function test_the_last_active_admin_cannot_be_taken_by_an_inactive_admin_acting(): void
    {
        // A second admin exists but is inactive: they are not an active actor.
        $inactiveAdmin = $this->makeUser(UserRole::Admin, status: 'inactive');

        $this->expectException(AuthorizationException::class);
        $this->service()->remove($inactiveAdmin, $this->admin, 'Coup');
    }

    public function test_deactivating_another_admin_is_allowed_while_one_stays_active(): void
    {
        $second = $this->makeUser(UserRole::Admin);

        $this->service()->deactivate($this->admin, $second);

        $this->assertSame('inactive', $second->fresh()->status);
        $this->assertSame(1, $this->activeAdmins());
    }

    public function test_a_second_admin_removed_leaves_the_first_but_not_vice_versa_when_alone(): void
    {
        $second = $this->makeUser(UserRole::Admin);
        $this->service()->deactivate($this->admin, $second);

        // Only the first admin is left: nobody else can act, and they cannot deactivate themselves.
        $this->expectException(AuthorizationException::class);
        $this->service()->deactivate($second->fresh(), $this->admin);
    }

    public function test_a_topic_role_cannot_grant_or_remove_system_admin_authority(): void
    {
        $role = $this->role('System Admin', Capability::cases());
        $holder = $this->makeUser();
        $this->assign($holder, $role, $this->topic('Root'));

        $this->assertFalse($holder->hasRole(UserRole::Admin));
        $this->expectException(AuthorizationException::class);
        $this->service()->remove($holder, $this->admin, 'Coup');
    }

    public function test_deactivating_an_account_ends_its_sessions_and_keeps_its_assignments(): void
    {
        $topic = $this->topic('Root');
        $user = $this->makeUser();
        $assignment = $this->assign($user, $this->viewer(), $topic);
        DB::table('sessions')->insert(['id' => 'abc', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

        $this->service()->deactivate($this->admin, $user);

        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertNull($assignment->fresh()->ended_at);
        $this->assertSame(1, $this->service()->activeAssignmentCount($user));
    }
}

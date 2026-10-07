<?php

namespace Tests\Feature\Topics;

use App\Enums\Capability;
use App\Enums\UserRole;
use App\Models\RoleAssignment;
use App\Models\RoleDefinition;
use App\Models\Topic;
use App\Models\User;
use App\Services\TopicService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Shared fixtures for the topic-tree tests. Topics are built through the real
 * service (so closure rows are real); assignments are inserted directly so a
 * test can set up any situation, including ones the service would refuse.
 */
abstract class TopicTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = $this->makeUser(UserRole::Admin, 'Admin One');
    }

    protected function makeUser(UserRole $role = UserRole::Teacher, ?string $name = null, string $status = 'active'): User
    {
        $user = User::factory()->create(array_filter(['name' => $name, 'status' => $status]));
        $user->assignRole($role);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function topic(string $title, ?Topic $parent = null, ?User $by = null, array $data = []): Topic
    {
        return app(TopicService::class)->create($by ?? $this->admin, $parent, [...$data, 'title' => $title]);
    }

    /**
     * @param  list<Capability>  $capabilities
     * @param  list<Capability>|null  $delegable  null = may pass on everything it has
     */
    protected function role(string $name, array $capabilities, ?array $delegable = null, ?Topic $scope = null): RoleDefinition
    {
        $values = array_map(fn (Capability $c) => $c->value, $capabilities);

        return RoleDefinition::create([
            'name' => $name,
            'topic_id' => $scope?->id,
            'capabilities' => $values,
            'delegable' => $delegable === null ? $values : array_map(fn (Capability $c) => $c->value, $delegable),
            'created_by_id' => $this->admin->id,
        ]);
    }

    protected function assign(User $user, RoleDefinition $role, Topic $topic, ?User $by = null, ?RoleAssignment $via = null): RoleAssignment
    {
        return RoleAssignment::create([
            'topic_id' => $topic->id,
            'user_id' => $user->id,
            'role_definition_id' => $role->id,
            'granted_by_id' => ($by ?? $this->admin)->id,
            'granted_by_assignment_id' => $via?->id,
            'started_at' => now(),
        ]);
    }

    protected function viewer(): RoleDefinition
    {
        return $this->role('Viewer', [Capability::View]);
    }

    /**
     * A role holding every capability, able to pass all of them on.
     */
    protected function branchLead(): RoleDefinition
    {
        return $this->role('Branch lead', Capability::cases());
    }
}

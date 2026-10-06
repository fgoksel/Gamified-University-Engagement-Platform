<?php

namespace Tests\Feature\SubjectTree;

use App\Enums\TreeRole;
use App\Enums\UserRole;
use App\Models\Course;
use App\Models\TreeUnit;
use App\Models\UnitMembership;
use App\Models\User;
use App\Services\TreeService;
use Closure;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Builds the same small tree for every subject tree test:
 *
 *   Faculty of Informatics (root, dean)
 *   ├─ Database (subject, teacher)
 *   │  └─ SQL (subtopic)
 *   └─ Networks (subject, nobody yet)
 */
abstract class SubjectTreeTestCase extends TestCase
{
    use RefreshDatabase;

    protected TreeService $tree;

    protected User $admin;

    protected User $dean;

    protected User $teacher;

    protected TreeUnit $root;

    protected TreeUnit $database;

    protected TreeUnit $sql;

    protected TreeUnit $networks;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->tree = app(TreeService::class);
        $this->admin = User::factory()->create()->assignRole(UserRole::Admin);
        $this->dean = $this->teacherAccount();
        $this->teacher = $this->teacherAccount();

        $this->root = $this->tree->createTree($this->admin, 'Faculty of Informatics', $this->dean);
        $this->database = $this->tree->addSubject($this->admin, $this->root, Course::factory()->create(['name' => 'Database']));
        $this->networks = $this->tree->addSubject($this->admin, $this->root, Course::factory()->create(['name' => 'Networks']));
        $this->tree->addMember($this->dean, $this->database, $this->teacher, TreeRole::Teacher);
        $this->sql = $this->tree->addSubtopic($this->teacher, $this->database, 'SQL');
    }

    protected function teacherAccount(): User
    {
        return User::factory()->create()->assignRole(UserRole::Teacher);
    }

    protected function studentAccount(): User
    {
        return User::factory()->create()->assignRole(UserRole::Student);
    }

    /**
     * Add a person in $role on $unit, as $actor. Creates an account of the
     * right type when no $user is given.
     */
    protected function add(User $actor, TreeUnit $unit, TreeRole $role, ?User $user = null): UnitMembership
    {
        $user ??= $role->accountType() === UserRole::Teacher ? $this->teacherAccount() : $this->studentAccount();

        return $this->tree->addMember($actor, $unit, $user, $role);
    }

    protected function assertCan(User $user, string $ability, mixed $arguments): void
    {
        $this->assertTrue(Gate::forUser($user)->allows($ability, $arguments), "Expected {$ability} to be allowed.");
    }

    protected function assertCannot(User $user, string $ability, mixed $arguments): void
    {
        $this->assertFalse(Gate::forUser($user)->allows($ability, $arguments), "Expected {$ability} to be refused.");
    }

    /**
     * The action must be refused by a policy.
     */
    protected function assertForbidden(Closure $action): void
    {
        try {
            $action();
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('Expected the action to be forbidden.');
    }

    /**
     * The action must be refused by a data rule, with a message on $field.
     */
    protected function assertRejected(Closure $action, string $field): void
    {
        try {
            $action();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());

            return;
        }

        $this->fail("Expected the action to be rejected on {$field}.");
    }
}

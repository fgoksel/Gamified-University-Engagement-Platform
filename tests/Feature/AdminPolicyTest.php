<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class AdminPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function createUser(string $email, UserRole $role, string $status = 'active'): User
    {
        $user = User::create([
            'name' => "User {$email}",
            'email' => $email,
            'password' => 'secret_hash',
            'status' => $status,
        ]);
        $user->assignRole($role);

        return $user;
    }

    public function test_admin_cannot_deactivate_their_own_account(): void
    {
        $admin = $this->createUser('admin1@campusengage.hu', UserRole::Admin);

        $this->assertFalse(Gate::forUser($admin)->allows('deactivate', $admin));
        $this->assertFalse($admin->can('deactivate', $admin));
    }

    public function test_admin_cannot_deactivate_last_remaining_active_admin(): void
    {
        $admin1 = $this->createUser('admin1@campusengage.hu', UserRole::Admin);

        // Attempting to deactivate the only active admin is denied (self-lock)
        $this->assertFalse($admin1->can('deactivate', $admin1));

        // Create a teacher account with admin permissions or another user
        $inactiveAdmin = $this->createUser('admin2@campusengage.hu', UserRole::Admin, status: 'inactive');

        // Only admin1 is active
        $this->assertSame(1, User::role(UserRole::Admin)->where('status', 'active')->count());
        $this->assertFalse($admin1->can('deactivate', $admin1));
    }

    public function test_admin_can_deactivate_another_admin_when_multiple_active_admins_exist(): void
    {
        $admin1 = $this->createUser('admin1@campusengage.hu', UserRole::Admin);
        $admin2 = $this->createUser('admin2@campusengage.hu', UserRole::Admin);

        $this->assertSame(2, User::role(UserRole::Admin)->where('status', 'active')->count());
        $this->assertTrue($admin1->can('deactivate', $admin2));
        $this->assertTrue($admin2->can('deactivate', $admin1));
    }

    public function test_admin_cannot_deactivate_last_admin_even_if_target_has_teacher_role(): void
    {
        $admin1 = $this->createUser('admin1@campusengage.hu', UserRole::Admin);
        $admin1->assignRole(UserRole::Teacher);

        // admin1 is the only active admin, though also a teacher
        $this->assertSame(1, User::role(UserRole::Admin)->where('status', 'active')->count());

        $this->assertFalse($admin1->can('deactivate', $admin1));
    }

    public function test_admin_can_deactivate_regular_teacher_and_student(): void
    {
        $admin = $this->createUser('admin@campusengage.hu', UserRole::Admin);
        $teacher = $this->createUser('teacher@pte.hu', UserRole::Teacher);
        $student = $this->createUser('student@student.pte.hu', UserRole::Student);

        $this->assertTrue($admin->can('deactivate', $teacher));
        $this->assertTrue($admin->can('deactivate', $student));
    }

    public function test_non_admin_cannot_deactivate_any_account(): void
    {
        $teacher = $this->createUser('teacher@pte.hu', UserRole::Teacher);
        $student = $this->createUser('student@student.pte.hu', UserRole::Student);

        $this->assertFalse($teacher->can('deactivate', $student));
        $this->assertFalse($student->can('deactivate', $teacher));
    }
}

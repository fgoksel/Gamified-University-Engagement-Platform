<?php

namespace Tests\Feature\SubjectTree;

use App\Enums\UserRole;
use App\Models\Semester;
use App\Models\User;
use Database\Seeders\TreeTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The test accounts for trying out the subject tree.
 */
class TreeTestSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_active_teacher_and_student_accounts(): void
    {
        $this->seed(TreeTestSeeder::class);

        foreach (array_keys(TreeTestSeeder::TEACHERS) as $email) {
            $this->assertUsableAccount(User::where('email', $email)->sole(), UserRole::Teacher);
        }

        foreach (TreeTestSeeder::STUDENTS as $neptun) {
            $student = User::where('neptun_code', $neptun)->sole();
            $this->assertSame(strtolower($neptun).'@test.local', $student->email);
            $this->assertUsableAccount($student, UserRole::Student);
        }
    }

    public function test_exactly_one_semester_is_active(): void
    {
        $this->seed(TreeTestSeeder::class);

        $this->assertSame(1, Semester::where('status', 'active')->count());
    }

    public function test_an_archived_semester_with_the_default_name_still_leaves_one_active(): void
    {
        Semester::create(['name' => '2026/2027 Fall Semester', 'starts_at' => '2026-09-07', 'ends_at' => '2027-01-31', 'status' => 'archived']);

        $this->seed(TreeTestSeeder::class);

        $this->assertSame(1, Semester::where('status', 'active')->count());
    }

    public function test_existing_users_are_not_changed(): void
    {
        $this->seed(TreeTestSeeder::class);
        $existing = User::factory()->create(['email' => 'other@test.local', 'neptun_code' => 'XYZ999']);
        User::where('email', 'dean@test.local')->update(['name' => 'Renamed Dean', 'must_change_password' => true]);

        $this->seed(TreeTestSeeder::class);

        $dean = User::where('email', 'dean@test.local')->sole();
        $this->assertSame('Renamed Dean', $dean->name);
        $this->assertTrue($dean->must_change_password);
        $this->assertEquals($existing->fresh()->updated_at, $existing->updated_at);
        $this->assertSame(10, User::count());
    }

    public function test_a_neptun_code_owned_by_someone_else_is_left_alone(): void
    {
        $owner = User::factory()->create(['email' => 'real.student@example.com', 'neptun_code' => 'ABC001']);

        $this->seed(TreeTestSeeder::class);

        $this->assertNull(User::where('email', 'abc001@test.local')->first());
        $this->assertSame('real.student@example.com', User::where('neptun_code', 'ABC001')->sole()->email);
        $this->assertSame($owner->id, User::where('neptun_code', 'ABC001')->sole()->id);
    }

    private function assertUsableAccount(User $user, UserRole $role): void
    {
        $this->assertTrue($user->hasRole($role));
        $this->assertSame('active', $user->status);
        $this->assertFalse($user->must_change_password);
        $this->assertTrue(Hash::check('password', $user->password));
    }
}

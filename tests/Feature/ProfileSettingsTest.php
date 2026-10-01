<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Faculty;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Profile Settings page: Profile Data, Change Password and Appearance.
 */
class ProfileSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Storage::fake('public');
    }

    private function student(array $attributes = []): User
    {
        return User::factory()->create([
            'password' => 'old-password',
            ...$attributes,
        ])->assignRole(UserRole::Student);
    }

    public function test_the_page_shows_the_users_profile_data(): void
    {
        $faculty = Faculty::create(['code' => 'MIK', 'name' => 'Faculty of Engineering and Information Technology']);
        $student = $this->student([
            'name' => 'Kovács Anna',
            'email' => 'anna@example.com',
            'neptun_code' => 'ABC123',
            'major' => 'Civil Engineering',
            'year_of_study' => 2,
            'faculty_id' => $faculty->id,
        ]);

        $this->actingAs($student)->get('/profile')->assertInertia(fn (Assert $page) => $page
            ->component('Profile/Edit')
            ->where('profile.name', 'Kovács Anna')
            ->where('profile.email', 'anna@example.com')
            ->where('profile.role', 'student')
            ->where('profile.faculty', 'Faculty of Engineering and Information Technology')
            ->where('profile.neptunCode', 'ABC123')
            ->where('profile.major', 'Civil Engineering')
            ->where('profile.yearOfStudy', 2));
    }

    public function test_a_photo_can_be_uploaded_and_is_shared_with_the_layout(): void
    {
        $student = $this->student();

        $this->actingAs($student)
            ->post('/profile/avatar', ['avatar' => UploadedFile::fake()->image('me.png', 200, 200)])
            ->assertRedirect()
            ->assertSessionHas('success', 'Your profile photo has been updated.');

        $path = $student->fresh()->avatar;
        $this->assertNotNull($path);
        Storage::disk('public')->assertExists($path);

        $this->actingAs($student)->get('/profile')->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.avatar', Storage::disk('public')->url($path)));
    }

    public function test_a_new_photo_replaces_the_old_file(): void
    {
        $student = $this->student();

        $this->actingAs($student)->post('/profile/avatar', ['avatar' => UploadedFile::fake()->image('one.png')]);
        $oldPath = $student->fresh()->avatar;

        $this->actingAs($student)->post('/profile/avatar', ['avatar' => UploadedFile::fake()->image('two.png')]);

        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists($student->fresh()->avatar);
    }

    public function test_only_small_images_are_accepted(): void
    {
        $student = $this->student();

        $this->actingAs($student)
            ->post('/profile/avatar', ['avatar' => UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf')])
            ->assertSessionHasErrors('avatar');

        $this->actingAs($student)
            ->post('/profile/avatar', ['avatar' => UploadedFile::fake()->image('big.png')->size(3000)])
            ->assertSessionHasErrors(['avatar' => 'The photo cannot be larger than 2 MB.']);

        $this->assertNull($student->fresh()->avatar);
    }

    public function test_the_photo_can_be_removed(): void
    {
        $student = $this->student();
        $this->actingAs($student)->post('/profile/avatar', ['avatar' => UploadedFile::fake()->image('me.png')]);
        $path = $student->fresh()->avatar;

        $this->actingAs($student)
            ->delete('/profile/avatar')
            ->assertSessionHas('success', 'Your profile photo has been removed.');

        $this->assertNull($student->fresh()->avatar);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_the_password_can_be_changed_from_the_profile(): void
    {
        $teacher = User::factory()->create(['password' => 'old-password'])->assignRole(UserRole::Teacher);

        $this->actingAs($teacher)
            ->from('/profile')
            ->put('/profile/password', [
                'current_password' => 'old-password',
                'password' => 'brand-new-password',
                'password_confirmation' => 'brand-new-password',
            ])
            ->assertRedirect('/profile')
            ->assertSessionHas('success', 'Your password has been changed.');

        $this->assertTrue(Hash::check('brand-new-password', $teacher->fresh()->password));
    }

    public function test_a_wrong_old_password_is_refused(): void
    {
        $student = $this->student();

        $this->actingAs($student)
            ->from('/profile')
            ->put('/profile/password', [
                'current_password' => 'wrong-password',
                'password' => 'brand-new-password',
                'password_confirmation' => 'brand-new-password',
            ])
            ->assertRedirect('/profile')
            ->assertSessionHasErrors(['current_password' => 'The old password is incorrect.']);

        $this->assertTrue(Hash::check('old-password', $student->fresh()->password));
    }

    public function test_the_new_password_must_be_confirmed(): void
    {
        $this->actingAs($this->student())
            ->from('/profile')
            ->put('/profile/password', [
                'current_password' => 'old-password',
                'password' => 'brand-new-password',
                'password_confirmation' => 'something-else',
            ])
            ->assertSessionHasErrors(['password' => 'The two passwords do not match.']);
    }

    public function test_guests_and_admins_cannot_use_the_profile_actions(): void
    {
        $this->post('/profile/avatar')->assertRedirect('/login');
        $this->put('/profile/password')->assertRedirect('/login');

        $admin = User::factory()->create()->assignRole(UserRole::Admin);
        $this->actingAs($admin)->delete('/profile/avatar')->assertRedirect('/');
    }
}

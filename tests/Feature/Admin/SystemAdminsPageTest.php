<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Filament\Pages\SystemAdmins;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The System admins page: appointing, and the two-step remove and replace
 * flows. The service tests cover the invariants; these check the screens.
 */
class SystemAdminsPageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->create(['name' => 'First Admin'])->assignRole(UserRole::Admin);
    }

    private function page()
    {
        return Livewire::actingAs($this->admin)->test(SystemAdmins::class);
    }

    public function test_only_system_admins_open_the_page(): void
    {
        $teacher = User::factory()->create()->assignRole(UserRole::Teacher);

        $this->actingAs($teacher)->get('/admin/system-admins')->assertRedirect('/');
        $this->actingAs($this->admin)->get('/admin/system-admins')->assertOk();
    }

    public function test_the_only_admin_appoints_a_second_admin_and_cannot_touch_themselves(): void
    {
        $second = User::factory()->create(['name' => 'Second Admin'])->assignRole(UserRole::Teacher);

        $this->page()
            ->assertCanSeeTableRecords([$this->admin])
            ->assertTableActionDisabled('remove', $this->admin)
            ->assertTableActionDisabled('replace', $this->admin)
            ->callAction('appoint', ['user' => $second->id])
            ->assertNotified('System Admin appointed');

        $this->assertTrue($second->fresh()->hasRole(UserRole::Admin));
    }

    public function test_removing_an_admin_takes_two_steps_and_the_first_changes_nothing(): void
    {
        $second = User::factory()->create(['name' => 'Second Admin'])->assignRole(UserRole::Admin);

        $this->page()
            ->callTableAction('remove', $second, ['reason' => 'Left the university'])
            ->assertActionMounted('confirmRemove');

        $this->assertTrue($second->fresh()->hasRole(UserRole::Admin));

        $this->page()
            ->mountAction('confirmRemove', ['outgoing' => $second->id, 'reason' => 'Left the university'])
            ->callMountedAction()
            ->assertNotified('System Admin removed');

        $this->assertFalse($second->fresh()->hasRole(UserRole::Admin));
        $this->assertTrue($this->admin->fresh()->hasRole(UserRole::Admin));
    }

    public function test_replacing_an_admin_takes_two_steps(): void
    {
        $second = User::factory()->create()->assignRole(UserRole::Admin);
        $incoming = User::factory()->create(['name' => 'New Admin'])->assignRole(UserRole::Teacher);

        $this->page()
            ->callTableAction('replace', $second, ['incoming' => $incoming->id, 'reason' => 'Handover'])
            ->assertActionMounted('confirmReplace');
        $this->assertTrue($second->fresh()->hasRole(UserRole::Admin));
        $this->assertFalse($incoming->fresh()->hasRole(UserRole::Admin));

        $this->page()
            ->mountAction('confirmReplace', ['outgoing' => $second->id, 'incoming' => $incoming->id, 'reason' => 'Handover'])
            ->callMountedAction()
            ->assertNotified('System Admin replaced');

        $this->assertFalse($second->fresh()->hasRole(UserRole::Admin));
        $this->assertTrue($incoming->fresh()->hasRole(UserRole::Admin));
    }

    public function test_a_stale_second_step_is_refused_when_the_situation_changed(): void
    {
        // The second admin was already removed by someone else between the two steps.
        $second = User::factory()->create()->assignRole(UserRole::Admin);
        $second->removeRole(UserRole::Admin);

        $this->page()
            ->mountAction('confirmRemove', ['outgoing' => $second->id, 'reason' => 'Late'])
            ->callMountedAction()
            ->assertNotified('Nothing was changed');

        $this->assertSame(1, User::role(UserRole::Admin)->count());
    }
}

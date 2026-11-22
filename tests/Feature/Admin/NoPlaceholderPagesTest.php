<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Filament\Pages\EventModeration;
use App\Models\Faculty;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task #123: no page of the admin panel lists planned features. The pages that did (the Semester
 * page, and the Event moderation page until Task #58) are built or gone, and the placeholder view
 * they shared is deleted, so a new page cannot quietly use it again.
 */
class NoPlaceholderPagesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->create()->assignRole(UserRole::Admin);
    }

    public function test_the_placeholder_view_is_gone(): void
    {
        $this->assertFileDoesNotExist(resource_path('views/filament/pages/coming-soon.blade.php'));
    }

    public function test_no_page_of_the_admin_panel_shows_planned_features(): void
    {
        $pages = Filament::getPanel('admin')->getPages();
        // The courses page opens one faculty; the others ignore the parameter.
        $faculty = Faculty::factory()->create();

        // Every page of the panel, so a new one is checked without anyone adding it here. The page of
        // the Event moderation is among them: it was a placeholder until Task #58.
        $this->assertContains(EventModeration::class, $pages);

        foreach ($pages as $page) {
            $this->actingAs($this->admin)->get($page::getUrl(['faculty' => $faculty->id], panel: 'admin'))
                ->assertOk()
                ->assertDontSee('Coming soon')
                ->assertDontSee('This page will let administrators')
                ->assertDontSee('Planned features');
        }
    }

    public function test_the_semester_page_is_not_in_the_menu_and_cannot_be_opened(): void
    {
        $this->actingAs($this->admin)->get('/admin')
            ->assertOk()
            ->assertDontSee('/admin/semester-management', false);

        $this->actingAs($this->admin)->get('/admin/semester-management')->assertNotFound();
    }
}

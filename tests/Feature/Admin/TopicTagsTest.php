<?php

namespace Tests\Feature\Admin;

use App\Enums\TaxonomyCategory;
use App\Enums\UserRole;
use App\Filament\Pages\TopicTags;
use App\Models\TopicTag;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Tests for Global Topic Tag Taxonomy Management — UC-3.4.
 */
class TopicTagsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->admin = User::factory()->create([
            'status' => 'active',
            'must_change_password' => false,
        ])->assignRole(UserRole::Admin);
    }

    public function test_admin_can_view_topic_tags_page_and_table(): void
    {
        $tag = TopicTag::create([
            'name' => 'Exam Preparation',
            'category' => 'academic',
            'description' => 'Support sessions before midterms and finals.',
            'is_active' => true,
        ]);

        Livewire::actingAs($this->admin)
            ->test(TopicTags::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$tag])
            ->assertSee('Exam Preparation')
            ->assertSee('Academic');
    }

    public function test_admin_can_create_new_topic_tag(): void
    {
        Livewire::actingAs($this->admin)
            ->test(TopicTags::class)
            ->callAction('create', [
                'name' => 'Campus Hackathon',
                'category' => TaxonomyCategory::Academic->value,
                'description' => '24-hour programming challenge.',
            ])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('topic_tags', [
            'name' => 'Campus Hackathon',
            'category' => 'academic',
            'description' => '24-hour programming challenge.',
            'is_active' => 1,
        ]);
    }

    public function test_topic_tag_creation_requires_mandatory_fields(): void
    {
        Livewire::actingAs($this->admin)
            ->test(TopicTags::class)
            ->callAction('create', [
                'name' => '',
                'category' => '',
            ])
            ->assertHasActionErrors(['name' => 'required', 'category' => 'required']);
    }

    public function test_topic_tag_creation_rejects_duplicate_name_with_spec_message(): void
    {
        TopicTag::create([
            'name' => 'Freshman Ball',
            'category' => 'community',
            'is_active' => true,
        ]);

        Livewire::actingAs($this->admin)
            ->test(TopicTags::class)
            ->callAction('create', [
                'name' => 'Freshman Ball',
                'category' => TaxonomyCategory::Community->value,
            ])
            ->assertHasActionErrors(['name' => 'A topic tag with this name already exists.']);
    }

    public function test_admin_can_edit_topic_tag(): void
    {
        $tag = TopicTag::create([
            'name' => 'Old Tag Name',
            'category' => 'sports',
            'description' => 'Old description',
            'is_active' => true,
        ]);

        Livewire::actingAs($this->admin)
            ->test(TopicTags::class)
            ->callTableAction('edit', $tag, [
                'name' => 'Updated Tag Name',
                'category' => TaxonomyCategory::Sports->value,
                'description' => 'Updated description text.',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('topic_tags', [
            'id' => $tag->id,
            'name' => 'Updated Tag Name',
            'category' => 'sports',
            'description' => 'Updated description text.',
        ]);
    }

    public function test_topic_tag_edit_rejects_duplicate_name(): void
    {
        TopicTag::create([
            'name' => 'Existing Tag One',
            'category' => 'scientific',
            'is_active' => true,
        ]);

        $secondTag = TopicTag::create([
            'name' => 'Second Tag',
            'category' => 'scientific',
            'is_active' => true,
        ]);

        Livewire::actingAs($this->admin)
            ->test(TopicTags::class)
            ->callTableAction('edit', $secondTag, [
                'name' => 'Existing Tag One',
                'category' => TaxonomyCategory::Scientific->value,
            ])
            ->assertHasTableActionErrors(['name' => 'A topic tag with this name already exists.']);
    }

    public function test_admin_can_deactivate_active_topic_tag_per_uc_3_4(): void
    {
        $tag = TopicTag::create([
            'name' => 'Retired Activity',
            'category' => 'community',
            'is_active' => true,
        ]);

        Livewire::actingAs($this->admin)
            ->test(TopicTags::class)
            ->callTableAction('deactivate', $tag)
            ->assertHasNoTableActionErrors();

        $this->assertFalse((bool) $tag->fresh()->is_active);
    }

    public function test_admin_can_activate_inactive_topic_tag_per_uc_3_4(): void
    {
        $tag = TopicTag::create([
            'name' => 'Re-enabled Activity',
            'category' => 'community',
            'is_active' => false,
        ]);

        Livewire::actingAs($this->admin)
            ->test(TopicTags::class)
            ->callTableAction('activate', $tag)
            ->assertHasNoTableActionErrors();

        $this->assertTrue((bool) $tag->fresh()->is_active);
    }

    public function test_deactivate_action_is_hidden_for_inactive_topic_tag(): void
    {
        $tag = TopicTag::create([
            'name' => 'Inactive Tag',
            'category' => 'sports',
            'is_active' => false,
        ]);

        Livewire::actingAs($this->admin)
            ->test(TopicTags::class)
            ->assertTableActionHidden('deactivate', $tag)
            ->assertTableActionVisible('activate', $tag);
    }

    public function test_student_cannot_access_topic_tags_management(): void
    {
        $student = User::factory()->create([
            'status' => 'active',
            'must_change_password' => false,
        ])->assignRole(UserRole::Student);

        $this->actingAs($student)
            ->get('/admin/topic-tags')
            ->assertRedirect('/');
    }
}

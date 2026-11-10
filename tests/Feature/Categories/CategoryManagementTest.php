<?php

namespace Tests\Feature\Categories;

use App\Enums\Capability;
use App\Enums\CategoryColor;
use App\Enums\UserRole;
use App\Models\Category;
use App\Models\PointTransaction;
use App\Models\TopicTag;
use App\Models\User;
use App\Services\CategoryList;
use App\Services\PointCalculationService;
use Illuminate\Database\QueryException;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Feature\Events\EventTestCase;

/**
 * Task #116 (N6, D10): the categories are a list that changes without a code change. The system
 * admin and the area admin of the University root may add, rename, recolour, retire and restore
 * categories; nobody else may. Renaming changes no stored value, and nothing is ever deleted.
 */
class CategoryManagementTest extends EventTestCase
{
    /** The area admin of the University root: "Manage categories" on the top area. */
    private User $rootAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rootAdmin = $this->makeUser(UserRole::Teacher, 'Root Admin');
        $this->assign($this->rootAdmin, $this->role('University admin', [Capability::View, Capability::CategoriesManage]), $this->university);
    }

    private function category(string $slug): Category
    {
        return Category::query()->where('slug', $slug)->firstOrFail();
    }

    private function add(User $user, string $name, string $color = 'teal')
    {
        return $this->actingAs($user)->from('/categories')->post('/categories', ['name' => $name, 'color' => $color]);
    }

    public function test_the_list_starts_with_the_four_categories_the_code_used_to_name(): void
    {
        $this->assertSame(
            ['academic' => 'Academic', 'scientific' => 'Scientific', 'sports' => 'Sports', 'community' => 'Community'],
            app(CategoryList::class)->allOptions(),
        );
        $this->assertSame(
            [CategoryColor::Violet, CategoryColor::Green, CategoryColor::Orange, CategoryColor::Pink],
            Category::query()->orderBy('id')->get()->pluck('color')->all(),
        );
    }

    public function test_the_system_admin_and_the_area_admin_of_the_university_root_may_manage_the_list(): void
    {
        foreach ([$this->admin, $this->rootAdmin] as $user) {
            $this->actingAs($user)->get('/categories')->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Categories/Index')
                    ->has('categories', 4)
                    ->where('categories.0.slug', 'academic')
                    ->has('colors', count(CategoryColor::cases()))
                    ->where('topics.categories', true));
        }
    }

    public function test_nobody_else_may_manage_the_list(): void
    {
        // The same right further down the tree: categories are shared by every branch.
        $facultyAdmin = $this->makeUser(UserRole::Teacher, 'Faculty Admin');
        $this->assign($facultyAdmin, $this->role('Faculty admin', [Capability::View, Capability::CategoriesManage]), $this->faculty);
        // Every other right on the root, but not this one.
        $rootLead = $this->makeUser(UserRole::Teacher, 'Root Lead');
        $this->assign($rootLead, $this->role('Root lead', array_values(array_filter(Capability::cases(), fn (Capability $c) => $c !== Capability::CategoriesManage))), $this->university);
        // A system admin whose account was deactivated.
        $formerAdmin = $this->makeUser(UserRole::Admin, 'Former Admin', 'inactive');

        $category = $this->category('sports');

        foreach ([$facultyAdmin, $rootLead, $this->makeUser(UserRole::Teacher), $this->student()] as $user) {
            $this->actingAs($user)->getJson('/categories')->assertForbidden();
            $this->actingAs($user)->postJson('/categories', ['name' => 'Robotics', 'color' => 'teal'])->assertForbidden();
            $this->actingAs($user)->putJson("/categories/{$category->id}", ['name' => 'Sport', 'color' => 'orange'])->assertForbidden();
            $this->actingAs($user)->postJson("/categories/{$category->id}/retire")->assertForbidden();
            $this->actingAs($user)->postJson("/categories/{$category->id}/restore")->assertForbidden();
            $this->assertFalse(app(CategoryList::class)->canManage($user));
        }

        // In the browser, the page says so, as every refused page does.
        $this->actingAs($facultyAdmin)->get('/categories')
            ->assertRedirect('/')
            ->assertSessionHas('error', __('errors.no_permission'));

        $this->assertFalse(app(CategoryList::class)->canManage($formerAdmin));
        $this->assertDatabaseMissing('categories', ['name' => 'Robotics']);
        $this->assertSame('Sports', $category->refresh()->name);
        $this->assertFalse($category->isRetired());

        // Signed out: to the login page.
        auth()->logout();
        $this->get('/categories')->assertRedirect('/login');
    }

    public function test_the_right_stops_counting_when_the_top_area_is_archived(): void
    {
        $this->university->update(['archived_at' => now()]);

        $this->assertFalse(app(CategoryList::class)->canManage($this->rootAdmin->fresh()));
        $this->assertTrue(app(CategoryList::class)->canManage($this->admin));
    }

    public function test_the_menu_shows_categories_only_to_people_who_may_manage_the_list(): void
    {
        // The home page opens for everybody (the system admin's menu flag is checked above).
        $this->actingAs($this->rootAdmin)->get('/')
            ->assertInertia(fn (Assert $page) => $page->where('topics.categories', true));

        $teacher = $this->staff([Capability::EventsCreate]);
        $this->actingAs($teacher)->get('/')
            ->assertInertia(fn (Assert $page) => $page->where('topics.categories', false));

        $this->actingAs($this->student())->get('/')
            ->assertInertia(fn (Assert $page) => $page->where('topics.categories', false));
    }

    public function test_every_page_gets_the_list_with_names_colours_and_retired_ones(): void
    {
        $this->category('community')->update(['retired_at' => now()]);

        $this->actingAs($this->student())->get('/events')
            ->assertInertia(fn (Assert $page) => $page
                ->has('categories', 4)
                ->where('categories.0', ['slug' => 'academic', 'name' => 'Academic', 'color' => 'violet', 'retired' => false])
                ->where('categories.3', ['slug' => 'community', 'name' => 'Community', 'color' => 'pink', 'retired' => true]));
    }

    public function test_a_new_category_gets_a_slug_made_from_its_name(): void
    {
        $this->add($this->rootAdmin, '  Student   life ')->assertSessionHasNoErrors()->assertRedirect('/categories');
        $this->add($this->admin, 'Közösségi élet', 'amber')->assertSessionHasNoErrors();
        $this->add($this->admin, '3D printing', 'slate')->assertSessionHasNoErrors();
        $this->add($this->admin, '!!!', 'blue')->assertSessionHasNoErrors();

        $this->assertDatabaseHas('categories', ['slug' => 'student_life', 'name' => 'Student life', 'color' => 'teal', 'retired_at' => null]);
        $this->assertDatabaseHas('categories', ['slug' => 'kozossegi_elet', 'name' => 'Közösségi élet', 'color' => 'amber']);
        $this->assertDatabaseHas('categories', ['slug' => 'category_3d_printing', 'name' => '3D printing']);
        $this->assertDatabaseHas('categories', ['slug' => 'category', 'name' => '!!!']);
        $this->assertSame('Student life', app(CategoryList::class)->name('student_life'));
    }

    public function test_a_slug_that_is_taken_or_would_overwrite_a_total_gets_a_number(): void
    {
        $this->add($this->admin, 'Points')->assertSessionHasNoErrors();
        $this->add($this->admin, 'Total')->assertSessionHasNoErrors();
        $this->add($this->admin, 'Sports!')->assertSessionHasNoErrors();
        $this->add($this->admin, 'Sports?')->assertSessionHasNoErrors();

        $this->assertDatabaseHas('categories', ['slug' => 'points_2', 'name' => 'Points']);
        $this->assertDatabaseHas('categories', ['slug' => 'total_2', 'name' => 'Total']);
        $this->assertDatabaseHas('categories', ['slug' => 'sports_2', 'name' => 'Sports!']);
        $this->assertDatabaseHas('categories', ['slug' => 'sports_3', 'name' => 'Sports?']);
    }

    public function test_names_are_unique_ignoring_case_and_spaces(): void
    {
        $this->add($this->admin, 'sports')->assertSessionHasErrors(['name' => __('categories.validation.name_taken')]);
        $this->add($this->admin, ' ACADEMIC ')->assertSessionHasErrors('name');

        // A retired category keeps its name, and the message says how to get it back.
        $this->category('community')->update(['retired_at' => now()]);
        $this->add($this->admin, 'Community')->assertSessionHasErrors(['name' => __('categories.validation.name_retired', ['name' => 'Community'])]);

        // Renaming into another category's name is refused too; keeping one's own is fine.
        $sports = $this->category('sports');
        $this->actingAs($this->admin)->put("/categories/{$sports->id}", ['name' => 'Academic', 'color' => 'orange'])->assertSessionHasErrors('name');
        $this->actingAs($this->admin)->put("/categories/{$sports->id}", ['name' => 'SPORTS', 'color' => 'orange'])->assertSessionHasNoErrors();

        $this->assertSame(4, Category::query()->count());
        $this->assertSame('SPORTS', $sports->refresh()->name);
    }

    public function test_the_name_and_colour_are_checked(): void
    {
        $this->add($this->admin, '')->assertSessionHasErrors(['name' => __('categories.validation.name_required')]);
        $this->add($this->admin, '   ')->assertSessionHasErrors('name');
        $this->add($this->admin, str_repeat('a', 41))->assertSessionHasErrors('name');
        $this->add($this->admin, 'Robotics', 'rainbow')->assertSessionHasErrors(['color' => __('categories.validation.color_required')]);
        $this->actingAs($this->admin)->post('/categories', ['name' => 'Robotics'])->assertSessionHasErrors('color');

        $this->assertSame(4, Category::query()->count());
        $this->add($this->admin, str_repeat('a', 40))->assertSessionHasNoErrors();
    }

    public function test_renaming_changes_the_name_everywhere_and_no_stored_value(): void
    {
        $tag = TopicTag::create(['name' => 'Football', 'category' => 'sports', 'is_active' => true]);
        $event = $this->makeEvent(['category' => 'sports', 'topic_tag_id' => $tag->id]);
        $student = $this->student();
        $row = PointTransaction::create([
            'student_id' => $student->id, 'event_id' => $event->id, 'semester_id' => $event->semester_id,
            'credited_by_id' => $this->admin->id, 'points' => 7, 'type' => PointTransaction::CREDIT,
            'source' => 'import', 'category' => 'sports',
        ]);
        $sports = $this->category('sports');

        $this->actingAs($this->rootAdmin)->put("/categories/{$sports->id}", ['name' => 'Sport and games', 'color' => 'blue'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertSame('sports', $sports->refresh()->slug);
        $this->assertSame(CategoryColor::Blue, $sports->color);
        $this->assertSame('sports', $tag->refresh()->category);
        $this->assertSame('sports', $event->refresh()->category);
        $this->assertSame('sports', PointTransaction::query()->whereKey($row->id)->value('category'));
        $this->assertSame(7, app(PointCalculationService::class)->studentTotals($student->id)['sports']);

        $this->actingAs($student)->get('/events')
            ->assertInertia(fn (Assert $page) => $page->where('categories.2.name', 'Sport and games')->where('categories.2.color', 'blue'));
    }

    public function test_a_retired_category_stays_on_everything_but_is_offered_for_nothing_new(): void
    {
        $tag = TopicTag::create(['name' => 'Choir', 'category' => 'community', 'is_active' => true]);
        $event = $this->makeEvent(['category' => 'community', 'topic_tag_id' => $tag->id]);
        $community = $this->category('community');

        $this->actingAs($this->rootAdmin)->post("/categories/{$community->id}/retire")
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertTrue($community->refresh()->isRetired());
        $this->assertSame('community', $event->refresh()->category);
        $this->assertSame('community', $tag->refresh()->category);
        $this->assertTrue($tag->is_active);
        $this->assertFalse($tag->isPickable());
        $this->assertSame(['academic', 'scientific', 'sports'], array_keys(app(CategoryList::class)->options()));
        $this->assertArrayHasKey('community', app(CategoryList::class)->options('community'));
        $this->assertSame('Community', app(CategoryList::class)->name('community'));

        // Retiring twice changes nothing.
        $this->actingAs($this->rootAdmin)->post("/categories/{$community->id}/retire")->assertSessionHasNoErrors();
        $this->assertTrue($community->refresh()->isRetired());

        $this->actingAs($this->admin)->get('/categories')->assertInertia(fn (Assert $page) => $page
            ->where('categories.3.retired', true)
            ->where('categories.3.tags', 1)
            ->where('categories.3.events', 1));
    }

    public function test_the_last_category_in_use_cannot_be_retired(): void
    {
        foreach (['academic', 'scientific', 'sports'] as $slug) {
            $this->actingAs($this->admin)->post("/categories/{$this->category($slug)->id}/retire")->assertSessionHasNoErrors();
        }

        $this->actingAs($this->admin)->post("/categories/{$this->category('community')->id}/retire")
            ->assertSessionHasErrors(['category' => __('categories.validation.keep_one_in_use')]);

        $this->assertFalse($this->category('community')->isRetired());
        $this->assertCount(1, app(CategoryList::class)->inUse());
    }

    public function test_a_retired_category_can_be_restored(): void
    {
        $sports = $this->category('sports');
        $sports->update(['retired_at' => now()]);

        $this->actingAs($this->rootAdmin)->post("/categories/{$sports->id}/restore")
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertFalse($sports->refresh()->isRetired());
        $this->assertArrayHasKey('sports', app(CategoryList::class)->options());

        // Restoring one in use changes nothing.
        $this->actingAs($this->rootAdmin)->post("/categories/{$sports->id}/restore")->assertSessionHasNoErrors();
        $this->assertFalse($sports->refresh()->isRetired());
    }

    public function test_a_category_is_never_deleted_and_keeps_its_slug(): void
    {
        $academic = $this->category('academic');

        try {
            $academic->delete();
            $this->fail('A category was deleted.');
        } catch (LogicException) {
        }

        try {
            $academic->update(['slug' => 'scholarly']);
            $this->fail('A slug was changed.');
        } catch (LogicException) {
        }

        $this->assertSame('academic', $academic->fresh()->slug);

        // The database refuses it too, as long as anything uses the category.
        TopicTag::create(['name' => 'Logic', 'category' => 'academic', 'is_active' => true]);
        $this->expectException(QueryException::class);
        Category::query()->whereKey($academic->id)->delete();
    }

    public function test_an_unknown_category_is_not_found(): void
    {
        $this->actingAs($this->admin)->put('/categories/999', ['name' => 'X', 'color' => 'teal'])->assertNotFound();
        $this->actingAs($this->admin)->post('/categories/999/retire')->assertNotFound();
    }
}

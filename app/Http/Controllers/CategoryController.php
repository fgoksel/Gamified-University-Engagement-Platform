<?php

namespace App\Http\Controllers;

use App\Enums\CategoryColor;
use App\Models\Category;
use App\Models\Event;
use App\Models\TopicTag;
use App\Services\CategoryEditor;
use App\Services\CategoryList;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The category list in the main app (Task #116, N6): add, rename, recolour, retire and restore
 * categories without a code change. Not in the admin panel: the area admin of the University root
 * runs it, and the admin panel is for the system admin only (#104). CategoryPolicy decides who.
 */
class CategoryController extends Controller
{
    public function index(CategoryList $categories): Response
    {
        Gate::authorize('manage', Category::class);

        // How much uses each category, so retiring one is an informed choice.
        $tags = TopicTag::query()->selectRaw('category, count(*) as used')->groupBy('category')->pluck('used', 'category');
        $events = Event::query()->whereNotNull('category')->selectRaw('category, count(*) as used')->groupBy('category')->pluck('used', 'category');

        return Inertia::render('Categories/Index', [
            'categories' => $categories->all()->map(fn (Category $category) => [
                'id' => $category->id,
                'slug' => $category->slug,
                'name' => $category->name,
                'color' => $category->color->value,
                'retired' => $category->isRetired(),
                'tags' => (int) ($tags[$category->slug] ?? 0),
                'events' => (int) ($events[$category->slug] ?? 0),
            ])->values()->all(),
            'colors' => collect(CategoryColor::options())
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                ->values()
                ->all(),
            'nameMax' => CategoryEditor::NAME_MAX,
        ]);
    }

    public function store(Request $request, CategoryEditor $editor): RedirectResponse
    {
        Gate::authorize('manage', Category::class);

        $data = $this->validated($request);
        $category = $editor->create($data['name'], CategoryColor::from($data['color']));

        return back()->with('success', __('categories.flash.added', ['name' => $category->name]));
    }

    public function update(Request $request, Category $category, CategoryEditor $editor): RedirectResponse
    {
        Gate::authorize('manage', Category::class);

        $data = $this->validated($request);
        $editor->update($category, $data['name'], CategoryColor::from($data['color']));

        return back()->with('success', __('categories.flash.saved', ['name' => $category->name]));
    }

    public function retire(Category $category, CategoryEditor $editor): RedirectResponse
    {
        Gate::authorize('manage', Category::class);

        $editor->retire($category);

        return back()->with('success', __('categories.flash.retired', ['name' => $category->name]));
    }

    public function restore(Category $category, CategoryEditor $editor): RedirectResponse
    {
        Gate::authorize('manage', Category::class);

        $editor->restore($category);

        return back()->with('success', __('categories.flash.restored', ['name' => $category->name]));
    }

    /**
     * @return array{name: string, color: string}
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:'.CategoryEditor::NAME_MAX],
            'color' => ['required', 'string', Rule::enum(CategoryColor::class)],
        ], [
            'name.required' => __('categories.validation.name_required'),
            'name.max' => __('categories.validation.name_too_long', ['max' => CategoryEditor::NAME_MAX]),
            'color.required' => __('categories.validation.color_required'),
            'color.enum' => __('categories.validation.color_required'),
        ]);
    }
}

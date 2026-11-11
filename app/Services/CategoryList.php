<?php

namespace App\Services;

use App\Enums\Capability;
use App\Models\Category;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * The one place that reads the category list (Task #116). Nothing in the code names a category:
 * every picker, filter, label and per-category total asks this class, so a category the admin
 * adds shows up everywhere without a code change.
 *
 * "In use" categories are offered for new tags and events. Retired ones are kept so that old
 * tags, events and points still show their name, and so that a total per category still adds
 * up to the points a student has.
 *
 * Who may change the list (N1, N3 and the answer of 10 November): the system admin, who can do
 * everything, and the area admin of the University root, the area that holds the whole tree.
 * In the roles that is the "Manage categories" right, and it only counts on a top area: a
 * category is shared by every branch, so an area admin further down cannot change it.
 *
 * The list is read once per request (it is registered as scoped) and read again after any
 * change (Category::booted()).
 */
class CategoryList
{
    /** @var Collection<int, Category>|null */
    private ?Collection $all = null;

    public function __construct(private TopicAccess $access) {}

    public function flush(): void
    {
        $this->all = null;
    }

    /**
     * Every category, in use and retired, in the order they were added.
     *
     * @return Collection<int, Category>
     */
    public function all(): Collection
    {
        return $this->all ??= Category::query()->orderBy('id')->get();
    }

    /**
     * The categories offered for new tags and events.
     *
     * @return Collection<int, Category>
     */
    public function inUse(): Collection
    {
        return $this->all()->reject(fn (Category $category) => $category->isRetired())->values();
    }

    public function find(?string $slug): ?Category
    {
        return $slug === null ? null : $this->all()->firstWhere('slug', $slug);
    }

    /**
     * The name to show for a stored slug; null when there is no category.
     */
    public function name(?string $slug): ?string
    {
        return $this->find($slug)?->name;
    }

    public function isInUse(?string $slug): bool
    {
        $category = $this->find($slug);

        return $category !== null && ! $category->isRetired();
    }

    /**
     * slug => name for a picker: the categories in use, and the one a record already has even
     * when it was retired since, so editing the record does not change it by accident.
     *
     * @return array<string, string>
     */
    public function options(?string $keep = null): array
    {
        return $this->all()
            ->filter(fn (Category $category) => ! $category->isRetired() || $category->slug === $keep)
            ->mapWithKeys(fn (Category $category) => [$category->slug => $category->name])
            ->all();
    }

    /**
     * slug => name of every category, retired ones too: for filters over records that may
     * have any of them.
     *
     * @return array<string, string>
     */
    public function allOptions(): array
    {
        return $this->all()->mapWithKeys(fn (Category $category) => [$category->slug => $category->name])->all();
    }

    /**
     * slug => 0 for every category, in list order: where a total per category starts, so it has
     * a place for each one, retired ones included.
     *
     * @return array<string, int>
     */
    public function zeroes(): array
    {
        return $this->all()->mapWithKeys(fn (Category $category) => [$category->slug => 0])->all();
    }

    /**
     * The validation rule "a category in use".
     */
    public function inUseRule(): Exists
    {
        return Rule::exists('categories', 'slug')->whereNull('retired_at');
    }

    /**
     * The list as every page gets it (HandleInertiaRequests), so a screen shows a stored slug with
     * its name and colour without a list of its own.
     *
     * @return list<array{slug: string, name: string, color: string, retired: bool}>
     */
    public function shared(): array
    {
        return $this->all()->map(fn (Category $category) => [
            'slug' => $category->slug,
            'name' => $category->name,
            'color' => $category->color->value,
            'retired' => $category->isRetired(),
        ])->values()->all();
    }

    /**
     * Whether the person may add, rename, recolour, retire and restore categories.
     */
    public function canManage(User $user): bool
    {
        if ($this->access->isSystemAdmin($user)) {
            return true;
        }

        $holding = $this->access->topicIdsHolding($user, Capability::CategoriesManage);

        if ($holding === []) {
            return false;
        }

        // Only an assignment on a top area counts, and only while that area is in use.
        return Topic::query()
            ->whereIn('id', $holding)
            ->whereNull('parent_id')
            ->notArchived()
            ->exists();
    }
}

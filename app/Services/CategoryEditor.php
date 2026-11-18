<?php

namespace App\Services;

use App\Enums\CategoryColor;
use App\Models\Category;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Changes to the category list (Task #116): add a category, rename or recolour it, retire it and
 * bring it back. Who may do it is CategoryPolicy; this class keeps the list sound:
 *
 *   - Names are unique, ignoring case and extra spaces, retired categories included, so two
 *     categories never look the same on a screen.
 *   - The slug is made once from the first name and never changes. It is what tags, events and
 *     ledger rows store, so a rename changes no record and no point.
 *   - Nothing is deleted. A retired category stays on everything that has it and its points
 *     still count; it is only no longer offered for new tags, events or tag requests.
 *   - At least one category stays in use, so a tag can always be given one.
 */
class CategoryEditor
{
    /** The longest name: a category has to fit on a chip. */
    public const NAME_MAX = 40;

    /** The longest slug (the column holds 40), with room for a "_2" to tell slugs apart. */
    private const SLUG_MAX = 36;

    /**
     * Slugs a category may not get. The standings and the totals per category put each slug
     * next to these fields (PointCalculationService, ApplicantReviewService, OwnLeaderboards),
     * so a category called "Points" or "Rank" must not overwrite them.
     *
     * @var list<string>
     */
    public const RESERVED = [
        'categories', 'first_name', 'id', 'is_you', 'last_name', 'leader', 'name', 'neptun_code',
        'next_rank', 'none', 'points', 'position', 'rank', 'student_id', 'students', 'to_next', 'total', 'you',
    ];

    /**
     * @throws ValidationException
     */
    public function create(string $name, CategoryColor $color): Category
    {
        $name = $this->clean($name);

        try {
            return DB::transaction(function () use ($name, $color) {
                $this->ensureNameIsFree($name);

                return Category::create([
                    'slug' => $this->slugFor($name),
                    'name' => $name,
                    'color' => $color,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // Someone added the same name at the same moment, or MySQL compares it the same.
            throw $this->nameTaken();
        }
    }

    /**
     * A new name or colour. The slug stays, so nothing that has the category changes.
     *
     * @throws ValidationException
     */
    public function update(Category $category, string $name, CategoryColor $color): Category
    {
        $name = $this->clean($name);

        try {
            return DB::transaction(function () use ($category, $name, $color) {
                $this->ensureNameIsFree($name, $category);

                $category->update(['name' => $name, 'color' => $color]);

                return $category;
            });
        } catch (UniqueConstraintViolationException) {
            throw $this->nameTaken();
        }
    }

    /**
     * No longer offered for anything new. Refused for the last category in use.
     *
     * @throws ValidationException
     */
    public function retire(Category $category): Category
    {
        return DB::transaction(function () use ($category) {
            // Locked, so two people retiring the last two at the same moment cannot both succeed.
            $inUse = Category::query()->inUse()->lockForUpdate()->get(['id']);
            $category->refresh();

            if ($category->isRetired()) {
                return $category;
            }

            if ($inUse->count() <= 1) {
                throw ValidationException::withMessages([
                    'category' => __('categories.validation.keep_one_in_use'),
                ]);
            }

            $category->update(['retired_at' => now()]);

            return $category;
        });
    }

    /**
     * Offered again, as before it was retired.
     */
    public function restore(Category $category): Category
    {
        if ($category->isRetired()) {
            $category->update(['retired_at' => null]);
        }

        return $category;
    }

    /**
     * Spaces at the ends dropped and runs of spaces made one, as the name is shown.
     */
    private function clean(string $name): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $name));
    }

    /**
     * @throws ValidationException
     */
    private function ensureNameIsFree(string $name, ?Category $except = null): void
    {
        $wanted = mb_strtolower($name);

        $taken = Category::query()
            ->when($except, fn ($query) => $query->whereKeyNot($except->id))
            ->get(['id', 'name', 'retired_at'])
            ->first(fn (Category $category) => mb_strtolower($category->name) === $wanted);

        if ($taken !== null) {
            throw $taken->isRetired()
                ? ValidationException::withMessages(['name' => __('categories.validation.name_retired', ['name' => $taken->name])])
                : $this->nameTaken();
        }
    }

    private function nameTaken(): ValidationException
    {
        return ValidationException::withMessages(['name' => __('categories.validation.name_taken')]);
    }

    /**
     * Lower case letters, digits and "_", starting with a letter: "Student life" becomes
     * "student_life" and "Közösség" becomes "kozosseg". A slug already used, or reserved, gets
     * "_2", "_3" and so on.
     */
    private function slugFor(string $name): string
    {
        $base = (string) preg_replace('/[^a-z0-9_]/', '', Str::slug($name, '_'));

        if (! preg_match('/^[a-z]/', $base)) {
            $base = rtrim('category_'.$base, '_');
        }

        $base = rtrim(substr($base, 0, self::SLUG_MAX), '_');
        $taken = Category::query()->pluck('slug')->all();

        $slug = $base;
        for ($number = 2; in_array($slug, $taken, true) || in_array($slug, self::RESERVED, true); $number++) {
            $slug = "{$base}_{$number}";
        }

        return $slug;
    }
}

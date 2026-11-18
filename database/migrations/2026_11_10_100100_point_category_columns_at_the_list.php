<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The categories that the columns allowed before the list existed. */
    private const FIXED = ['academic', 'scientific', 'sports', 'community'];

    /**
     * Each table with a category column, and whether the column may stay empty.
     *
     * An event no longer needs a category (D10, 7 November), so neither do the ledger rows
     * that copy it. A tag still sits under a category, and so does a tag request.
     *
     * @var array<string, bool>
     */
    private const COLUMNS = [
        'subject_areas' => false,
        'topic_tags' => false,
        'topic_tag_requests' => false,
        'events' => true,
        'point_transactions' => true,
    ];

    /**
     * The other fixed-value columns of these tables: name => [values, default].
     *
     * SQLite cannot change a column in place, so Laravel copies the table into a new one, and the
     * copy loses the checks that hold these columns to their values. They are declared again in
     * the same step, so the tables keep refusing a status or type that does not exist. MySQL
     * changes the category column alone and keeps them.
     *
     * @var array<string, array<string, array{0: list<string>, 1: ?string}>>
     */
    private const OTHER_ENUMS = [
        'topic_tag_requests' => ['status' => [['pending', 'approved', 'rejected'], 'pending']],
        'events' => [
            'application_type' => [['fcfs', 'teacher_select'], null],
            'status' => [['draft', 'published', 'on_hold', 'completed', 'cancelled'], 'draft'],
        ],
        'point_transactions' => ['type' => [['credit', 'reversal'], 'credit']],
    ];

    /**
     * The category columns stop being a fixed list of four values and point at the categories
     * table instead (Task #116). The stored values stay as they are: they are the slugs of the
     * four categories the table starts with. The foreign key keeps every value a real category,
     * and it refuses to remove a category that something still uses.
     */
    public function up(): void
    {
        foreach (self::COLUMNS as $table => $nullable) {
            Schema::table($table, function (Blueprint $blueprint) use ($table, $nullable) {
                $blueprint->string('category', 40)->nullable($nullable)->change();
                $blueprint->foreign('category')->references('slug')->on('categories')->restrictOnDelete();
                $this->keepOtherChecks($blueprint, $table);
            });
        }
    }

    /**
     * On SQLite, declare the table's other fixed-value columns again (see OTHER_ENUMS).
     */
    private function keepOtherChecks(Blueprint $blueprint, string $table): void
    {
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            return;
        }

        foreach (self::OTHER_ENUMS[$table] ?? [] as $column => [$values, $default]) {
            $definition = $blueprint->enum($column, $values);

            if ($default !== null) {
                $definition->default($default);
            }

            $definition->change();
        }
    }

    /**
     * Back to the four fixed values. Only possible while nothing uses a category that was added
     * later, and while every event and ledger row still has one: refused otherwise, so going back
     * never loses a category.
     */
    public function down(): void
    {
        foreach (self::COLUMNS as $table => $nullable) {
            $unfit = DB::table($table)
                ->where(fn ($query) => $query->whereNull('category')->orWhereNotIn('category', self::FIXED))
                ->count();

            if ($unfit > 0) {
                throw new RuntimeException("{$unfit} rows of {$table} have no category or one added after the four fixed ones, so the column cannot go back to the fixed list.");
            }
        }

        foreach (array_reverse(self::COLUMNS, true) as $table => $nullable) {
            // MySQL made an index for the foreign key, and dropping the key leaves it behind.
            $keyIndex = "{$table}_category_foreign";
            $dropKeyIndex = Schema::hasIndex($table, $keyIndex);

            Schema::table($table, function (Blueprint $blueprint) use ($table, $keyIndex, $dropKeyIndex) {
                $blueprint->dropForeign(['category']);

                if ($dropKeyIndex) {
                    $blueprint->dropIndex($keyIndex);
                }

                $blueprint->enum('category', self::FIXED)->change();
                $this->keepOtherChecks($blueprint, $table);
            });
        }
    }
};

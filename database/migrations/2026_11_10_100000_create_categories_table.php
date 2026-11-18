<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The categories become a list that can change without changing the code (Task #116, N6 and
     * D10). It starts with the four categories that used to be written into the code, with the
     * same slugs, so every tag, event and ledger row keeps the category it had.
     *
     * The slug is what other tables store, and it never changes: renaming a category changes
     * its name only. A category that is no longer wanted is retired, never deleted, so the
     * points already given in it keep it.
     */
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 40)->unique();
            $table->string('name', 40)->unique();
            // One of App\Enums\CategoryColor.
            $table->string('color', 20);
            // Set when the category is retired: kept for the history, offered for nothing new.
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();
        });

        $now = now();

        DB::table('categories')->insert([
            ['slug' => 'academic', 'name' => 'Academic', 'color' => 'violet', 'created_at' => $now, 'updated_at' => $now],
            ['slug' => 'scientific', 'name' => 'Scientific', 'color' => 'green', 'created_at' => $now, 'updated_at' => $now],
            ['slug' => 'sports', 'name' => 'Sports', 'color' => 'orange', 'created_at' => $now, 'updated_at' => $now],
            ['slug' => 'community', 'name' => 'Community', 'color' => 'pink', 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};

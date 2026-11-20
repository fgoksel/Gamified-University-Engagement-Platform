<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Where am I?" (Task #132): in an area with no leaderboard, its students see their own place,
 * unless someone who manages the area's leaderboards turns that off for the area. On by default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('topics', function (Blueprint $table) {
            $table->boolean('show_own_place')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('topics', function (Blueprint $table) {
            $table->dropColumn('show_own_place');
        });
    }
};

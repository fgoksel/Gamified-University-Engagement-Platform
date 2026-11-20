<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Names on the hall screen (Task #118, N13 of 9 November). Guests always see Neptun codes. The
     * area admin chooses which name columns a leaderboard has; this switch is the system admin's
     * and decides, board by board, whether those names also reach the hall screen. Off by default
     * (agreed on 10 November), so every existing board stops sending names to guests until the
     * system admin turns them on.
     */
    public function up(): void
    {
        Schema::table('leaderboards', function (Blueprint $table) {
            $table->boolean('hall_show_names')->default(false)->after('hall_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('leaderboards', function (Blueprint $table) {
            $table->dropColumn('hall_show_names');
        });
    }
};

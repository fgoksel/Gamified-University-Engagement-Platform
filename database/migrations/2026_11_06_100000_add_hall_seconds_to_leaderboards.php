<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Time on the hall screen (Task #56): each leaderboard may set its own seconds, and "settings"
     * holds the one row of system-wide values, here the default the super admin chooses.
     */
    public function up(): void
    {
        Schema::table('leaderboards', function (Blueprint $table) {
            // Empty: the default in settings.
            $table->unsignedSmallInteger('hall_seconds')->nullable()->after('show_last_name');
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('hall_seconds')->default(15);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');

        Schema::table('leaderboards', function (Blueprint $table) {
            $table->dropColumn('hall_seconds');
        });
    }
};

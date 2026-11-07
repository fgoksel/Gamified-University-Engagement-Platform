<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Highlighted leaderboards (D6, 7 November): a person stars a few leaderboards and they are
     * listed first. One row per person and leaderboard. It is a personal setting, so it goes away
     * with the account and with the leaderboard.
     */
    public function up(): void
    {
        Schema::create('leaderboard_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leaderboard_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'leaderboard_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leaderboard_user');
    }
};

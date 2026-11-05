<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Area leaderboards (Task #89). The points are not stored here: a leaderboard always
     * sums the ledger for the events owned in its topic and every topic below it (L1, 27 October).
     */
    public function up(): void
    {
        Schema::create('leaderboards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('topic_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            // public: seen without login and on the hall screen. area: only by people who can open the topic (L4).
            $table->enum('visibility', ['area', 'public'])->default('area');
            // The Neptun code alone by default; names only when the creator ticks them (L6).
            $table->boolean('show_neptun_code')->default(true);
            $table->boolean('show_first_name')->default(false);
            $table->boolean('show_last_name')->default(false);
            $table->foreignId('created_by_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['topic_id', 'name']);
            $table->index('visibility');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leaderboards');
    }
};

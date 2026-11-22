<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Badges (Task #103, N10): an achievement that works like an event with no registering. The
     * first kind hangs on an event: a student who gets the event's points gets its badge.
     *
     * Neither table touches the points ledger, so a badge cannot change anybody's points or rank.
     */
    public function up(): void
    {
        Schema::create('badges', function (Blueprint $table) {
            $table->id();
            // An event has at most one badge. A draft event that is deleted takes its badge with it.
            $table->foreignId('event_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('description', 200)->nullable();
            // For the badge icons (Task #125): a short key such as "star". Empty until then.
            $table->string('icon', 40)->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('badge_awards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('badge_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('awarded_at')->useCurrent();
            // Once: however the student meets the condition, the badge is theirs one time.
            $table->unique(['badge_id', 'student_id']);
            $table->index('student_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('badge_awards');
        Schema::dropIfExists('badges');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every saved edit of an event (Task #76: "edits are logged"): who changed it, when, and
     * which fields went from what to what. One row per save that changed something.
     */
    public function up(): void
    {
        Schema::create('event_edits', function (Blueprint $table) {
            $table->id();
            // A deleted event (only ever one nobody applied to) takes its edit history with it.
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            // Kept even if the account goes away later; the row then says "someone".
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            // {field: {old, new}} for each field that changed.
            $table->json('changes');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['event_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_edits');
    }
};

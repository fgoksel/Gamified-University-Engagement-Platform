<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Printed slips are made in batches (Task #84): the organiser asks for a number of slips,
     * and that batch prints on its own, so a second batch never prints the first one again.
     * A printed copy of a slip that was already handed out would be a second paper copy of
     * a code that works only once.
     *
     * No value (null) is a code made before batches: posters, the parts of a task, and slips,
     * which are printed together as "earlier slips".
     */
    public function up(): void
    {
        Schema::table('qr_tokens', function (Blueprint $table) {
            $table->uuid('batch')->nullable()->after('type');
            $table->index(['event_id', 'batch']);
        });
    }

    public function down(): void
    {
        Schema::table('qr_tokens', function (Blueprint $table) {
            $table->dropIndex(['event_id', 'batch']);
            $table->dropColumn('batch');
        });
    }
};

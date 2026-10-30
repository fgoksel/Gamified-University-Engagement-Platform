<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Three date windows for each event (Task #78, meeting of 26 Oct): applications, the event
     * itself and the points. Applications now open on a date the organiser may set and may close
     * any time up to the end of the event. The points window already has its end in
     * completion_deadline and starts when the event starts.
     *
     * No value (null) means "open from publishing", which is how every existing event worked,
     * so nothing changes for them.
     */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dateTime('applications_open_at')->nullable()->after('event_ends_at');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('applications_open_at');
        });
    }
};

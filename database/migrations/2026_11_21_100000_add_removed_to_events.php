<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The statuses an event had before the system admin could remove one. */
    private const BEFORE = ['draft', 'published', 'on_hold', 'completed', 'cancelled'];

    /**
     * Event moderation (Task #58, UC-3.5): the system admin removes an event for good reason. A
     * removed event is a status of its own, so every list that only shows the statuses it knows
     * leaves it out, and the event keeps its row: its applications, QR codes and ledger rows stay
     * (the ledger is never edited), and the database refuses to delete an event that has them.
     * Who removed it, when and why is kept on the event, as for a cancelled one.
     */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->timestamp('removed_at')->nullable()->after('cancellation_reason');
            $table->foreignId('removed_by_id')->nullable()->after('removed_at')->constrained('users')->nullOnDelete();
            $table->string('removal_reason', 500)->nullable()->after('removed_by_id');
        });

        $this->statusAllows([...self::BEFORE, 'removed']);
    }

    /**
     * Back to the five old statuses. Refused while an event is removed, so going back never loses
     * the fact that it was.
     */
    public function down(): void
    {
        $removed = DB::table('events')->where('status', 'removed')->count();

        if ($removed > 0) {
            throw new RuntimeException("{$removed} events are removed, so the status column cannot go back to the five old values.");
        }

        // The columns go first: on SQLite dropping a column copies the table again, which would lose
        // the checks that the step after it puts back.
        Schema::table('events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('removed_by_id');
            $table->dropColumn(['removed_at', 'removal_reason']);
        });

        $this->statusAllows(self::BEFORE);
    }

    /**
     * @param  list<string>  $statuses
     */
    private function statusAllows(array $statuses): void
    {
        Schema::table('events', function (Blueprint $table) use ($statuses) {
            $table->enum('status', $statuses)->default('draft')->change();

            // SQLite cannot change a column in place, so Laravel copies the table into a new one, and
            // the copy loses the check that holds the other fixed-value column to its values. It is
            // declared again in the same step, as in the migration of the category list (Task #116).
            // MySQL changes the status alone and keeps it.
            if (Schema::getConnection()->getDriverName() === 'sqlite') {
                $table->enum('application_type', ['fcfs', 'teacher_select'])->change();
            }
        });
    }
};

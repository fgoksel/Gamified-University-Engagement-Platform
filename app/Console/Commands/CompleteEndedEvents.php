<?php

namespace App\Console\Commands;

use App\Models\Event;
use Illuminate\Console\Command;

/**
 * Events become Completed when they end, without a manual step (Task #101, team decision of
 * 27 October, G16 in missing.md). The scheduler runs this every minute (routes/console.php).
 *
 * Only published events change. A draft never went out, an event on hold waits for its
 * organiser to restore it with a new date (#122), and a cancelled one stays cancelled.
 * Completed changes the label and nothing else: points can still be given until the points
 * end date (#78), as before, and every page treats a completed event like an ended one.
 */
class CompleteEndedEvents extends Command
{
    protected $signature = 'events:complete';

    protected $description = 'Mark published events that have ended as completed';

    public function handle(): int
    {
        // One UPDATE, so an event that ends while this runs is simply taken next time.
        $completed = Event::query()
            ->where('status', Event::STATUS_PUBLISHED)
            ->where('event_ends_at', '<', now())
            ->update(['status' => Event::STATUS_COMPLETED, 'updated_at' => now()]);

        $this->info("{$completed} ended events marked completed.");

        return self::SUCCESS;
    }
}

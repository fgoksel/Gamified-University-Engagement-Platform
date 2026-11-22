<?php

namespace App\Jobs;

use App\Enums\UserRole;
use App\Models\RoleAssignment;
use App\Models\Topic;
use App\Models\User;
use App\Services\AssignmentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * "Also add the active students who are not in this area yet" (Task #133): when an area admin
 * switches an area to general and ticks it, the students who are already active join it too, in
 * chunks, through the same AssignmentService::joinGeneralArea() a new activation uses.
 */
class JoinGeneralAreaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const CHUNK = 200;

    public function __construct(public int $topicId) {}

    /**
     * The active students who do not hold the area's general role there yet: who the job adds,
     * and the number the dialog shows before it is ticked.
     *
     * @return Builder<User>
     */
    public static function missing(Topic $topic, int $roleId): Builder
    {
        return User::role(UserRole::Student)
            ->where('status', 'active')
            ->whereNotIn('id', RoleAssignment::query()->active()
                ->where(['topic_id' => $topic->id, 'role_definition_id' => $roleId])
                ->select('user_id'));
    }

    public function handle(AssignmentService $assignments): void
    {
        $topic = Topic::query()->general()->find($this->topicId);

        // Switched off or archived in the meantime: nobody joins.
        if ($topic === null) {
            return;
        }

        self::missing($topic, $topic->general_role_id)
            ->chunkById(self::CHUNK, function ($students) use ($assignments, $topic) {
                foreach ($students as $student) {
                    $assignments->joinGeneralArea($topic, $student);
                }
            });
    }
}

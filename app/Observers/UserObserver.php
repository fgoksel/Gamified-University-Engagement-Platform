<?php

namespace App\Observers;

use App\Enums\UserRole;
use App\Models\Topic;
use App\Models\User;
use App\Services\AssignmentService;
use Illuminate\Support\Facades\DB;

/**
 * General areas (Task #133, #128 Q3): when a student's account becomes active, by the activation
 * link or by an admin's "Activate", the student joins every general area with its role. Course
 * areas are not touched: students join those by import.
 *
 * It runs after the commit, so an activation that is rolled back adds nothing, and the account's
 * role (given right after it is created) is there to check.
 */
class UserObserver
{
    public function created(User $user): void
    {
        if ($user->status === 'active') {
            $this->joinAfterCommit($user);
        }
    }

    public function updated(User $user): void
    {
        if ($user->wasChanged('status') && $user->status === 'active') {
            $this->joinAfterCommit($user);
        }
    }

    private function joinAfterCommit(User $user): void
    {
        DB::afterCommit(function () use ($user) {
            $student = User::query()->find($user->id);

            // Only students: an organiser or admin account that becomes active joins nothing.
            if ($student === null || $student->status !== 'active' || ! $student->hasRole(UserRole::Student)) {
                return;
            }

            $assignments = app(AssignmentService::class);

            foreach (Topic::query()->general()->get() as $topic) {
                $assignments->joinGeneralArea($topic, $student);
            }
        });
    }
}

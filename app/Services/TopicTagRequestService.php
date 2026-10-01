<?php

namespace App\Services;

use App\Models\TopicTag;
use App\Models\TopicTagRequest;
use App\Models\User;
use App\Notifications\TopicTagRequestApprovedNotification;
use App\Notifications\TopicTagRequestRejectedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Service handling Topic Tag Request lifecycle resolution (UC-3.4, Technical Specification Table 22).
 */
class TopicTagRequestService
{
    /**
     * Approve a pending topic tag request:
     * - Validates that no existing topic tag shares the proposed name.
     * - Creates the active TopicTag record.
     * - Marks the request as approved.
     * - Notifies the requesting teacher.
     *
     * @throws ValidationException
     */
    public function approve(TopicTagRequest $request, User $admin): TopicTag
    {
        if ($request->status !== 'pending') {
            throw ValidationException::withMessages([
                'status' => 'This topic tag request has already been resolved.',
            ]);
        }

        // UC-3.4 exception: "Duplicate Topic Tag Name: Approving a request whose Proposed Name matches an existing Topic Tag displays error: 'A topic tag with this name already exists.'"
        if (TopicTag::where('name', $request->proposed_name)->exists()) {
            throw ValidationException::withMessages([
                'proposed_name' => 'A topic tag with this name already exists.',
            ]);
        }

        return DB::transaction(function () use ($request, $admin) {
            $topicTag = TopicTag::create([
                'name' => $request->proposed_name,
                'category' => $request->category,
                'description' => $request->justification,
                'is_active' => true,
            ]);

            $request->update([
                'status' => 'approved',
                'resolved_by_id' => $admin->id,
                'resolved_topic_tag_id' => $topicTag->id,
            ]);

            $teacher = $request->requestedBy;
            if ($teacher) {
                $teacher->notify(new TopicTagRequestApprovedNotification(
                    proposedName: $request->proposed_name,
                    category: (string) $request->category
                ));
            }

            return $topicTag;
        });
    }

    /**
     * Reject a pending topic tag request:
     * - Records the optional rejection reason.
     * - Marks the request as rejected.
     * - Notifies the requesting teacher with the reason.
     *
     * @throws ValidationException
     */
    public function reject(TopicTagRequest $request, User $admin, ?string $reason = null): void
    {
        if ($request->status !== 'pending') {
            throw ValidationException::withMessages([
                'status' => 'This topic tag request has already been resolved.',
            ]);
        }

        DB::transaction(function () use ($request, $admin, $reason) {
            $request->update([
                'status' => 'rejected',
                'resolved_by_id' => $admin->id,
                'rejection_reason' => $reason,
            ]);

            $teacher = $request->requestedBy;
            if ($teacher) {
                $teacher->notify(new TopicTagRequestRejectedNotification(
                    proposedName: $request->proposed_name,
                    reason: $reason
                ));
            }
        });
    }
}

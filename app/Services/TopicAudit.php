<?php

namespace App\Services;

use App\Models\TopicAuditEvent;
use App\Models\User;

/**
 * Writes the audit trail of structure, role and access changes.
 */
class TopicAudit
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public static function record(?User $actor, string $action, ?int $topicId, string $subjectType, int $subjectId, ?array $before = null, ?array $after = null): void
    {
        TopicAuditEvent::create([
            'actor_id' => $actor?->id,
            'action' => $action,
            'topic_id' => $topicId,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'before' => $before,
            'after' => $after,
            'created_at' => now(),
        ]);
    }
}

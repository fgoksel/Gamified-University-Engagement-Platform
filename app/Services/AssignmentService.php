<?php

namespace App\Services;

use App\Enums\Capability;
use App\Models\RoleAssignment;
use App\Models\RoleDefinition;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The only place that writes role assignments.
 *
 * Giving a role is one step. Ending a role, or handing it to a replacement,
 * is two: prepare() checks everything and returns the summary for the
 * confirmations plus a signed, short-lived token; execute() redeems the
 * token and checks everything again inside one transaction on the locked
 * row. Cancelling means never calling execute(). An assignment is never
 * deleted, and a person leaving never ends what they granted.
 */
class AssignmentService
{
    public function __construct(private TopicAccess $access) {}

    /**
     * @throws AuthorizationException|ValidationException
     */
    public function grant(User $actor, Topic $topic, RoleDefinition $role, User $recipient, ?int $semesterId = null): RoleAssignment
    {
        Gate::forUser($actor)->authorize('grant', [RoleAssignment::class, $topic, $role, $recipient]);

        try {
            return DB::transaction(function () use ($actor, $topic, $role, $recipient, $semesterId) {
                // Lock first: reads that follow then see the latest committed data.
                $role = RoleDefinition::query()->whereKey($role->id)->lockForUpdate()->firstOrFail();
                $actor = User::query()->findOrFail($actor->id);
                $recipient = User::query()->whereKey($recipient->id)->lockForUpdate()->firstOrFail();
                $this->access->flush();
                Gate::forUser($actor)->authorize('grant', [RoleAssignment::class, $topic, $role, $recipient]);

                if ($role->isArchived()) {
                    throw ValidationException::withMessages(['role_definition_id' => 'This role is archived and cannot be given to anyone new.']);
                }

                $this->assertEligibleRecipient($recipient);

                if (RoleAssignment::query()->active()->where(['user_id' => $recipient->id, 'role_definition_id' => $role->id, 'topic_id' => $topic->id])->exists()) {
                    throw ValidationException::withMessages(['user_id' => "{$recipient->name} already has this role here."]);
                }

                $assignment = $this->create($actor, $topic, $role, $recipient, $semesterId === null ? [] : ['semester_id' => $semesterId]);

                TopicAudit::record($actor, 'assignment.granted', $topic->id, 'role_assignment', $assignment->id, null, $this->snapshot($assignment));

                return $assignment;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['user_id' => 'This person already has this role here.']);
        }
    }

    /**
     * Check a removal or handover and return what the confirmations show.
     *
     * @return array{token: string, summary: array<string, mixed>}
     *
     * @throws AuthorizationException|ValidationException
     */
    public function prepare(User $actor, RoleAssignment $assignment, ?User $replacement, string $reason): array
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Give a reason for the change.']);
        }

        if (mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'The reason can be at most 500 characters.']);
        }

        // Always work on the stored row, so the version in the token is the real one.
        $assignment = RoleAssignment::query()->with(['role', 'topic', 'user'])->findOrFail($assignment->id);

        $this->check($actor, $assignment, $replacement);

        $token = Crypt::encryptString(json_encode([
            'actor' => $actor->id,
            'assignment' => $assignment->id,
            'version' => $assignment->version,
            'replacement' => $replacement?->id,
            'reason' => $reason,
            'expires' => now()->addMinutes((int) config('topics.confirmation_minutes'))->timestamp,
        ]));

        return ['token' => $token, 'summary' => $this->summary($assignment, $replacement, $reason)];
    }

    /**
     * Carry out a prepared removal or handover.
     *
     * @return array{ended: RoleAssignment, replacement: ?RoleAssignment}
     *
     * @throws AuthorizationException|ValidationException
     */
    public function execute(User $actor, string $token): array
    {
        $payload = $this->open($actor, $token);

        try {
            return DB::transaction(function () use ($actor, $payload) {
                // Lock the row first: a second submission waits here and then finds it ended.
                $assignment = RoleAssignment::query()->whereKey($payload['assignment'])->lockForUpdate()->firstOrFail();
                $actor = User::query()->findOrFail($actor->id);
                $this->access->flush();

                if (! $assignment->isActive()) {
                    throw ValidationException::withMessages(['confirmation' => 'This role was already ended. Nothing was changed.']);
                }

                if ($assignment->version !== $payload['version']) {
                    throw ValidationException::withMessages(['confirmation' => 'This role changed after you opened it. Nothing was changed; please start again.']);
                }

                $assignment->load(['role', 'topic', 'user']);
                $replacement = $payload['replacement'] === null
                    ? null
                    : User::query()->whereKey($payload['replacement'])->lockForUpdate()->firstOrFail();

                $this->check($actor, $assignment, $replacement);

                $before = $this->snapshot($assignment);
                $new = null;

                if ($replacement !== null) {
                    $new = $this->create($actor, $assignment->topic, $assignment->role, $replacement, ['replaces_assignment_id' => $assignment->id]);
                }

                $assignment->update([
                    'ended_at' => now(),
                    'ended_reason' => $payload['reason'],
                    'ended_by_id' => $actor->id,
                    'replaced_by_assignment_id' => $new?->id,
                    'version' => $assignment->version + 1,
                ]);

                TopicAudit::record(
                    $actor,
                    $new === null ? 'assignment.ended' : 'assignment.replaced',
                    $assignment->topic_id,
                    'role_assignment',
                    $assignment->id,
                    $before,
                    [...$this->snapshot($assignment), 'replacement_assignment_id' => $new?->id],
                );

                return ['ended' => $assignment, 'replacement' => $new];
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['confirmation' => 'This change was already made. Nothing was changed.']);
        }
    }

    /**
     * Every rule for ending or replacing, in one place so prepare() and
     * execute() cannot drift apart.
     *
     * @throws AuthorizationException|ValidationException
     */
    private function check(User $actor, RoleAssignment $assignment, ?User $replacement): void
    {
        if (! $assignment->isActive()) {
            throw ValidationException::withMessages(['confirmation' => 'This role was already ended.']);
        }

        if ($replacement === null) {
            Gate::forUser($actor)->authorize('end', $assignment);

            return;
        }

        Gate::forUser($actor)->authorize('replace', [$assignment, $replacement]);

        if ($replacement->id === $assignment->user_id) {
            throw ValidationException::withMessages(['replacement_id' => 'Choose a different person as the replacement.']);
        }

        $this->assertEligibleRecipient($replacement, 'replacement_id');

        if (RoleAssignment::query()->active()->where([
            'user_id' => $replacement->id,
            'role_definition_id' => $assignment->role_definition_id,
            'topic_id' => $assignment->topic_id,
        ])->exists()) {
            throw ValidationException::withMessages(['replacement_id' => "{$replacement->name} already has this role here."]);
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function create(User $actor, Topic $topic, RoleDefinition $role, User $recipient, array $extra = []): RoleAssignment
    {
        $authority = $this->access->isSystemAdmin($actor)
            ? null
            : $this->access->coveringAssignments($actor, $topic)->first(fn (RoleAssignment $a) => $a->role->has(Capability::AccessAssign));

        return RoleAssignment::create([
            'topic_id' => $topic->id,
            'user_id' => $recipient->id,
            'role_definition_id' => $role->id,
            'granted_by_id' => $actor->id,
            'granted_by_assignment_id' => $authority?->id,
            'authority_snapshot' => $authority === null
                ? ['via' => 'system_admin']
                : [
                    'via' => 'assignment',
                    'assignment_id' => $authority->id,
                    'role_definition_id' => $authority->role->id,
                    'role_name' => $authority->role->name,
                    'topic_id' => $authority->topic_id,
                    'capabilities' => $authority->role->capabilityList(),
                    'delegable' => $authority->role->delegableList(),
                ],
            'started_at' => now(),
            ...$extra,
        ]);
    }

    private function assertEligibleRecipient(User $user, string $field = 'user_id'): void
    {
        if ($user->status !== 'active') {
            throw ValidationException::withMessages([$field => 'Only active accounts can be given a role.']);
        }
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function open(User $actor, string $token): array
    {
        try {
            $payload = json_decode(Crypt::decryptString($token), true, 4, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            throw ValidationException::withMessages(['confirmation' => 'The confirmation is not valid. Nothing was changed.']);
        }

        if (($payload['actor'] ?? null) !== $actor->id || ($payload['expires'] ?? 0) < now()->timestamp) {
            throw ValidationException::withMessages(['confirmation' => 'The confirmation expired. Nothing was changed; please start again.']);
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(RoleAssignment $assignment, ?User $replacement, string $reason): array
    {
        return [
            'assignment_id' => $assignment->id,
            'person' => $assignment->user->name,
            'person_inactive' => $assignment->user->status !== 'active',
            'role' => $assignment->role->name,
            'topic' => $assignment->topic->title,
            'topic_id' => $assignment->topic_id,
            'replacement' => $replacement?->name,
            'reason' => $reason,
            'remains' => 'The topic, everything below it, the role definition and other people\'s roles stay as they are. Roles this person gave to others stay in place.',
            'loses' => 'The person loses only the access this role gave. Access from any other role they hold is kept.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(RoleAssignment $assignment): array
    {
        return $assignment->only(['topic_id', 'user_id', 'role_definition_id', 'granted_by_id', 'started_at', 'ended_at', 'ended_reason', 'version']);
    }
}

<?php

namespace App\Services;

use App\Enums\Capability;
use App\Enums\UserRole;
use App\Models\RoleAssignment;
use App\Models\RoleDefinition;
use App\Models\Semester;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
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
        return $this->give('grant', Capability::AccessAssign, $actor, $topic, $role, $recipient, $semesterId);
    }

    /**
     * Give a role to a student listed in an import file (Task #72): the same checks
     * as grant(), with the "import students" capability as the authority.
     *
     * @throws AuthorizationException|ValidationException
     */
    public function import(User $actor, Topic $topic, RoleDefinition $role, User $recipient, ?int $semesterId = null): RoleAssignment
    {
        return $this->give('import', Capability::AccessImport, $actor, $topic, $role, $recipient, $semesterId);
    }

    /**
     * A student joins a general area (Task #133) when their account becomes active: its general
     * role, written like an import (the active semester), but with no acting user's rights check:
     * the area admin passed that check when they switched the area on. The assignment says where it
     * came from (authority_snapshot "via" general_area), and its grantor is whoever switched the
     * area on, so the Access tab and the history can say "joined automatically".
     *
     * Only for an active student and a general area that is not archived; a role already held there
     * is not given twice. Never touches another area, never ends anything.
     *
     * @return RoleAssignment|null null when nothing was added
     */
    public function joinGeneralArea(Topic $topic, User $student): ?RoleAssignment
    {
        $topic = Topic::query()->general()->whereKey($topic->id)->first();
        $role = $topic?->general_role_id === null ? null : RoleDefinition::query()->usable()->find($topic->general_role_id);

        if ($topic === null || $role === null || $student->status !== 'active' || ! $student->hasRole(UserRole::Student)) {
            return null;
        }

        // The role may have gained powers since the area was switched on (someone edited it): then
        // it is no longer given automatically. The area admin sees why in the log.
        if (! $role->isPlainStudentRole()) {
            Log::warning('General area skipped: its role is no longer a plain student role.', [
                'topic_id' => $topic->id,
                'role_definition_id' => $role->id,
                'student_id' => $student->id,
            ]);

            return null;
        }

        try {
            return DB::transaction(function () use ($topic, $role, $student) {
                $held = RoleAssignment::query()->active()
                    ->where(['user_id' => $student->id, 'role_definition_id' => $role->id, 'topic_id' => $topic->id])
                    ->lockForUpdate()
                    ->exists();

                if ($held) {
                    return null;
                }

                $assignment = RoleAssignment::create([
                    'topic_id' => $topic->id,
                    'user_id' => $student->id,
                    'role_definition_id' => $role->id,
                    'granted_by_id' => $topic->general_set_by_id,
                    'authority_snapshot' => ['via' => 'general_area', 'topic_id' => $topic->id, 'set_by_id' => $topic->general_set_by_id],
                    'semester_id' => Semester::where('status', 'active')->orderByDesc('starts_at')->value('id'),
                    'started_at' => now(),
                ]);

                TopicAudit::record(null, 'assignment.joined_general_area', $topic->id, 'role_assignment', $assignment->id, null, $this->snapshot($assignment));

                return $assignment;
            });
        } catch (UniqueConstraintViolationException) {
            // Added at the same moment by another path: they hold it, which is all this wanted.
            return null;
        }
    }

    /**
     * @param  string  $ability  The RoleAssignmentPolicy check that allows it.
     * @param  Capability  $authority  Whose assignment is recorded as the authority.
     *
     * @throws AuthorizationException|ValidationException
     */
    private function give(string $ability, Capability $authority, User $actor, Topic $topic, RoleDefinition $role, User $recipient, ?int $semesterId): RoleAssignment
    {
        Gate::forUser($actor)->authorize($ability, [RoleAssignment::class, $topic, $role, $recipient]);

        try {
            return DB::transaction(function () use ($ability, $authority, $actor, $topic, $role, $recipient, $semesterId) {
                // Lock first: reads that follow then see the latest committed data.
                $role = RoleDefinition::query()->whereKey($role->id)->lockForUpdate()->firstOrFail();
                $actor = User::query()->findOrFail($actor->id);
                $recipient = User::query()->whereKey($recipient->id)->lockForUpdate()->firstOrFail();
                $this->access->flush();
                Gate::forUser($actor)->authorize($ability, [RoleAssignment::class, $topic, $role, $recipient]);

                if ($role->isArchived()) {
                    throw ValidationException::withMessages(['role_definition_id' => __('areas.assignment.this_role_is_archived')]);
                }

                $this->assertEligibleRecipient($recipient);

                if (RoleAssignment::query()->active()->where(['user_id' => $recipient->id, 'role_definition_id' => $role->id, 'topic_id' => $topic->id])->exists()) {
                    throw ValidationException::withMessages(['user_id' => __('areas.assignment.person_has_role', ['name' => $recipient->name])]);
                }

                $assignment = $this->create($actor, $topic, $role, $recipient, $semesterId === null ? [] : ['semester_id' => $semesterId], $authority);

                TopicAudit::record($actor, $ability === 'import' ? 'assignment.imported' : 'assignment.granted', $topic->id, 'role_assignment', $assignment->id, null, $this->snapshot($assignment));

                return $assignment;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['user_id' => __('areas.assignment.this_person_already_has')]);
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
            throw ValidationException::withMessages(['reason' => __('areas.assignment.give_a_reason')]);
        }

        if (mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => __('areas.assignment.the_reason_can')]);
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
                    throw ValidationException::withMessages(['confirmation' => __('areas.assignment.this_role_was_already_ended')]);
                }

                if ($assignment->version !== $payload['version']) {
                    throw ValidationException::withMessages(['confirmation' => __('areas.assignment.this_role_changed_after')]);
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
            throw ValidationException::withMessages(['confirmation' => __('areas.assignment.this_change_was_already_made')]);
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
            throw ValidationException::withMessages(['confirmation' => __('areas.assignment.this_role_was_already_ended_2')]);
        }

        if ($replacement === null) {
            Gate::forUser($actor)->authorize('end', $assignment);

            return;
        }

        Gate::forUser($actor)->authorize('replace', [$assignment, $replacement]);

        if ($replacement->id === $assignment->user_id) {
            throw ValidationException::withMessages(['replacement_id' => __('areas.assignment.choose_a_different_person')]);
        }

        $this->assertEligibleRecipient($replacement, 'replacement_id');

        if (RoleAssignment::query()->active()->where([
            'user_id' => $replacement->id,
            'role_definition_id' => $assignment->role_definition_id,
            'topic_id' => $assignment->topic_id,
        ])->exists()) {
            throw ValidationException::withMessages(['replacement_id' => __('areas.assignment.person_has_role', ['name' => $replacement->name])]);
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     * @param  Capability  $capability  The capability that allowed it: its assignment is recorded as the authority.
     */
    private function create(User $actor, Topic $topic, RoleDefinition $role, User $recipient, array $extra = [], Capability $capability = Capability::AccessAssign): RoleAssignment
    {
        $authority = $this->access->isSystemAdmin($actor)
            ? null
            : $this->access->coveringAssignments($actor, $topic)->first(fn (RoleAssignment $a) => $a->role->has($capability));

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

    /**
     * Active and invited accounts may hold a role (Task #104): an invited person cannot sign in
     * before activating, so the role only takes effect then (TopicAccess counts active accounts
     * only). A deactivated account is refused.
     */
    private function assertEligibleRecipient(User $user, string $field = 'user_id'): void
    {
        if (! in_array($user->status, ['active', 'invited'], true)) {
            throw ValidationException::withMessages([$field => __('areas.assignment.a_deactivated_account_cannot')]);
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
            throw ValidationException::withMessages(['confirmation' => __('areas.assignment.the_confirmation_is_not_valid')]);
        }

        if (($payload['actor'] ?? null) !== $actor->id || ($payload['expires'] ?? 0) < now()->timestamp) {
            throw ValidationException::withMessages(['confirmation' => __('areas.assignment.the_confirmation_expired_nothing_was')]);
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
            'remains' => __('areas.assignment.the_area_everything_below'),
            'loses' => __('areas.assignment.the_person_loses_only'),
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

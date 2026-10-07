<?php

namespace App\Services;

use App\Enums\Capability;
use App\Models\RoleAssignment;
use App\Models\RoleDefinition;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The only place that writes role definitions.
 *
 * A definition is a name plus registry capabilities. Creating or changing one
 * never touches who holds it; archiving one only stops new assignments.
 * Assignments of a changed role keep working with the role's new powers, so a
 * change to a role that people hold must be acknowledged with the number of
 * affected assignments.
 */
class RoleService
{
    public function __construct(private TopicAccess $access) {}

    /**
     * @param  array{name?: mixed, description?: mixed, capabilities?: mixed, delegable?: mixed}  $data
     *
     * @throws AuthorizationException|ValidationException
     */
    public function create(User $actor, ?Topic $scope, array $data): RoleDefinition
    {
        $fields = $this->validated($data);

        Gate::forUser($actor)->authorize('create', [RoleDefinition::class, $scope, $fields['capabilities'], $fields['delegable']]);

        return DB::transaction(function () use ($actor, $scope, $fields) {
            $role = RoleDefinition::create([
                ...$fields,
                'topic_id' => $scope?->id,
                'created_by_id' => $actor->id,
            ]);

            TopicAudit::record($actor, 'role.created', $scope?->id, 'role_definition', $role->id, null, $this->snapshot($role));

            return $role;
        });
    }

    /**
     * How many assignments a change would affect.
     *
     * @return array{assignments: int, people: int}
     */
    public function usage(RoleDefinition $role): array
    {
        $active = RoleAssignment::query()->active()->where('role_definition_id', $role->id);

        return [
            'assignments' => (clone $active)->count(),
            'people' => (clone $active)->distinct()->count('user_id'),
        ];
    }

    /**
     * @param  array{name?: mixed, description?: mixed, capabilities?: mixed, delegable?: mixed, acknowledge_affected?: mixed}  $data
     *
     * @throws AuthorizationException|ValidationException
     */
    public function update(User $actor, RoleDefinition $role, array $data): RoleDefinition
    {
        $fields = $this->validated($data);

        return DB::transaction(function () use ($actor, $role, $data, $fields) {
            $role = RoleDefinition::query()->whereKey($role->id)->lockForUpdate()->firstOrFail();
            $this->access->flush();

            $changesPower = $fields['capabilities'] !== $role->capabilityList() || $fields['delegable'] !== $role->delegableList();

            Gate::forUser($actor)->authorize('update', [$role, $fields['capabilities'], $fields['delegable'], $changesPower]);

            if ($changesPower) {
                $usage = $this->usage($role);

                if ($usage['assignments'] > 0 && ! filter_var($data['acknowledge_affected'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                    throw ValidationException::withMessages([
                        'capabilities' => "{$usage['assignments']} active assignment(s) held by {$usage['people']} person(s) use this role. Confirm that their access should change.",
                    ]);
                }
            }

            $before = $this->snapshot($role);
            $role->update([...$fields, 'version' => $role->version + 1]);

            TopicAudit::record($actor, 'role.updated', $role->topic_id, 'role_definition', $role->id, $before, $this->snapshot($role));

            return $role;
        });
    }

    /**
     * Stop new assignments of a role (or allow them again). Existing
     * assignments are not ended and nobody loses access.
     *
     * @throws AuthorizationException
     */
    public function setArchived(User $actor, RoleDefinition $role, bool $archived): RoleDefinition
    {
        return DB::transaction(function () use ($actor, $role, $archived) {
            $role = RoleDefinition::query()->whereKey($role->id)->lockForUpdate()->firstOrFail();
            $this->access->flush();

            Gate::forUser($actor)->authorize('update', [$role, $role->capabilityList(), $role->delegableList(), true]);

            if ($role->isArchived() === $archived) {
                return $role;
            }

            $before = $this->snapshot($role);
            $role->update(['archived_at' => $archived ? now() : null, 'version' => $role->version + 1]);

            TopicAudit::record($actor, $archived ? 'role.archived' : 'role.restored', $role->topic_id, 'role_definition', $role->id, $before, $this->snapshot($role));

            return $role;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{name: string, description: ?string, capabilities: list<string>, delegable: list<string>}
     *
     * @throws ValidationException
     */
    private function validated(array $data): array
    {
        $clean = Validator::make([
            'name' => is_string($data['name'] ?? null) ? trim($data['name']) : ($data['name'] ?? null),
            'description' => is_string($data['description'] ?? null) ? trim($data['description']) : ($data['description'] ?? null),
            'capabilities' => $data['capabilities'] ?? [],
            'delegable' => $data['delegable'] ?? [],
        ], [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'capabilities' => ['required', 'array', 'min:1'],
            'capabilities.*' => ['string', 'in:'.implode(',', Capability::values())],
            'delegable' => ['array'],
            'delegable.*' => ['string', 'in:'.implode(',', Capability::values())],
        ], [
            'name.required' => 'Give the role a name.',
            'capabilities.required' => 'Choose at least one thing this role can do.',
            'capabilities.min' => 'Choose at least one thing this role can do.',
        ])->validate();

        $capabilities = Capability::normalise($clean['capabilities']);
        $delegable = Capability::normalise($clean['delegable'] ?? []);

        foreach ($capabilities as $value) {
            $missing = array_values(array_diff(
                array_map(fn (Capability $c) => $c->value, Capability::from($value)->requires()),
                $capabilities,
            ));
            if ($missing !== []) {
                $names = implode(', ', array_map(fn (string $m) => Capability::from($m)->label(), $missing));
                throw ValidationException::withMessages([
                    'capabilities' => '"'.Capability::from($value)->label()."\" also needs: {$names}.",
                ]);
            }
        }

        if (array_diff($delegable, $capabilities) !== []) {
            throw ValidationException::withMessages([
                'delegable' => 'A role can only pass on powers that it has itself.',
            ]);
        }

        return [
            'name' => $clean['name'],
            'description' => ($clean['description'] ?? '') === '' ? null : $clean['description'],
            'capabilities' => $capabilities,
            'delegable' => $delegable,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(RoleDefinition $role): array
    {
        return [
            'name' => $role->name,
            'description' => $role->description,
            'topic_id' => $role->topic_id,
            'capabilities' => $role->capabilityList(),
            'delegable' => $role->delegableList(),
            'archived' => $role->isArchived(),
        ];
    }
}

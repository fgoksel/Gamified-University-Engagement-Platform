<?php

namespace App\Http\Controllers\Concerns;

use App\Models\RoleAssignment;
use App\Models\RoleDefinition;
use App\Models\Topic;
use App\Models\User;
use App\Services\TopicBrowser;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * What "Import students" (Task #72) and "Invite a person" (Task #104) both check before they
 * give a role: the role passes the 'importRole' gate at the area, and every ticked area above
 * is one this person may import into (S3, 27 October) and accepts the role too.
 */
trait ChecksImportTargets
{
    /**
     * @param  list<mixed>  $parentIds
     * @return array{0: RoleDefinition, 1: Collection<int, Topic>} the role and the ticked areas above, nearest first
     *
     * @throws ValidationException
     */
    protected function importTargets(TopicBrowser $browser, User $user, Topic $topic, int $roleId, array $parentIds): array
    {
        $role = RoleDefinition::find($roleId);
        abort_if($role === null, 404);

        // The dialog only offers allowed roles, so this is about a change in the meantime.
        $allowed = Gate::forUser($user)->inspect('importRole', [RoleAssignment::class, $topic, $role]);

        if ($allowed->denied()) {
            throw ValidationException::withMessages(['role_definition_id' => $allowed->message()]);
        }

        $offered = array_column($browser->importParents($user, $topic), 'id');
        $parents = array_values(array_unique(array_map('intval', $parentIds)));

        if (array_diff($parents, $offered) !== []) {
            throw ValidationException::withMessages(['parent_topic_ids' => __('areas.import_flash.you_can_only_add_people')]);
        }

        $topics = Topic::query()->whereIn('id', $parents)->get()
            ->sortBy(fn (Topic $parent) => array_search($parent->id, $offered, true))
            ->values();

        foreach ($topics as $parent) {
            $allowed = Gate::forUser($user)->inspect('importRole', [RoleAssignment::class, $parent, $role]);

            if ($allowed->denied()) {
                throw ValidationException::withMessages(['parent_topic_ids' => __('areas.import_flash.parent_not_allowed', ['area' => $parent->title, 'reason' => $allowed->message()])]);
            }
        }

        return [$role, $topics];
    }
}

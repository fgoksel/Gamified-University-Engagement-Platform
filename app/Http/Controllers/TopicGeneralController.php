<?php

namespace App\Http\Controllers;

use App\Jobs\JoinGeneralAreaJob;
use App\Models\RoleAssignment;
use App\Models\RoleDefinition;
use App\Models\Topic;
use App\Services\TopicAccess;
use App\Services\TopicService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * "General area" on an area's Access tab (Task #133): every student whose account becomes active
 * joins the area with the chosen role. An area admin (Give a role and Import students here) or a
 * system admin switches it; course areas keep using the import.
 */
class TopicGeneralController extends Controller
{
    public function __construct(
        private TopicAccess $access,
        private TopicService $topics,
    ) {}

    public function update(Request $request, Topic $topic): RedirectResponse
    {
        $user = $request->user();
        abort_unless($this->access->canView($user, $topic), 404);
        abort_unless(Gate::forUser($user)->allows('setGeneral', $topic), 403);

        $data = $request->validate([
            'role_definition_id' => ['nullable', 'integer'],
            'add_active' => ['nullable', 'boolean'],
        ]);

        $role = null;

        if (isset($data['role_definition_id'])) {
            $role = RoleDefinition::query()->usable()->find($data['role_definition_id']);
            abort_if($role === null, 404);

            // A general area is for every student: only a plain student role (Task #133 review).
            if (! $role->isPlainStudentRole()) {
                throw ValidationException::withMessages(['role_definition_id' => __('areas.general_flash.only_a_student_role')]);
            }

            // The dialog only offers allowed roles, so this is about a change in the meantime.
            $allowed = Gate::forUser($user)->inspect('importRole', [RoleAssignment::class, $topic, $role]);

            if ($allowed->denied()) {
                throw ValidationException::withMessages(['role_definition_id' => $allowed->message()]);
            }
        }

        $topic = $this->topics->setGeneral($user, $topic, $role);

        if ($role === null) {
            return $this->back($topic)->with('success', __('areas.general_flash.turned_off', ['area' => $topic->title]));
        }

        $added = 0;

        // Students already active join only when asked, in the background.
        if ($request->boolean('add_active')) {
            $added = JoinGeneralAreaJob::missing($topic, $role->id)->count();
            JoinGeneralAreaJob::dispatch($topic->id);
        }

        return $this->back($topic)->with('success', $added > 0
            ? trans_choice('areas.general_flash.saved_and_adding', $added, ['count' => $added, 'area' => $topic->title, 'role' => $role->name])
            : __('areas.general_flash.saved', ['area' => $topic->title, 'role' => $role->name]));
    }

    private function back(Topic $topic): RedirectResponse
    {
        return redirect()->route('topics.show', ['topic' => $topic, 'tab' => 'access']);
    }
}

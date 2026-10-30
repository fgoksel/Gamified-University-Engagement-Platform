<?php

namespace App\Http\Controllers;

use App\Enums\Capability;
use App\Jobs\ImportTopicStudentsJob;
use App\Models\RoleAssignment;
use App\Models\RoleDefinition;
use App\Models\Topic;
use App\Services\TopicAccess;
use App\Services\TopicBrowser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * "Import students" on a topic (Task #72): a file with one column of Neptun codes, the
 * role to give and the topics above that should hold the same students. The file is
 * processed by a queued job, which checks every student again; the result arrives as
 * a notification.
 */
class TopicImportController extends Controller
{
    public function __construct(
        private TopicAccess $access,
        private TopicBrowser $browser,
    ) {}

    public function store(Request $request, Topic $topic): RedirectResponse
    {
        $user = $request->user();
        abort_unless($this->access->canView($user, $topic), 404);
        abort_unless($this->access->can($user, Capability::AccessImport, $topic), 403);

        $data = $request->validate([
            // 10 MB, like the other imports (Technical Specification 7.3.1).
            'file' => ['required', 'file', 'max:10240'],
            'role_definition_id' => ['required', 'integer'],
            'parent_topic_ids' => ['nullable', 'array'],
            'parent_topic_ids.*' => ['integer'],
        ], [
            'file.required' => 'Choose a file.',
            'role_definition_id.required' => 'Choose a role.',
        ]);

        $file = $request->file('file');

        if (! in_array(strtolower($file->getClientOriginalExtension()), ['csv', 'txt'], true)) {
            throw ValidationException::withMessages(['file' => 'The file must be a CSV file.']);
        }

        $role = RoleDefinition::find($data['role_definition_id']);
        abort_if($role === null, 404);

        // The dialog only offers allowed roles, so this is about a change in the meantime.
        $allowed = Gate::forUser($user)->inspect('importRole', [RoleAssignment::class, $topic, $role]);

        if ($allowed->denied()) {
            throw ValidationException::withMessages(['role_definition_id' => $allowed->message()]);
        }

        // Only the topics above that this person may import into (S3, 27 October).
        $offered = array_column($this->browser->importParents($user, $topic), 'id');
        $parents = array_values(array_unique(array_map('intval', $data['parent_topic_ids'] ?? [])));

        if (array_diff($parents, $offered) !== []) {
            throw ValidationException::withMessages(['parent_topic_ids' => 'You can only add students to topics above this one where you may import them.']);
        }

        foreach (Topic::query()->whereIn('id', $parents)->get() as $parent) {
            $allowed = Gate::forUser($user)->inspect('importRole', [RoleAssignment::class, $parent, $role]);

            if ($allowed->denied()) {
                throw ValidationException::withMessages(['parent_topic_ids' => "{$parent->title}: {$allowed->message()}"]);
            }
        }

        $path = $file->store('imports', 'local');

        // For example a storage folder the web server may not write to: say so instead of queueing nothing.
        if ($path === false) {
            report(new RuntimeException("A student import file could not be saved to storage (topic {$topic->id})."));

            throw ValidationException::withMessages(['file' => 'The file could not be saved on the server, so nothing was imported. Please try again, or tell an administrator.']);
        }

        ImportTopicStudentsJob::dispatch($path, $user->id, $topic->id, $role->id, $parents);

        return redirect()
            ->route('topics.show', ['topic' => $topic, 'tab' => 'access'])
            ->with('success', 'The import has started. You will find the result under Notifications.');
    }
}

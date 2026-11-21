<?php

namespace App\Http\Controllers;

use App\Enums\Capability;
use App\Http\Controllers\Concerns\ChecksImportTargets;
use App\Jobs\ImportTopicStudentsJob;
use App\Models\Topic;
use App\Services\TopicAccess;
use App\Services\TopicBrowser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * "Import students" on a topic (Task #72): a file with a column of Neptun codes (and, to create
 * missing accounts, Full Name and Email: Task #104), the role to give and the topics above that
 * should hold the same students. The file is processed by a queued job, which checks every
 * student again; the result arrives as a notification.
 */
class TopicImportController extends Controller
{
    use ChecksImportTargets;

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
            'file.required' => __('areas.import_flash.choose_a_file'),
            'role_definition_id.required' => __('areas.import_flash.choose_a_role'),
        ]);

        $file = $request->file('file');

        if (! in_array(strtolower($file->getClientOriginalExtension()), ['csv', 'txt'], true)) {
            throw ValidationException::withMessages(['file' => __('areas.import_flash.the_file_must')]);
        }

        [$role, $parents] = $this->importTargets($this->browser, $user, $topic, (int) $data['role_definition_id'], $data['parent_topic_ids'] ?? []);

        $path = $file->store('imports', 'local');

        // For example a storage folder the web server may not write to: say so instead of queueing nothing.
        if ($path === false) {
            report(new RuntimeException("A student import file could not be saved to storage (topic {$topic->id})."));

            throw ValidationException::withMessages(['file' => __('areas.import_flash.the_file_could')]);
        }

        ImportTopicStudentsJob::dispatch($path, $user->id, $topic->id, $role->id, $parents->pluck('id')->all());

        return redirect()
            ->route('topics.show', ['topic' => $topic, 'tab' => 'access'])
            ->with('success', __('areas.import_flash.the_import_has_started'));
    }
}

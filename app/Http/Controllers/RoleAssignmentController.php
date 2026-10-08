<?php

namespace App\Http\Controllers;

use App\Models\RoleAssignment;
use App\Models\RoleDefinition;
use App\Models\Topic;
use App\Models\User;
use App\Services\AssignmentService;
use App\Services\TopicAccess;
use App\Services\TopicBrowser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Giving, ending and handing over roles. Ending and handover are two calls:
 * prepare() validates and returns the summary and a token for the dialogs,
 * execute() redeems the token. Neither trusts the dialogs.
 */
class RoleAssignmentController extends Controller
{
    public function __construct(
        private AssignmentService $assignments,
        private TopicAccess $access,
        private TopicBrowser $browser,
    ) {}

    public function candidates(Request $request, Topic $topic): JsonResponse
    {
        abort_unless($this->access->canView($request->user(), $topic), 404);

        return response()->json(['results' => $this->browser->candidates($request->user(), $topic, (string) $request->query('q', ''))]);
    }

    public function store(Request $request, Topic $topic): RedirectResponse
    {
        abort_unless($this->access->canView($request->user(), $topic), 404);

        $data = $request->validate([
            'user_id' => ['required', 'integer'],
            'role_definition_id' => ['required', 'integer'],
        ], ['user_id.required' => 'Choose a person.', 'role_definition_id.required' => 'Choose a role.']);

        $recipient = User::find($data['user_id']);
        $role = RoleDefinition::find($data['role_definition_id']);

        abort_if($recipient === null || $role === null, 404);

        $assignment = $this->assignments->grant($request->user(), $topic, $role, $recipient);

        return redirect()
            ->route('topics.show', ['topic' => $topic, 'tab' => 'access'])
            ->with('success', "{$recipient->name} now has the role \"{$role->name}\" here and below.");
    }

    public function prepare(Request $request, RoleAssignment $assignment): JsonResponse
    {
        abort_unless($this->access->canView($request->user(), $assignment->topic), 404);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
            'replacement_id' => ['nullable', 'integer'],
        ], ['reason.required' => 'Give a reason for the change.']);

        $replacement = null;
        if (! empty($data['replacement_id'])) {
            $replacement = User::find($data['replacement_id']);
            abort_if($replacement === null, 404);
        }

        return response()->json($this->assignments->prepare($request->user(), $assignment, $replacement, $data['reason']));
    }

    public function execute(Request $request): RedirectResponse|JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string']]);

        $result = $this->assignments->execute($request->user(), $data['token']);

        $topic = $result['ended']->topic;
        $message = $result['replacement'] === null
            ? "{$result['ended']->user->name} no longer has the role \"{$result['ended']->role->name}\" in \"{$topic->title}\"."
            : "{$result['replacement']->user->name} replaced {$result['ended']->user->name} as \"{$result['ended']->role->name}\" in \"{$topic->title}\".";

        return redirect()->route('topics.show', ['topic' => $topic, 'tab' => 'access'])->with('success', $message);
    }
}

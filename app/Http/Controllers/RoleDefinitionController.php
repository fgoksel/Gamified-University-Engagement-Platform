<?php

namespace App\Http\Controllers;

use App\Enums\Capability;
use App\Models\RoleDefinition;
use App\Models\Topic;
use App\Services\RoleService;
use App\Services\TopicAccess;
use App\Services\TopicBrowser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The role builder: reusable roles made of registry capabilities.
 */
class RoleDefinitionController extends Controller
{
    public function __construct(
        private RoleService $roles,
        private TopicAccess $access,
        private TopicBrowser $browser,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        abort_unless(
            $this->access->isSystemAdmin($user)
                || $this->access->canAnywhere($user, Capability::DefineRoles)
                || $this->access->canAnywhere($user, Capability::AccessAssign),
            403,
        );

        return Inertia::render('Topics/Roles', [
            'roles' => $this->browser->roleList($user),
            'registry' => Capability::registry(),
            'scopes' => $this->browser->definableScopes($user),
            'isAdmin' => $this->access->isSystemAdmin($user),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $scope = $this->scope($request);

        $role = $this->roles->create($request->user(), $scope, $request->only(['name', 'description', 'capabilities', 'delegable']));

        return redirect()->route('topics.roles.index')->with('success', "Role \"{$role->name}\" created. It is not given to anyone yet.");
    }

    public function update(Request $request, RoleDefinition $role): RedirectResponse
    {
        $this->roles->update($request->user(), $role, $request->only(['name', 'description', 'capabilities', 'delegable', 'acknowledge_affected']));

        return redirect()->route('topics.roles.index')->with('success', "Role \"{$role->name}\" saved.");
    }

    public function archive(Request $request, RoleDefinition $role): RedirectResponse
    {
        $archived = $request->boolean('archived', true);

        $this->roles->setArchived($request->user(), $role, $archived);

        return redirect()->route('topics.roles.index')->with('success', $archived
            ? "Role \"{$role->name}\" archived. People who have it keep it; it cannot be given to anyone new."
            : "Role \"{$role->name}\" can be given again.");
    }

    public function usage(Request $request, RoleDefinition $role): JsonResponse
    {
        abort_unless($this->browser->canEditRole($request->user(), $role), 403);

        return response()->json($this->roles->usage($role));
    }

    private function scope(Request $request): ?Topic
    {
        $id = $request->input('scope_topic_id');

        if ($id === null || $id === '') {
            return null;
        }

        $scope = Topic::find($id);

        abort_unless($scope !== null && $this->access->canView($request->user(), $scope), 404);

        return $scope;
    }
}

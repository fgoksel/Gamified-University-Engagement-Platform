<?php

namespace App\Http\Middleware;

use App\Enums\Capability;
use App\Enums\UserRole;
use App\Services\TopicAccess;
use App\Support\SubjectAreaTree;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            // The logged-in user and their role, used by the layouts to show the
            // right menu (e.g. the Subject Area panel is for teachers only).
            'auth' => [
                'user' => fn () => $request->user() ? [
                    'id' => $request->user()->id,
                    'name' => $request->user()->name,
                    'email' => $request->user()->email,
                    'role' => $request->user()->getRoleNames()->first(),
                    'appearance' => $request->user()->appearance,
                    // Profile photo URL, or null to show initials
                    'avatar' => $request->user()->avatar
                        ? Storage::disk('public')->url($request->user()->avatar)
                        : null,
                ] : null,
            ],
            // Menu flags for Topics: shown only to people who can open a topic, and
            // "Roles" only to those who may define or give roles somewhere.
            'topics' => fn () => $request->user() ? $this->topicMenu($request) : null,
            // Subject Area panel in the sidebar: teachers only (UC-1.2)
            'subjectAreaTree' => fn () => $request->user()?->hasRole(UserRole::Teacher)
                ? SubjectAreaTree::for($request->user())
                : null,
            // One-time messages set with redirect(...)->with('success' | 'error', '...'),
            // e.g. "Your password has been changed." or
            // "You do not have permission to view this page."
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }

    /**
     * @return array{available: bool, roles: bool}
     */
    private function topicMenu(Request $request): array
    {
        $access = app(TopicAccess::class);
        $user = $request->user();

        return [
            'available' => $access->isSystemAdmin($user) || $access->grantedTopicIds($user) !== [],
            'roles' => $access->isSystemAdmin($user)
                || $access->canAnywhere($user, Capability::DefineRoles)
                || $access->canAnywhere($user, Capability::AccessAssign),
        ];
    }
}

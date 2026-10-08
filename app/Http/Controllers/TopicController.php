<?php

namespace App\Http\Controllers;

use App\Enums\Capability;
use App\Models\Topic;
use App\Services\TopicAccess;
use App\Services\TopicBrowser;
use App\Services\TopicService;
use App\Support\Like;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Topics explorer: the page itself, its lazy tree, search and topic edits.
 * Every read goes through TopicAccess/TopicBrowser and every write through
 * TopicService, so the page, the JSON endpoints and the services share rules.
 */
class TopicController extends Controller
{
    public function __construct(
        private TopicAccess $access,
        private TopicBrowser $browser,
        private TopicService $topics,
    ) {}

    /**
     * Entry point. Reopens the last topic if it is still allowed, goes straight
     * to a single entry topic, otherwise lists the entry topics.
     */
    public function index(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if (! $request->boolean('start')) {
            $lastId = $request->session()->get('topics.last');
            $last = $lastId === null ? null : Topic::find($lastId);

            if ($last !== null && $this->access->canView($user, $last)) {
                return redirect()->route('topics.show', $last);
            }

            $entries = $this->access->entryTopics($user);
            if ($entries->count() === 1) {
                return redirect()->route('topics.show', $entries->first());
            }
        }

        return $this->render($request, null);
    }

    public function show(Request $request, Topic $topic): Response
    {
        // Not allowed looks exactly like not existing.
        abort_unless($this->access->canView($request->user(), $topic), 404);

        $request->session()->put('topics.last', $topic->id);

        return $this->render($request, $topic);
    }

    /**
     * Children for the lazy tree.
     */
    public function children(Request $request, Topic $topic): JsonResponse
    {
        abort_unless($this->access->canView($request->user(), $topic), 404);

        return response()->json(['children' => $this->browser->children($topic)]);
    }

    public function search(Request $request): JsonResponse
    {
        return response()->json(['results' => $this->browser->search($request->user(), (string) $request->query('q', ''))]);
    }

    public function storeRoot(Request $request): RedirectResponse
    {
        $topic = $this->topics->create($request->user(), null, $request->only(['title', 'code', 'description']));

        return redirect()->route('topics.show', $topic)->with('success', "Topic \"{$topic->title}\" created.");
    }

    public function storeChild(Request $request, Topic $topic): RedirectResponse
    {
        abort_unless($this->access->canView($request->user(), $topic), 404);

        $child = $this->topics->create($request->user(), $topic, $request->only(['title', 'code', 'description']));

        return redirect()->route('topics.show', $child)->with('success', "Topic \"{$child->title}\" added under \"{$topic->title}\".");
    }

    public function update(Request $request, Topic $topic): RedirectResponse
    {
        abort_unless($this->access->canView($request->user(), $topic), 404);

        $this->topics->update($request->user(), $topic, $request->only(['title', 'code', 'description']));

        return redirect()->route('topics.show', $topic)->with('success', 'Topic saved.');
    }

    /**
     * Archive a visible topic with everything below it.
     */
    public function archive(Request $request, Topic $topic): RedirectResponse
    {
        abort_unless($this->access->canView($request->user(), $topic), 404);

        $this->topics->archive($request->user(), $topic);

        return $this->after($request, $topic)->with('success', "\"{$topic->title}\" archived. Nothing was deleted; find it under Archived topics to restore it.");
    }

    /**
     * Archived topics the person may restore.
     */
    public function archived(Request $request): Response
    {
        $user = $request->user();

        abort_unless($this->canArchiveAnywhere($user), 403);

        return Inertia::render('Topics/Archived', [
            'topics' => $this->browser->archived($user),
            'isAdmin' => $this->access->isSystemAdmin($user),
        ]);
    }

    public function restore(Request $request, Topic $topic): RedirectResponse
    {
        // Not allowed looks like not existing.
        abort_unless($this->access->can($request->user(), Capability::Archive, $topic), 404);

        $this->topics->restore($request->user(), $topic);

        return redirect()->route('topics.show', $topic)->with('success', "\"{$topic->title}\" restored.");
    }

    /**
     * Step 1 of a permanent deletion: checks and returns the confirmation data and token.
     */
    public function deletePrepare(Request $request, Topic $topic): JsonResponse
    {
        abort_unless($this->access->isSystemAdmin($request->user()), 404);

        return response()->json($this->topics->prepareDelete($request->user(), $topic));
    }

    /**
     * Step 2: delete for good (needs the token and the topic name typed in).
     */
    public function deleteExecute(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'confirm_title' => ['required', 'string'],
        ], ['confirm_title.required' => 'Type the topic name to confirm.']);

        abort_unless($this->access->isSystemAdmin($request->user()), 404);

        $this->topics->executeDelete($request->user(), $data['token'], $data['confirm_title']);

        return redirect()->route('topics.index', ['start' => 1])->with('success', 'The topic was permanently deleted.');
    }

    /**
     * Possible destinations for a move: topics where the person may move into.
     */
    public function movePreview(Request $request, Topic $topic): JsonResponse
    {
        abort_unless($this->access->canView($request->user(), $topic), 404);

        $destination = $this->destination($request);

        return response()->json($this->topics->movePreview($request->user(), $topic, $destination));
    }

    public function move(Request $request, Topic $topic): RedirectResponse
    {
        abort_unless($this->access->canView($request->user(), $topic), 404);

        $moved = $this->topics->move($request->user(), $topic, $this->destination($request));

        return redirect()->route('topics.show', $moved)->with('success', "\"{$moved->title}\" moved.");
    }

    /**
     * Places the person may move this topic to, filtered by search.
     */
    public function destinations(Request $request, Topic $topic): JsonResponse
    {
        abort_unless($this->access->canView($request->user(), $topic), 404);

        $term = trim((string) $request->query('q', ''));
        $user = $request->user();

        $found = $this->access->visibleTopics($user)
            ->whereKeyNot($topic->id)
            ->when($term !== '', fn ($q) => Like::contains($q, ['title'], $term))
            ->orderBy('depth')->orderBy('title')->limit(50)->get()
            ->filter(fn (Topic $candidate) => ! $candidate->isWithin($topic) && Gate::forUser($user)->allows('move', [$topic, $candidate]))
            ->take(15)
            ->map(fn (Topic $candidate) => [
                'id' => $candidate->id,
                'title' => $candidate->title,
                'trail' => $this->access->breadcrumbs($user, $candidate)->slice(0, -1)->pluck('title')->values()->all(),
            ])->values();

        return response()->json(['results' => $found]);
    }

    /**
     * Where to go after the topic left normal browsing: its parent, or the start page.
     */
    private function after(Request $request, Topic $topic): RedirectResponse
    {
        $parent = $topic->parent_id === null ? null : Topic::find($topic->parent_id);

        return $parent !== null && $this->access->canView($request->user(), $parent)
            ? redirect()->route('topics.show', $parent)
            : redirect()->route('topics.index', ['start' => 1]);
    }

    private function canArchiveAnywhere($user): bool
    {
        return $this->access->isSystemAdmin($user) || $this->access->canAnywhere($user, Capability::Archive);
    }

    private function destination(Request $request): ?Topic
    {
        $id = $request->input('destination_id');

        if ($id === null || $id === '') {
            return null;
        }

        $destination = Topic::find($id);

        // A destination the person cannot see is reported like a missing one.
        abort_unless($destination !== null && $this->access->canView($request->user(), $destination), 404);

        return $destination;
    }

    private function render(Request $request, ?Topic $topic): Response
    {
        $user = $request->user();
        $view = $request->query('view') === 'branch' ? 'branch' : 'contents';
        $tab = $request->query('tab') === 'access' ? 'access' : 'topic';

        $selected = null;

        if ($topic !== null) {
            $abilities = $this->browser->abilities($user, $topic);
            $tab = $tab === 'access' && ! ($abilities['view_access'] || $abilities['assign'] || $abilities['end']) ? 'topic' : $tab;

            $selected = [
                'id' => $topic->id,
                'title' => $topic->title,
                'code' => $topic->code,
                'description' => $topic->description,
                'depth' => $topic->depth,
                'created_by' => $topic->createdBy?->name,
                'breadcrumbs' => $this->access->breadcrumbs($user, $topic)->map(fn (Topic $crumb) => ['id' => $crumb->id, 'title' => $crumb->title])->values()->all(),
                'can' => $abilities,
                'children' => $this->browser->children($topic),
                'counts' => $this->browser->counts($user, $topic),
                'branch' => $view === 'branch' ? $this->browser->branch($user, $topic, $request->query('q'), max(1, (int) $request->query('page', 1))) : null,
                'access' => $tab === 'access' ? $this->browser->access($user, $topic) : null,
            ];
        }

        return Inertia::render('Topics/Explorer', [
            'entries' => $this->browser->entries($user),
            'expanded' => $topic === null ? (object) [] : (object) $this->browser->expandedFor($user, $topic),
            'selected' => $selected,
            'view' => $view,
            'tab' => $tab,
            'search' => (string) $request->query('q', ''),
            'isAdmin' => $this->access->isSystemAdmin($user),
            'canSeeRoles' => $this->canSeeRoles($user),
            'canSeeArchived' => $this->canArchiveAnywhere($user),
            'maxDepth' => (int) config('topics.max_depth'),
        ]);
    }

    private function canSeeRoles($user): bool
    {
        return $this->access->isSystemAdmin($user)
            || $this->access->canAnywhere($user, Capability::DefineRoles)
            || $this->access->canAnywhere($user, Capability::AccessAssign);
    }
}

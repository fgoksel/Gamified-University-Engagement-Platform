<?php

namespace App\Http\Controllers;

use App\Models\Leaderboard;
use App\Models\Topic;
use App\Services\AreaLeaderboards;
use App\Services\LeaderboardStandings;
use App\Services\OwnLeaderboards;
use App\Services\TopicAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Area leaderboards (Task #89): topic admins create, edit, publish and delete them on the
 * topic's Leaderboards tab; people open the ones they may see; the hall screen (#56) reads
 * the published ones without login.
 */
class AreaLeaderboardController extends Controller
{
    public function __construct(
        private TopicAccess $access,
        private AreaLeaderboards $boards,
        private LeaderboardStandings $standings,
    ) {}

    /**
     * "My leaderboard" (Task #54): the person's own points and rank, and the leaderboards they may see.
     */
    public function index(Request $request, OwnLeaderboards $own): Response
    {
        return Inertia::render('Leaderboards/Index', $own->for($request->user()));
    }

    public function show(Request $request, Leaderboard $leaderboard): Response
    {
        $user = $request->user();

        // Not allowed looks exactly like not existing.
        abort_unless($this->boards->canSee($user, $leaderboard), 404);

        return Inertia::render('Leaderboards/Show', [
            'leaderboard' => [
                ...$this->boards->summary($leaderboard),
                'can_open_topic' => $this->access->canView($user, $leaderboard->topic),
            ],
            'standings' => $this->standings->view($leaderboard, $user),
        ]);
    }

    /**
     * The hall screen (Task #56), without login: it reads the feed below and rotates by itself.
     */
    public function hallScreen(): Response
    {
        return Inertia::render('Screen/Hall');
    }

    /**
     * The read-only feed of the hall screen, without login: published leaderboards only,
     * with the top places and only the columns their creators chose.
     */
    public function hall(): JsonResponse
    {
        return response()->json([
            'leaderboards' => Leaderboard::query()
                ->onHallScreen()
                ->with('topic:id,title')
                ->orderBy('name')
                ->orderBy('id')
                ->get()
                ->map(fn (Leaderboard $board) => [
                    'id' => $board->id,
                    'name' => $board->name,
                    'topic' => $board->topic->title,
                    'columns' => $board->columns(),
                    'seconds' => Leaderboard::HALL_SECONDS,
                    ...$this->standings->top($board),
                ])
                ->values()
                ->all(),
        ]);
    }

    public function store(Request $request, Topic $topic): RedirectResponse
    {
        $this->authorizeManage($request, $topic);

        $board = $this->boards->create($request->user(), $topic, $this->validated($request, $topic));

        return $this->backToTopic($topic)->with('success', "Leaderboard \"{$board->name}\" created.");
    }

    public function update(Request $request, Leaderboard $leaderboard): RedirectResponse
    {
        $topic = $leaderboard->topic;
        $this->authorizeManage($request, $topic);

        $this->boards->update($request->user(), $leaderboard, $this->validated($request, $topic, $leaderboard));

        return $this->backToTopic($topic)->with('success', 'Leaderboard saved.');
    }

    public function publish(Request $request, Leaderboard $leaderboard): RedirectResponse
    {
        $topic = $leaderboard->topic;
        $this->authorizeManage($request, $topic);

        $this->boards->setPublished($request->user(), $leaderboard, true);

        return $this->backToTopic($topic)->with('success', "\"{$leaderboard->name}\" is published.");
    }

    public function unpublish(Request $request, Leaderboard $leaderboard): RedirectResponse
    {
        $topic = $leaderboard->topic;
        $this->authorizeManage($request, $topic);

        $this->boards->setPublished($request->user(), $leaderboard, false);

        return $this->backToTopic($topic)->with('success', "\"{$leaderboard->name}\" is no longer published.");
    }

    public function destroy(Request $request, Leaderboard $leaderboard): RedirectResponse
    {
        $topic = $leaderboard->topic;
        $this->authorizeManage($request, $topic);

        $this->boards->delete($request->user(), $leaderboard);

        return $this->backToTopic($topic)->with('success', "\"{$leaderboard->name}\" deleted. No points changed.");
    }

    private function authorizeManage(Request $request, Topic $topic): void
    {
        $user = $request->user();

        abort_unless($this->access->canView($user, $topic), 404);
        abort_unless($this->boards->canManage($user, $topic), 403);
    }

    /**
     * Left out, a column keeps the default: the Neptun code alone (L6, 27 October).
     *
     * @return array{name: string, show_neptun_code: bool, show_first_name: bool, show_last_name: bool}
     */
    private function validated(Request $request, Topic $topic, ?Leaderboard $board = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('leaderboards', 'name')->where('topic_id', $topic->id)->ignore($board)],
            'show_neptun_code' => ['sometimes', 'boolean'],
            'show_first_name' => ['sometimes', 'boolean'],
            'show_last_name' => ['sometimes', 'boolean'],
        ], [
            'name.required' => 'Give the leaderboard a name.',
            'name.unique' => 'This topic already has a leaderboard with that name.',
        ]);

        $data = [
            'name' => $data['name'],
            'show_neptun_code' => (bool) ($data['show_neptun_code'] ?? true),
            'show_first_name' => (bool) ($data['show_first_name'] ?? false),
            'show_last_name' => (bool) ($data['show_last_name'] ?? false),
        ];

        if (! $data['show_neptun_code'] && ! $data['show_first_name'] && ! $data['show_last_name']) {
            throw ValidationException::withMessages(['columns' => 'Tick at least one column, so people can tell the students apart.']);
        }

        return $data;
    }

    private function backToTopic(Topic $topic): RedirectResponse
    {
        return redirect()->route('topics.show', ['topic' => $topic->id, 'tab' => 'leaderboards']);
    }
}

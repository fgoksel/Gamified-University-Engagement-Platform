<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Requests\StoreEventRequest;
use App\Models\Event;
use App\Models\Semester;
use App\Models\TopicTag;
use App\Services\EventPlacement;
use App\Services\OrganizedEvents;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Event pages of the main menu.
 */
class EventController extends Controller
{
    /**
     * All events (UC-1.5, UC-2.3).
     */
    public function index(): Response
    {
        return Inertia::render('Events/Index');
    }

    /**
     * "My events": a teacher sees the events they organize (UC-1.3),
     * a student sees the events they applied to (UC-2.3).
     */
    public function mine(Request $request, OrganizedEvents $organized): Response
    {
        if (! $request->user()->hasRole(UserRole::Teacher)) {
            return Inertia::render('Events/Applied');
        }

        $filter = in_array($request->query('filter'), OrganizedEvents::FILTERS, true)
            ? $request->query('filter')
            : 'upcoming';

        return Inertia::render('Events/Organized', [
            'filter' => $filter,
            'counts' => $organized->counts($request->user()),
            'events' => $organized->list($request->user(), $filter),
        ]);
    }

    /**
     * The Event Form for a new event (Task #38, UC-1.3).
     */
    public function create(Request $request, EventPlacement $placement): Response
    {
        return Inertia::render('Events/Create', [
            'owningTopics' => $placement->formOptions($request->user()),
            'tags' => TopicTag::query()
                ->where('is_active', true)
                ->orderBy('category')
                ->orderBy('name')
                ->get(['id', 'name', 'category']),
            'hasActiveSemester' => Semester::where('status', 'active')->exists(),
        ]);
    }

    /**
     * Save the Event Form as a draft or publish it (Task #38).
     */
    public function store(StoreEventRequest $request): RedirectResponse
    {
        $semester = Semester::where('status', 'active')->orderByDesc('starts_at')->first();

        if ($semester === null) {
            throw ValidationException::withMessages([
                'title' => 'There is no active semester. Ask an administrator to open one first.',
            ]);
        }

        $data = $request->validated();
        $publish = $data['action'] === 'publish';
        $hidden = (bool) ($data['is_hidden'] ?? false);

        Event::create([
            'title' => trim($data['title']),
            'description' => trim($data['description']),
            'topic_tag_id' => $data['topic_tag_id'],
            // Copied from the tag; the ledger snapshots it again when crediting.
            'category' => TopicTag::findOrFail($data['topic_tag_id'])->category,
            'created_by_id' => $request->user()->id,
            'owning_topic_id' => $data['owning_topic_id'],
            'audience_topic_id' => $data['audience_topic_id'] ?? null,
            'semester_id' => $semester->id,
            'capacity' => $data['capacity'],
            'application_type' => $data['application_type'],
            'points_value' => $data['points_value'],
            'event_starts_at' => $data['event_starts_at'],
            'event_ends_at' => $data['event_ends_at'],
            'application_deadline' => $data['application_deadline'],
            'completion_deadline' => $data['completion_deadline'],
            'is_hidden' => $hidden,
            'reveal_at' => $hidden ? $data['reveal_at'] : null,
            'qr_enabled' => (bool) ($data['qr_enabled'] ?? false),
            'status' => $publish ? Event::STATUS_PUBLISHED : Event::STATUS_DRAFT,
            'published_at' => $publish ? now() : null,
        ]);

        return redirect()->route('events.mine')->with(
            'success',
            $publish ? 'Your event is published.' : 'Your event is saved as a draft.',
        );
    }
}

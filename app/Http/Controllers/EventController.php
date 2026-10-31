<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Requests\StoreEventRequest;
use App\Models\Event;
use App\Models\EventApplication;
use App\Models\Semester;
use App\Models\TopicTag;
use App\Services\EventApplicationService;
use App\Services\EventCatalog;
use App\Services\EventPlacement;
use App\Services\OrganizedEvents;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Event pages of the main menu.
 */
class EventController extends Controller
{
    /**
     * All events (Task #40, UC-1.5, UC-2.3): filtered by topic or branch, category and date.
     */
    public function index(Request $request, EventCatalog $catalog): Response
    {
        $filters = $catalog->filters($request->user(), $request->query());

        return Inertia::render('Events/Index', [
            'filters' => $filters,
            // Not "topics": that name is the shared menu flag from HandleInertiaRequests.
            'topicOptions' => $catalog->topicOptions($request->user()),
            'events' => $catalog->page($request->user(), $filters),
        ]);
    }

    /**
     * One event's detail page, also reached by its direct URL (Task #40).
     * It follows the same visibility rules as the catalog (EventPolicy::view);
     * an event the person may not see is "not found", so its existence is not revealed.
     */
    public function show(Request $request, Event $event, EventCatalog $catalog): Response
    {
        abort_unless(Gate::forUser($request->user())->allows('view', $event), 404);

        return Inertia::render('Events/Show', [
            'event' => $catalog->detail($request->user(), $event),
        ]);
    }

    /**
     * "My events": a teacher sees the events they organize (UC-1.3),
     * a student sees the events they applied to (UC-2.3).
     */
    public function mine(Request $request, OrganizedEvents $organized, EventApplicationService $service): Response
    {
        if ($request->user()->hasRole(UserRole::Teacher)) {
            $filter = in_array($request->query('filter'), OrganizedEvents::FILTERS, true)
                ? $request->query('filter')
                : 'upcoming';

            return Inertia::render('Events/Organized', [
                'filter' => $filter,
                'counts' => $organized->counts($request->user()),
                'events' => $organized->list($request->user(), $filter),
            ]);
        }

        $now = now();
        $positions = $service->waitlistPositions($request->user());
        $applications = EventApplication::query()
            ->where('student_id', $request->user()->id)
            // Undone straight after applying (Task #94): not part of the history.
            ->whereNull('undone_at')
            ->with([
                'event.owningTopic:id,title,code',
                'event.topicTag:id,name,category',
            ])
            ->orderByDesc('applied_at')
            ->get()
            ->map(function (EventApplication $app) use ($now, $positions) {
                $event = $app->event;

                return [
                    'id' => $app->id,
                    'status' => $app->status,
                    'waitlist_position' => $app->status === EventApplication::WAITLISTED ? ($positions[$app->event_id] ?? null) : null,
                    'applied_at' => $app->applied_at?->toIso8601String(),
                    'decided_at' => $app->decided_at?->toIso8601String(),
                    'withdrawn_at' => $app->withdrawn_at?->toIso8601String(),
                    'completed_at' => $app->completed_at?->toIso8601String(),
                    'scored_at' => $app->scored_at?->toIso8601String(),
                    'is_credited' => $app->isCredited(),
                    'can_withdraw' => in_array($app->status, [
                        EventApplication::PENDING,
                        EventApplication::ACCEPTED,
                        EventApplication::WAITLISTED,
                    ], true)
                        && $app->scored_at === null
                        && $event
                        && $now->lt($event->event_starts_at)
                        && in_array($event->status, [Event::STATUS_PUBLISHED, Event::STATUS_ON_HOLD], true),
                    'can_reapply' => $app->status === EventApplication::WITHDRAWN
                        && $event
                        && $event->status === Event::STATUS_PUBLISHED
                        && $now->lte($event->application_deadline)
                        && $now->lte($event->event_ends_at),
                    'event' => $event ? [
                        'id' => $event->id,
                        'title' => $event->title,
                        'description' => $event->description,
                        'location' => $event->location,
                        'category' => $event->category,
                        'points_value' => $event->points_value,
                        'event_starts_at' => $event->event_starts_at?->toIso8601String(),
                        'event_ends_at' => $event->event_ends_at?->toIso8601String(),
                        'application_deadline' => $event->application_deadline?->toIso8601String(),
                        'status' => $event->status,
                        'application_type' => $event->application_type,
                        'owning_topic' => $event->owningTopic ? [
                            'id' => $event->owningTopic->id,
                            'title' => $event->owningTopic->title,
                            'name' => $event->owningTopic->title,
                            'code' => $event->owningTopic->code,
                        ] : null,
                        'topic_tag' => $event->topicTag ? [
                            'id' => $event->topicTag->id,
                            'name' => $event->topicTag->name,
                            'category' => $event->topicTag->category,
                        ] : null,
                    ] : null,
                ];
            });

        return Inertia::render('Events/Applied', [
            'applications' => $applications,
        ]);
    }

    /**
     * The Event Form for a new event (Task #38, UC-1.3).
     */
    public function create(Request $request, EventPlacement $placement): Response
    {
        Gate::forUser($request->user())->authorize('create', Event::class);

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
            // Optional free text (Task #77): empty means no location yet.
            'location' => filled($data['location'] ?? null) ? trim($data['location']) : null,
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
            'applications_open_at' => $data['applications_open_at'] ?? null,
            'application_deadline' => $data['application_deadline'],
            'completion_deadline' => $data['completion_deadline'],
            // A surprise event stays hidden and has no QR codes: it is credited by import (Task #86).
            'is_hidden' => $hidden,
            'qr_enabled' => ! $hidden && (bool) ($data['qr_enabled'] ?? false),
            'status' => $publish ? Event::STATUS_PUBLISHED : Event::STATUS_DRAFT,
            'published_at' => $publish ? now() : null,
        ]);

        return redirect()->route('events.mine')->with(
            'success',
            $publish ? 'Your event is published.' : 'Your event is saved as a draft.',
        );
    }
}

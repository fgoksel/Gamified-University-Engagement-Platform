<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateEventRequest;
use App\Models\Event;
use App\Models\EventApplication;
use App\Models\EventEdit;
use App\Models\Topic;
use App\Models\TopicTag;
use App\Services\EventEditor;
use App\Services\EventPlacement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Editing an event after it was created (Task #68, UC-1.3 "Editing an Event").
 *
 * The page is the "Event Details" tab; "Applied Students" and "Point Crediting" are the
 * existing applicants and attendance pages, linked from the tab bar. Only people who run
 * the event may open it: everyone else gets "not found", like the other event pages.
 */
class EventEditController extends Controller
{
    /** How many edits the page lists; the table keeps them all. */
    private const HISTORY_LIMIT = 20;

    public function edit(Request $request, Event $event, EventEditor $editor, EventPlacement $placement): Response
    {
        $this->authorise($request, $event);

        $event->loadMissing('owningTopic');
        $gate = Gate::forUser($request->user());
        $locks = $editor->locks($event);
        $editable = $editor->isEditable($event);

        return Inertia::render('Events/Edit', [
            'event' => [
                'id' => $event->id,
                'status' => $event->status,
                'version' => $editor->version($event),
                'title' => $event->title,
                'description' => $event->description,
                'location' => $event->location,
                'topic_tag_id' => $event->topic_tag_id,
                'owning_topic_id' => $event->owning_topic_id,
                'audience_topic_id' => $event->audience_topic_id,
                'capacity' => $event->capacity,
                'application_type' => $event->application_type,
                'points_value' => $event->points_value,
                // Stored as typed (no zone), so the browser's datetime fields get them as they are.
                'event_starts_at' => $event->event_starts_at->format('Y-m-d\TH:i'),
                'event_ends_at' => $event->event_ends_at->format('Y-m-d\TH:i'),
                'applications_open_at' => $event->applications_open_at?->format('Y-m-d\TH:i'),
                'application_deadline' => $event->application_deadline->format('Y-m-d\TH:i'),
                'completion_deadline' => $event->completion_deadline->format('Y-m-d\TH:i'),
                'is_hidden' => $event->is_hidden,
                'qr_enabled' => $event->qr_enabled,
            ],
            'editable' => $editable,
            'locks' => (object) ($editable ? $locks : []),
            'minimumCapacity' => $editable ? $editor->minimumCapacity($event) : 1,
            'topics' => $this->topicOptions($request, $event, $placement),
            'tags' => TopicTag::query()
                ->where('is_active', true)
                ->orWhere('id', $event->topic_tag_id)
                ->orderBy('category')
                ->orderBy('name')
                ->get(['id', 'name', 'category']),
            // The other two tabs, shown only to people who may use them.
            'tabs' => [
                'applicants' => $gate->allows('manageApplicants', $event),
                // A surprise event has nothing to scan: its points are given by import (Task #86).
                'credit' => $gate->allows('credit', $event) && ! $event->is_hidden,
                'qr' => $gate->allows('manageQr', $event) && $event->qr_enabled,
            ],
            'applications' => $event->applications()->where('status', '!=', EventApplication::WITHDRAWN)->count(),
            // Who changed what and when (Task #76), newest first. The page names the fields.
            'history' => $event->edits()
                ->with('actor:id,name')
                ->limit(self::HISTORY_LIMIT)
                ->get()
                ->map(fn (EventEdit $edit) => [
                    'id' => $edit->id,
                    'at' => $edit->created_at->format('D j M Y, H:i'),
                    'by' => $edit->actor?->name,
                    'fields' => array_keys($edit->changes),
                ]),
        ]);
    }

    public function update(UpdateEventRequest $request, Event $event, EventEditor $editor): RedirectResponse
    {
        $this->authorise($request, $event);

        $data = $request->validated();
        $editor->update($request->user(), $event, $data, $data['version'] ?? null);

        return redirect()->route('events.edit', $event)->with('success', 'The event is saved.');
    }

    /**
     * People who do not run the event do not learn that it exists; people who do
     * but may not edit it are refused.
     */
    private function authorise(Request $request, Event $event): void
    {
        $gate = Gate::forUser($request->user());

        abort_unless($gate->allows('staff', $event), 404);
        abort_unless($gate->allows('update', $event), 403);
    }

    /**
     * Where the event may be managed from. A published event keeps its topic; a draft may
     * move to any topic where the person may create events.
     *
     * @return list<array{id: int, title: string, can_publish: bool, audiences: list<array{id: int, title: string}>}>
     */
    private function topicOptions(Request $request, Event $event, EventPlacement $placement): array
    {
        $options = $event->status === Event::STATUS_DRAFT ? $placement->formOptions($request->user()) : [];

        if (collect($options)->doesntContain('id', $event->owning_topic_id)) {
            $titles = Topic::query()
                ->whereIn('id', $placement->audienceIds($event->owningTopic))
                ->pluck('title', 'id');

            $options[] = [
                'id' => $event->owning_topic_id,
                'title' => $event->owningTopic->title,
                'can_publish' => true,
                'audiences' => collect($placement->audienceIds($event->owningTopic))
                    ->map(fn (int $id) => ['id' => $id, 'title' => (string) $titles[$id]])
                    ->all(),
            ];
        }

        return $options;
    }
}

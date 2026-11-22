<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateEventBadgeRequest;
use App\Models\Event;
use App\Services\EventBadges;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The badge of an event (Task #103, N10), made, changed and removed from the event's edit page.
 * Whoever may edit the event may do it: everyone else gets "not found", like the edit page.
 */
class EventBadgeController extends Controller
{
    public function update(UpdateEventBadgeRequest $request, Event $event, EventBadges $badges): RedirectResponse
    {
        $result = $badges->save($request->user(), $event, $request->validated('name'), $request->validated('description'), $request->validated('icon'));

        return back()->with('success', $result['awarded'] > 0
            ? trans_choice('events.badge.saved_and_given', $result['awarded'], ['count' => $result['awarded']])
            : __('events.badge.saved'));
    }

    public function destroy(Request $request, Event $event, EventBadges $badges): RedirectResponse
    {
        $gate = Gate::forUser($request->user());

        abort_unless($gate->allows('staff', $event), 404);
        abort_unless($gate->allows('update', $event), 403);

        $lost = $badges->remove($event);

        return back()->with('success', $lost > 0
            ? trans_choice('events.badge.removed_and_taken', $lost, ['count' => $lost])
            : __('events.badge.removed'));
    }
}

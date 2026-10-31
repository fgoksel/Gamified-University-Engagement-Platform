<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\EventApplication;
use App\Models\PointTransaction;
use App\Services\EventCreditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller for the QR Passport scanner and point crediting interface (Task #43, UC-1.7).
 */
class EventCreditController extends Controller
{
    public function __construct(private EventCreditService $service) {}

    /**
     * Show the QR Passport scanner interface for an event.
     */
    public function showScan(Request $request, Event $event): Response
    {
        abort_unless($request->user()->can('credit', $event), 403);

        $acceptedCount = EventApplication::query()
            ->where('event_id', $event->id)
            ->where('status', EventApplication::ACCEPTED)
            ->count();

        $creditedCount = PointTransaction::query()
            ->where('event_id', $event->id)
            ->where('type', PointTransaction::CREDIT)
            ->count();

        return Inertia::render('Events/Scan', [
            'event' => [
                'id' => $event->id,
                'title' => $event->title,
                'points_value' => $event->points_value,
                'category' => $event->category,
                'capacity' => $event->capacity,
                'status' => $event->status,
                'application_type' => $event->application_type,
                'owning_topic' => $event->owningTopic ? [
                    'id' => $event->owningTopic->id,
                    'title' => $event->owningTopic->title,
                    'code' => $event->owningTopic->code,
                ] : null,
            ],
            'stats' => [
                'accepted_count' => $acceptedCount,
                'credited_count' => $creditedCount,
            ],
        ]);
    }

    /**
     * Credit points to the student whose QR passport was scanned. Nothing else is accepted:
     * no typed code and no pick from a list (Task #83).
     */
    public function credit(Request $request, Event $event): JsonResponse
    {
        abort_unless($request->user()->can('credit', $event), 403);

        $data = $request->validate([
            'identifier' => ['required', 'string'],
        ]);

        return response()->json($this->service->credit($request->user(), $event, $data['identifier']));
    }
}

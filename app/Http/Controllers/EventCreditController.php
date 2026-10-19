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

        $acceptedApplications = EventApplication::query()
            ->where('event_id', $event->id)
            ->where('status', EventApplication::ACCEPTED)
            ->with('student:id,name,neptun_code')
            ->orderBy('applied_at')
            ->get();

        $creditedStudentIds = PointTransaction::query()
            ->where('event_id', $event->id)
            ->where('type', PointTransaction::CREDIT)
            ->pluck('student_id')
            ->all();

        $students = $acceptedApplications->map(function (EventApplication $app) use ($creditedStudentIds) {
            return [
                'id' => $app->student_id,
                'name' => $app->student?->name ?? 'Unknown Student',
                'neptun_code' => $app->student?->neptun_code,
                'is_credited' => in_array($app->student_id, $creditedStudentIds, true),
                'scored_at' => $app->scored_at?->toIso8601String(),
            ];
        });

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
                'accepted_count' => $acceptedApplications->count(),
                'credited_count' => count($creditedStudentIds),
            ],
            'students' => $students,
        ]);
    }

    /**
     * Credit points to a student via QR Passport scan or manual entry.
     */
    public function credit(Request $request, Event $event): JsonResponse
    {
        abort_unless($request->user()->can('credit', $event), 403);

        $data = $request->validate([
            'identifier' => ['required', 'string'],
            'source' => ['sometimes', 'string', 'in:passport,manual'],
        ]);

        $source = $data['source'] ?? 'passport';
        $result = $this->service->credit($request->user(), $event, $data['identifier'], $source);

        return response()->json($result);
    }
}

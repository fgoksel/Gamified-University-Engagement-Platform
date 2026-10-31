<?php

namespace App\Http\Controllers;

use App\Exceptions\EventActionRefused;
use App\Models\Event;
use App\Services\LiveAttendanceQr;
use App\Services\QrImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Live attendance check-in (Task #80): the organiser opens, pauses and stops the code that
 * changes every few seconds, and reads what it shows now. Needs the QR capability at the event's
 * topic (EventPolicy::manageQr). Students ask only whether check-in is open. The screens come
 * with the organiser's "Show attendance QR" view and the student's "Check-in is open" banner.
 */
class EventCheckinController extends Controller
{
    public function state(Request $request, Event $event, LiveAttendanceQr $live): JsonResponse
    {
        return $this->respond(fn () => $live->state($request->user(), $event));
    }

    public function open(Request $request, Event $event, LiveAttendanceQr $live): JsonResponse
    {
        return $this->respond(function () use ($request, $event, $live) {
            $live->open($request->user(), $event);

            return $live->state($request->user(), $event);
        });
    }

    public function pause(Request $request, Event $event, LiveAttendanceQr $live): JsonResponse
    {
        return $this->respond(function () use ($request, $event, $live) {
            $live->pause($request->user(), $event);

            return $live->state($request->user(), $event);
        });
    }

    public function stop(Request $request, Event $event, LiveAttendanceQr $live): JsonResponse
    {
        return $this->respond(function () use ($request, $event, $live) {
            $live->stop($request->user(), $event);

            return $live->state($request->user(), $event);
        });
    }

    /**
     * The code that is showing now, as a picture for a projector or a phone screen.
     */
    public function image(Request $request, Event $event, LiveAttendanceQr $live, QrImage $images): Response
    {
        abort_unless(Gate::forUser($request->user())->allows('manageQr', $event), 403);

        $session = $live->session($event);
        abort_unless($session?->isOpen(), 404);

        $size = (int) $request->query('size', QrImage::DEFAULT_SIZE);
        $text = route('qr.show', ['code' => $live->payload($session)]);

        return response($images->svg($text, $size), 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * The organiser's "Show attendance QR" screen (Task #81): the code full-screen for a projector or
     * a phone, its countdown, the live counter, pause and stop. Opening the page shows no code by
     * itself; the code appears when the organiser presses "Show attendance QR".
     */
    public function screen(Request $request, Event $event, LiveAttendanceQr $live): InertiaResponse
    {
        $this->authoriseScreen($request, $event);

        return Inertia::render('Screen/AttendanceQr', [
            'event' => [
                'id' => $event->id,
                'title' => $event->title,
                'points' => $event->points_value,
                'starts' => $event->event_starts_at->format('D j M Y, H:i'),
            ],
            'checkin' => $live->state($request->user(), $event),
        ]);
    }

    /**
     * "Show attendance QR" on the organiser's event card (Task #81): open check-in, or show the code
     * again after a pause, and go to the screen.
     */
    public function openScreen(Request $request, Event $event, LiveAttendanceQr $live): RedirectResponse
    {
        $this->authoriseScreen($request, $event);

        try {
            $live->open($request->user(), $event);
        } catch (EventActionRefused $refused) {
            return back()->with('error', $refused->getMessage());
        }

        return redirect()->route('events.checkin.screen', $event);
    }

    /**
     * For the student's event page: is check-in open right now, and are they accepted.
     */
    public function status(Request $request, Event $event, LiveAttendanceQr $live): JsonResponse
    {
        abort_unless(Gate::forUser($request->user())->allows('view', $event), 404);

        return response()->json($live->statusFor($request->user(), $event))->header('Cache-Control', 'private, no-store');
    }

    /**
     * Same rule as the QR codes page: other people's events are "not found", staff without the QR
     * capability are refused.
     */
    private function authoriseScreen(Request $request, Event $event): void
    {
        $gate = Gate::forUser($request->user());

        abort_unless($gate->allows('staff', $event), 404);
        abort_unless($gate->allows('manageQr', $event), 403);
    }

    private function respond(callable $action): JsonResponse
    {
        try {
            return response()->json($action())->header('Cache-Control', 'private, no-store');
        } catch (EventActionRefused $refused) {
            return response()->json(['message' => $refused->getMessage(), 'reason' => $refused->reason], $refused->status);
        }
    }
}

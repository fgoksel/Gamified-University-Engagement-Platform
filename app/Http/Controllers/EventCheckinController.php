<?php

namespace App\Http\Controllers;

use App\Exceptions\EventActionRefused;
use App\Models\Event;
use App\Services\LiveAttendanceQr;
use App\Services\QrImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

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
     * For the student's event page: is check-in open right now, and are they accepted.
     */
    public function status(Request $request, Event $event, LiveAttendanceQr $live): JsonResponse
    {
        abort_unless(Gate::forUser($request->user())->allows('view', $event), 404);

        return response()->json($live->statusFor($request->user(), $event))->header('Cache-Control', 'private, no-store');
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

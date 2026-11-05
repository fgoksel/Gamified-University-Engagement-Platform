<?php

namespace App\Http\Controllers;

use App\Exceptions\EventActionRefused;
use App\Models\Event;
use App\Models\QrToken;
use App\Services\QrImage;
use App\Services\QrTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Making, drawing and revoking the QR codes of an event (UC-1.8).
 * Needs the QR capability at the event's topic; creating the event is not enough.
 * Since Task #84 the only code made here is a printed single-use slip.
 */
class EventQrController extends Controller
{
    public function store(Request $request, Event $event, QrTokenService $qr): JsonResponse
    {
        $this->authorise($request, $event);

        $data = $request->validate([
            'type' => ['sometimes', Rule::in([QrToken::SLIP])],
            // Posters and the parts of a task are gone (Task #84).
            'step_number' => ['prohibited'],
            'total_steps' => ['prohibited'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ], [
            'type.in' => 'Only printed single-use slips can be made.',
        ]);

        try {
            $token = $qr->issue(
                $request->user(),
                $event,
                isset($data['expires_at']) ? now()->parse($data['expires_at']) : null,
            );
        } catch (EventActionRefused $refused) {
            return response()->json(['message' => $refused->getMessage(), 'reason' => $refused->reason], $refused->status);
        }

        return response()->json([
            'id' => $token->id,
            'type' => $token->type,
            'expires_at' => $token->expires_at?->toIso8601String(),
            'url' => $qr->url($token),
            'svg' => route('events.qr.image', [$event, $token, 'svg']),
            'png' => route('events.qr.image', [$event, $token, 'png']),
        ], 201);
    }

    public function image(Request $request, Event $event, QrToken $token, string $format, QrTokenService $qr, QrImage $images): Response
    {
        $this->authorise($request, $event);
        abort_unless($token->event_id === $event->id && $token->revoked_at === null, 404);

        $size = (int) $request->query('size', QrImage::DEFAULT_SIZE);
        $text = $qr->url($token);

        return $format === 'png'
            ? response($images->png($text, $size), 200, ['Content-Type' => 'image/png', 'Cache-Control' => 'private, no-store'])
            : response($images->svg($text, $size), 200, ['Content-Type' => 'image/svg+xml', 'Cache-Control' => 'private, no-store']);
    }

    public function revoke(Request $request, Event $event, QrToken $token, QrTokenService $qr): JsonResponse
    {
        $this->authorise($request, $event);
        abort_unless($token->event_id === $event->id, 404);

        $qr->revoke($request->user(), $token);

        return response()->json(['revoked' => true]);
    }

    private function authorise(Request $request, Event $event): void
    {
        $gate = Gate::forUser($request->user());

        abort_unless($gate->allows('staff', $event), 404);
        abort_unless($gate->allows('manageQr', $event), 403);
    }
}

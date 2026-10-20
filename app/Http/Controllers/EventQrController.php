<?php

namespace App\Http\Controllers;

use App\Exceptions\EventActionRefused;
use App\Models\Event;
use App\Models\QrToken;
use App\Services\QrTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Making and revoking the QR codes of an event (UC-1.8).
 * Needs the QR capability at the event's topic; creating the event is not enough.
 */
class EventQrController extends Controller
{
    public function store(Request $request, Event $event, QrTokenService $qr): JsonResponse
    {
        $this->authorise($request, $event);

        $data = $request->validate([
            'type' => ['required', Rule::in([QrToken::POSTER, QrToken::SLIP])],
            'step_number' => ['nullable', 'integer', 'min:1', 'max:'.QrTokenService::MAX_STEPS],
            'total_steps' => ['nullable', 'integer', 'min:2', 'max:'.QrTokenService::MAX_STEPS],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        try {
            $token = $qr->issue(
                $request->user(),
                $event,
                $data['type'],
                $data['step_number'] ?? null,
                $data['total_steps'] ?? null,
                isset($data['expires_at']) ? now()->parse($data['expires_at']) : null,
            );
        } catch (EventActionRefused $refused) {
            return response()->json(['message' => $refused->getMessage(), 'reason' => $refused->reason], $refused->status);
        }

        return response()->json([
            'id' => $token->id,
            'type' => $token->type,
            'step_number' => $token->step_number,
            'total_steps' => $token->total_steps,
            'expires_at' => $token->expires_at?->toIso8601String(),
            'url' => $qr->url($token),
        ], 201);
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

<?php

namespace App\Http\Controllers;

use App\Exceptions\EventActionRefused;
use App\Models\Event;
use App\Models\QrToken;
use App\Services\QrTokenService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Printable QR posters and slips of an event (Task #44, UC-1.8): the organiser's
 * page on top of the QR codes of Task #45 and the images of Task #46.
 *
 * Codes are issued and revoked only through QrTokenService, so the signature,
 * the capability check and the event rules live in one place. Printing uses the
 * server-drawn SVG images (EventQrController::image) on A4 sheets; the browser's
 * print dialog prints them or saves them as a PDF.
 */
class EventQrCodeController extends Controller
{
    /** The most codes one request may make, to keep a print run reasonable. */
    public const MAX_CODES = 300;

    public function __construct(private QrTokenService $qr) {}

    public function index(Request $request, Event $event): Response
    {
        $this->authorise($request, $event);

        $tokens = $event->qrTokens()->withCount('redemptions')->orderByDesc('id')->get();

        return Inertia::render('Events/QrCodes', [
            'event' => [
                'id' => $event->id,
                'title' => $event->title,
                'status' => $event->status,
                'points' => $event->points_value,
                'starts' => $event->event_starts_at->format('D j M Y, H:i'),
                'expires' => $event->completion_deadline->format('j M Y, H:i'),
            ],
            'refusal' => $this->refusalFor($event),
            'maxCodes' => self::MAX_CODES,
            'maxParts' => QrTokenService::MAX_STEPS,
            'tokens' => $tokens->map(fn (QrToken $token) => [
                'id' => $token->id,
                'type' => $token->type,
                'step_number' => $token->step_number,
                'total_steps' => $token->total_steps,
                'claims' => $token->redemptions_count,
                'revoked' => $token->revoked_at !== null,
                'expired' => $this->expired($event, $token),
                'created' => $token->created_at?->format('j M, H:i'),
            ]),
            'counts' => [
                QrToken::POSTER => $this->printable($event, QrToken::POSTER)->count(),
                QrToken::SLIP => $this->printable($event, QrToken::SLIP)->count(),
            ],
        ]);
    }

    /**
     * Make one poster, or a number of slips, for every part of the task.
     */
    public function store(Request $request, Event $event): RedirectResponse
    {
        $this->authorise($request, $event);

        $data = $request->validate([
            'type' => ['required', Rule::in([QrToken::POSTER, QrToken::SLIP])],
            'copies' => ['required_if:type,slip', 'nullable', 'integer', 'min:1', 'max:'.self::MAX_CODES],
            'parts' => ['required', 'integer', 'min:1', 'max:'.QrTokenService::MAX_STEPS],
        ], [
            'copies.required_if' => 'Say how many slips to make.',
            'parts.max' => 'A task can have at most '.QrTokenService::MAX_STEPS.' parts.',
        ]);

        $copies = $data['type'] === QrToken::POSTER ? 1 : (int) $data['copies'];
        $parts = (int) $data['parts'];

        if ($copies * $parts > self::MAX_CODES) {
            return back()->with('error', 'At most '.self::MAX_CODES.' codes can be made at once.');
        }

        try {
            // All or nothing: a refused code rolls the whole batch back.
            $made = DB::transaction(function () use ($request, $event, $data, $copies, $parts) {
                $count = 0;

                for ($part = 1; $part <= $parts; $part++) {
                    for ($i = 0; $i < $copies; $i++) {
                        $this->qr->issue(
                            $request->user(),
                            $event,
                            $data['type'],
                            $parts > 1 ? $part : null,
                            $parts > 1 ? $parts : null,
                        );
                        $count++;
                    }
                }

                return $count;
            });
        } catch (EventActionRefused $refused) {
            return back()->with('error', $refused->getMessage());
        }

        $what = $data['type'] === QrToken::POSTER ? 'poster' : 'slip';

        return back()->with('success', $made === 1 ? "1 {$what} code made." : "{$made} {$what} codes made.");
    }

    public function revoke(Request $request, Event $event, QrToken $token): RedirectResponse
    {
        $this->authorise($request, $event);
        abort_unless($token->event_id === $event->id, 404);

        $this->qr->revoke($request->user(), $token);

        return back()->with('success', 'The code no longer works. Claims already made are kept.');
    }

    /**
     * A print-only page (no menus) with A4 sheets: posters one per page,
     * slips twelve per page. The browser prints it or saves it as a PDF.
     */
    public function print(Request $request, Event $event, string $type): Response
    {
        $this->authorise($request, $event);

        return Inertia::render('Print/EventQrCodes', [
            'type' => $type,
            'event' => [
                'id' => $event->id,
                'title' => $event->title,
                'points' => $event->points_value,
                'starts' => $event->event_starts_at->format('D j M Y, H:i'),
                'owning_topic' => $event->owningTopic?->title,
                'expires' => $event->completion_deadline->format('j M Y'),
            ],
            'codes' => $this->printable($event, $type)->map(fn (QrToken $token) => [
                'id' => $token->id,
                // Server-drawn vector image of the signed scan link (Task #46).
                'image' => route('events.qr.image', [$event, $token, 'svg']),
                'step_number' => $token->step_number,
                'total_steps' => $token->total_steps,
            ])->values(),
        ]);
    }

    /**
     * Same access rule as EventQrController: other people's events are "not
     * found"; staff without the QR capability are refused.
     */
    private function authorise(Request $request, Event $event): void
    {
        $gate = Gate::forUser($request->user());

        abort_unless($gate->allows('staff', $event), 404);
        abort_unless($gate->allows('manageQr', $event), 403);
    }

    /**
     * Why no codes can be made right now (the rules of QrTokenService::issue), or null.
     */
    private function refusalFor(Event $event): ?string
    {
        return match (true) {
            $event->is_hidden => 'A surprise event has no QR codes: its points are given only by import.',
            ! $event->qr_enabled => 'QR attendance is switched off for this event. Turn it on in the event to make codes.',
            $event->status === Event::STATUS_DRAFT => 'Publish the event first, then make its QR codes.',
            $event->isOnHold() => 'This event is on hold. Restore it to make QR codes.',
            ! in_array($event->status, [Event::STATUS_PUBLISHED, Event::STATUS_COMPLETED], true) => 'This event is no longer active.',
            now()->gt($event->completion_deadline) => "This event's completion deadline has passed, so new codes would not work.",
            default => null,
        };
    }

    /**
     * A code stops working at its own expiry date, or when points can no longer be credited.
     */
    private function expired(Event $event, QrToken $token): bool
    {
        return ($token->expires_at !== null && $token->expires_at->isPast())
            || now()->gt($event->completion_deadline);
    }

    /**
     * Codes that still work, in print order.
     *
     * @return Collection<int, QrToken>
     */
    private function printable(Event $event, string $type)
    {
        return $event->qrTokens()
            ->where('type', $type)
            ->whereNull('revoked_at')
            ->orderBy('step_number')
            ->orderBy('id')
            ->get()
            ->reject(fn (QrToken $token) => $this->expired($event, $token))
            ->values();
    }
}

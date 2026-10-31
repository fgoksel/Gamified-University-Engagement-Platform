<?php

namespace App\Http\Controllers;

use App\Exceptions\EventActionRefused;
use App\Models\Event;
use App\Models\QrRedemption;
use App\Models\QrToken;
use App\Services\QrTokenService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Printed QR slips of an event (Task #84, 26 Oct Q5), the page of Task #44 with its
 * modes replaced: for an event with no screen, the organiser makes a batch of slips,
 * prints exactly that batch, hands the slips out, and sees in the log who used which
 * slip and when. Posters and the parts of a task are no longer made.
 *
 * Slips are issued and revoked only through QrTokenService, so the signature,
 * the capability check and the event rules live in one place. Printing uses the
 * server-drawn SVG images (EventQrController::image) on A4 sheets; the browser's
 * print dialog prints them or saves them as a PDF.
 */
class EventQrCodeController extends Controller
{
    /** The batch key of slips made before batches existed. */
    private const EARLIER = 'earlier';

    public function __construct(private QrTokenService $qr) {}

    public function index(Request $request, Event $event): Response
    {
        $this->authorise($request, $event);

        $slips = $this->slips($event);
        $labels = $this->batchLabels($slips);

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
            'maxSlips' => QrTokenService::MAX_SLIPS,
            // The newest batch first.
            'batches' => $slips->groupBy('batch')->reverse()->map(fn (Collection $rows, string $key) => [
                'key' => $key,
                'label' => $labels[$key],
                'made' => $rows->first()['token']->created_at?->format('j M Y, H:i'),
                'by' => $rows->first()['token']->issuer?->name,
                'total' => $rows->count(),
                'working' => $rows->where('state', 'working')->count(),
                'used' => $rows->where('state', 'used')->count(),
                'withdrawn' => $rows->where('state', 'withdrawn')->count(),
                'slips' => $rows->map(fn (array $row) => [
                    'id' => $row['token']->id,
                    'number' => $row['number'],
                    'state' => $row['state'],
                    'used_by' => $row['redemption']?->student?->name,
                    'used_at' => $row['redemption']?->redeemed_at?->format('j M, H:i'),
                ])->values(),
            ])->values(),
            'log' => $this->log($event, $slips),
            // Posters and parts of a task made before Task #84: they no longer work.
            'retired' => $event->qrTokens()->where(fn ($query) => $query->where('type', '!=', QrToken::SLIP)->orWhereNotNull('total_steps'))->count(),
        ]);
    }

    /**
     * Make a batch of slips: as many as the organiser asks for, printed on their own.
     */
    public function store(Request $request, Event $event): RedirectResponse
    {
        $this->authorise($request, $event);

        $data = $request->validate([
            'copies' => ['required', 'integer', 'min:1', 'max:'.QrTokenService::MAX_SLIPS],
        ], [
            'copies.required' => 'Say how many slips to make.',
            'copies.min' => 'Make at least 1 slip.',
            'copies.max' => 'Make at most '.QrTokenService::MAX_SLIPS.' slips at a time.',
        ]);

        try {
            $made = $this->qr->makeSlips($request->user(), $event, (int) $data['copies'])->count();
        } catch (EventActionRefused $refused) {
            return back()->with('error', $refused->getMessage());
        }

        return back()->with('success', $made === 1 ? '1 slip made. Print it from the list below.' : "{$made} slips made. Print them from the list below.");
    }

    public function revoke(Request $request, Event $event, QrToken $token): RedirectResponse
    {
        $this->authorise($request, $event);
        abort_unless($token->event_id === $event->id, 404);

        $this->qr->revoke($request->user(), $token);

        return back()->with('success', 'The slip no longer works. Points already given with it are kept.');
    }

    /**
     * A print-only page (no menus) with the slips of one batch that still work, twelve
     * per A4 page. The browser prints it or saves it as a PDF.
     */
    public function print(Request $request, Event $event, string $batch): Response
    {
        $this->authorise($request, $event);

        $slips = $this->slips($event);
        $rows = $slips->where('batch', $batch);
        abort_if($rows->isEmpty(), 404);

        return Inertia::render('Print/EventQrCodes', [
            'batch' => $this->batchLabels($slips)[$batch],
            'event' => [
                'id' => $event->id,
                'title' => $event->title,
                'points' => $event->points_value,
                'starts' => $event->event_starts_at->format('D j M Y, H:i'),
                'owning_topic' => $event->owningTopic?->title,
                'expires' => $event->completion_deadline->format('j M Y'),
            ],
            'codes' => $rows->where('state', 'working')->map(fn (array $row) => [
                'id' => $row['token']->id,
                'number' => $row['number'],
                // Server-drawn vector image of the signed scan link (Task #46).
                'image' => route('events.qr.image', [$event, $row['token'], 'svg']),
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
     * Why no slips can be made right now (the rules of QrTokenService), or null.
     */
    private function refusalFor(Event $event): ?string
    {
        return match (true) {
            $event->is_hidden => 'A surprise event has no QR codes: its points are given only by import.',
            ! $event->qr_enabled => 'QR attendance is switched off for this event. Turn it on in the event to make slips.',
            $event->status === Event::STATUS_DRAFT => 'Publish the event first, then make its slips.',
            $event->isOnHold() => 'This event is on hold. Restore it to make slips.',
            ! in_array($event->status, [Event::STATUS_PUBLISHED, Event::STATUS_COMPLETED], true) => 'This event is no longer active.',
            now()->gt($event->completion_deadline) => "This event's completion deadline has passed, so new slips would not work.",
            default => null,
        };
    }

    /**
     * Every slip of the event with its number (1, 2, 3, ... in the order they were made,
     * printed on the slip), its batch and its state:
     * used, withdrawn by the organiser, expired (past its own date or the points end date), or working.
     *
     * @return Collection<int, array{token: QrToken, number: int, batch: string, state: string, redemption: ?QrRedemption}>
     */
    private function slips(Event $event): Collection
    {
        $closed = now()->gt($event->completion_deadline);

        return $event->qrTokens()->slips()->orderBy('id')
            ->with(['issuer:id,name', 'redemptions.student:id,name'])
            ->get()
            ->values()
            ->map(function (QrToken $token, int $index) use ($closed) {
                $redemption = $token->redemptions->first();

                return [
                    'token' => $token,
                    'number' => $index + 1,
                    'batch' => $token->batch ?? self::EARLIER,
                    'state' => match (true) {
                        $redemption !== null => 'used',
                        $token->revoked_at !== null => 'withdrawn',
                        $closed || ($token->expires_at !== null && $token->expires_at->isPast()) => 'expired',
                        default => 'working',
                    },
                    'redemption' => $redemption,
                ];
            });
    }

    /**
     * "Batch 1", "Batch 2", ... in the order the batches were made, and "Earlier slips" for
     * slips made before batches existed.
     *
     * @param  Collection<int, array{batch: string}>  $slips
     * @return Collection<string, string>
     */
    private function batchLabels(Collection $slips): Collection
    {
        $number = 0;

        return $slips->pluck('batch')->unique()->mapWithKeys(function (string $key) use (&$number) {
            return [$key => $key === self::EARLIER ? 'Earlier slips' : 'Batch '.++$number];
        });
    }

    /**
     * Who used which code and when, newest first. Also the posters and parts of a task from
     * before Task #84, so no earlier claim drops out of the record.
     *
     * @param  Collection<int, array{token: QrToken, number: int}>  $slips
     * @return Collection<int, array<string, mixed>>
     */
    private function log(Event $event, Collection $slips): Collection
    {
        $numbers = $slips->mapWithKeys(fn (array $row) => [$row['token']->id => $row['number']]);

        return QrRedemption::query()
            ->whereIn('qr_token_id', $event->qrTokens()->select('id'))
            ->with(['token', 'student:id,name,neptun_code', 'pointTransaction:id,points'])
            ->orderByDesc('redeemed_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (QrRedemption $redemption) => [
                'id' => $redemption->id,
                'at' => $redemption->redeemed_at?->format('j M Y, H:i'),
                'student' => $redemption->student?->name,
                'neptun' => $redemption->student?->neptun_code,
                'code' => match (true) {
                    $numbers->has($redemption->qr_token_id) => 'Slip '.$numbers[$redemption->qr_token_id],
                    $redemption->token->isMilestone() => "Part {$redemption->token->step_number} of {$redemption->token->total_steps}",
                    default => 'Poster',
                },
                'points' => $redemption->pointTransaction?->points,
            ]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Exceptions\EventActionRefused;
use App\Imports\NeptunCodeFile;
use App\Models\Event;
use App\Models\PointTransaction;
use App\Services\EventPointsImport;
use App\Services\PointLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Give points by import" (Task #85): upload a list of Neptun codes, see who would get the
 * event's points and who is skipped and why, then give them. Open to whoever may credit
 * points for the event (EventPolicy::credit: "points.credit" at its topic). The file is
 * sent again with the second step and read afresh, so nothing is kept between the two.
 */
class EventPointsImportController extends Controller
{
    public function __construct(
        private EventPointsImport $import,
        private PointLedger $ledger,
    ) {}

    public function show(Request $request, Event $event): Response
    {
        abort_unless($request->user()->can('credit', $event), 403);

        return Inertia::render('Events/PointsImport', [
            'event' => [
                'id' => $event->id,
                'title' => $event->title,
                'points' => $event->points_value,
                'category' => $event->category,
                'is_surprise' => (bool) $event->is_hidden,
                'area' => $this->ledger->importArea($event)->title,
                'points_until' => $event->completion_deadline->format('D j M Y, H:i'),
            ],
            // Why points cannot be given right now, or null
            'refusal' => $this->ledger->refusalFor($event)?->getMessage(),
            'creditedCount' => $this->creditedCount($event),
            'maxRows' => NeptunCodeFile::MAX_ROWS,
        ]);
    }

    /**
     * What importing the file would do. Nothing is saved.
     */
    public function preview(Request $request, Event $event): JsonResponse
    {
        abort_unless($request->user()->can('credit', $event), 403);

        $plan = $this->import->assess($event, $request->user(), $this->rows($request));

        return response()->json([
            'refusal' => $plan['refusal'],
            'credit' => array_map(fn (array $row) => array_diff_key($row, ['id' => true]), $plan['credit']),
            'already' => $plan['already'],
            'skipped' => $plan['skipped'],
        ]);
    }

    /**
     * Give the points to the students the file lists who may get them.
     */
    public function store(Request $request, Event $event): JsonResponse
    {
        abort_unless($request->user()->can('credit', $event), 403);

        try {
            $result = $this->import->import($event, $request->user(), $this->rows($request));
        } catch (EventActionRefused $refused) {
            return response()->json(['message' => $refused->getMessage()], $refused->status);
        }

        $count = count($result['credited']);
        $points = $event->points_value === 1 ? '1 point' : "{$event->points_value} points";

        return response()->json([
            ...$result,
            'message' => match ($count) {
                0 => 'Nobody received points: everyone on the list was skipped or already had them.',
                1 => "1 student received {$points}.",
                default => "{$count} students received {$points} each.",
            },
            'credited_count' => $this->creditedCount($event),
        ]);
    }

    /**
     * @return list<array{line: int, code: string}>
     */
    private function rows(Request $request): array
    {
        $request->validate([
            // 2 MB: the upload limit of PHP as set up here, and far more than a list of codes needs.
            'file' => ['required', 'file', 'max:2048'],
        ], [
            'file.required' => 'Choose a file.',
            'file.uploaded' => 'The file could not be uploaded. Files up to 2 MB are accepted.',
            'file.max' => 'The file is larger than 2 MB.',
        ]);

        $file = $request->file('file');

        if (! in_array(strtolower($file->getClientOriginalExtension()), ['csv', 'txt'], true)) {
            throw ValidationException::withMessages(['file' => 'The file must be a CSV file (.csv or .txt).']);
        }

        return NeptunCodeFile::read($file);
    }

    private function creditedCount(Event $event): int
    {
        return PointTransaction::query()
            ->where('event_id', $event->id)
            ->where('type', PointTransaction::CREDIT)
            ->count();
    }
}

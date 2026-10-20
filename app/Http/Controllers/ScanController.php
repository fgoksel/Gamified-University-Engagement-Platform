<?php

namespace App\Http\Controllers;

use App\Exceptions\EventActionRefused;
use App\Services\QrTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * QR code scanner for students: confirms attendance at an event (UC-2.3).
 *
 * A code opened from the phone's own camera lands on the same page with the
 * code filled in. Opening it never gives points: only the explicit POST does.
 * The camera scanner itself comes in its own task.
 */
class ScanController extends Controller
{
    public function show(?string $code = null): Response
    {
        return Inertia::render('Scan/Index', ['code' => $code]);
    }

    public function redeem(Request $request, QrTokenService $qr): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:300']]);

        try {
            return response()->json($qr->redeem($request->user(), $data['code']));
        } catch (EventActionRefused $refused) {
            return response()->json(['message' => $refused->getMessage(), 'reason' => $refused->reason], $refused->status);
        }
    }
}

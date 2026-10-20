<?php

namespace App\Http\Controllers;

use App\Services\QrImage;
use App\Services\QrPassport;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "My QR passport" (Task #52): the code a student shows so an organiser can
 * confirm their attendance with "Scan attendance" (Task #43).
 */
class PassportController extends Controller
{
    public function show(Request $request, QrPassport $passport, QrImage $images): Response
    {
        $student = $request->user();
        $code = $passport->issue($student);

        return Inertia::render('Passport/Show', [
            'passport' => [
                // Drawn on the server (Task #46) and sent as an image, so no QR library is needed in the browser.
                'image' => 'data:image/svg+xml;base64,'.base64_encode($images->svg($code, 512)),
                'name' => $student->name,
                'neptun_code' => $student->neptun_code,
                'valid_for' => QrPassport::LIFETIME,
                'refresh_every' => QrPassport::REFRESH,
            ],
        ]);
    }
}

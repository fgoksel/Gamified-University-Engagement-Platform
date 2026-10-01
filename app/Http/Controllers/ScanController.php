<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

/**
 * QR code scanner for students: confirms attendance at an event (UC-2.3).
 * The camera scanner itself comes in the QR task.
 */
class ScanController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('Scan/Index');
    }
}

<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Event pages of the main menu. The event list and details come in the
 * event tasks; for now these pages only exist so the menu works.
 */
class EventController extends Controller
{
    /**
     * All events (UC-1.5, UC-2.3).
     */
    public function index(): Response
    {
        return Inertia::render('Events/Index');
    }

    /**
     * "My events": a teacher sees the events they organize (UC-1.3),
     * a student sees the events they applied to (UC-2.3).
     */
    public function mine(Request $request): Response
    {
        return $request->user()->hasRole(UserRole::Teacher)
            ? Inertia::render('Events/Organized')
            : Inertia::render('Events/Applied');
    }
}

<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Leaderboard: home page of teachers and students (UC-1.2, UC-2.2).
 * The rankings themselves come in the leaderboard task (#54).
 */
class LeaderboardController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        // Admins work in the Filament panel
        if ($request->user()->hasRole(UserRole::Admin)) {
            return redirect('/admin');
        }

        return Inertia::render('Leaderboard/Index');
    }
}

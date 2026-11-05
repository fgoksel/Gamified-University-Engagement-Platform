<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Services\OwnLeaderboards;
use App\Services\StudentHome;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The start page "/". Students get their home page (Task #92): points this semester, the next
 * event, events open for applications and recent points. Everyone else gets their own
 * leaderboard (Task #54, UC-1.2): the leaderboards they may see.
 */
class LeaderboardController extends Controller
{
    public function index(Request $request, StudentHome $home, OwnLeaderboards $own): Response|HttpResponse
    {
        $user = $request->user();

        // Admins work in the Filament panel
        if ($user->hasRole(UserRole::Admin)) {
            return Inertia::location('/admin');
        }

        if ($user->hasRole(UserRole::Student)) {
            return Inertia::render('Home', [
                'name' => $user->name,
                ...$home->for($user),
            ]);
        }

        return Inertia::render('Leaderboard/Index', $own->for($user));
    }
}

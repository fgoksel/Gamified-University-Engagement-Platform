<?php

namespace App\Http\Middleware;

use Closure;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin panel: an administrator who still has a temporary password (e.g. the
 * one from AdminUserSeeder) must choose their own on the Profile page before
 * using any other admin page (Task #17).
 */
class EnsureAdminPasswordIsChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user?->must_change_password) {
            return $next($request);
        }

        $panelId = Filament::getCurrentPanel()?->getId() ?? 'admin';

        // The Profile page and logging out stay available.
        if ($request->routeIs("filament.{$panelId}.auth.profile", "filament.{$panelId}.auth.logout")) {
            return $next($request);
        }

        Notification::make()
            ->title('Please change your temporary password first.')
            ->warning()
            ->send();

        return redirect(Filament::getProfileUrl());
    }
}

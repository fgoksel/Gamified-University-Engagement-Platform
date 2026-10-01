<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Saves the light / dark theme picked in the user menu to users.appearance,
 * so it is loaded again at the next login, on any device.
 */
class AppearanceController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'appearance' => ['required', Rule::in(['light', 'dark'])],
        ]);

        $request->user()->update($validated);

        return back();
    }
}

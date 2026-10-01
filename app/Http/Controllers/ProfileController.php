<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

/**
 * Profile Settings page of teachers and students.
 * The forms (profile data, password, appearance) are added in the next step.
 */
class ProfileController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('Profile/Edit');
    }
}

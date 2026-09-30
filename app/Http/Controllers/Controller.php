<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

/**
 * Base controller. Controllers call $this->authorize() for resource-level
 * checks, on top of the role / permission middleware on their routes
 * (Technical Specification 8.2).
 */
abstract class Controller
{
    use AuthorizesRequests;
}

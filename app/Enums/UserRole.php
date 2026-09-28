<?php

namespace App\Enums;

/**
 * The four user roles from Technical Specification sections 6.4 and 8.2.
 *
 * Use these cases instead of typing role names by hand, e.g.
 * $user->assignRole(UserRole::Teacher) or $user->hasRole(UserRole::Admin).
 */
enum UserRole: string
{
    /** System Administrator (UC-3.x) */
    case Admin = 'admin';

    /** Teacher / Organizer / Subject Area Administrator (UC-1.x) */
    case Teacher = 'teacher';

    /** Student (UC-2.x) */
    case Student = 'student';

    /** External observer of the public leaderboard (UC-4.x) */
    case Observer = 'observer';
}

<?php

namespace App\Enums;

/**
 * Rights a teacher or co-teacher can give to each tutor. They apply at the
 * tutor's unit and every unit below it.
 */
enum TutorPermission: string
{
    case CreateEvents = 'create_events';

    case SelectApplicants = 'select_applicants';

    case CreditPoints = 'credit_points';
}

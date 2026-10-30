<?php

return [
    /*
    | The live attendance QR code (Task #80, meeting of 26 Oct, section 3; team decision of 27 Oct, S5).
    | They are settings, not numbers hidden in the code. The values are forced into the ranges
    | below, so a typo in .env cannot give a photo of the code time to travel to a friend.
    */

    // Seconds one code is shown before the next one replaces it. Default 5, between 3 and 8.
    'step_seconds' => env('CHECKIN_STEP_SECONDS', 5),

    // Seconds the code that was showing a moment ago still works after a new one appears, for the
    // time a phone needs to read the code and send it. Default 2, between 0 (a code works only
    // while it is showing) and 2. Never a whole extra step.
    'margin_seconds' => env('CHECKIN_MARGIN_SECONDS', 2),
];

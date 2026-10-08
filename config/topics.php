<?php

return [
    /*
    | Operational limits of the topic tree. They are explicit settings, not
    | hidden academic levels: any topic may sit at any depth up to this limit.
    */
    'max_depth' => (int) env('TOPICS_MAX_DEPTH', 32),

    // Rows per page in the "Whole branch" view and search results.
    'page_size' => 25,

    // Minutes a two-step confirmation stays valid.
    'confirmation_minutes' => 10,
];

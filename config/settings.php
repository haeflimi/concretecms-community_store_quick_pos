<?php

// Defaults; the dashboard page (Store › Quick POS) saves overrides.
return [
    'badge' => [
        // a read must have at least this many characters (after removing everything but letters and digits)
        'min_length' => 6,
        // fragments with up to this many characters cut off still identify the badge (readers drop characters)
        'max_missing' => 3,
    ],
    'kiosk' => [
        // seconds a kiosk customer stays identified on the server, however active
        'session_max' => 600,
        // unknown badges per device within 60 s before the device is locked
        'max_failures' => 5,
        // seconds the device stays locked
        'lock_seconds' => 30,
    ],
];

<?php

return [
    /*
    | Base URL for locations-feed HTTP calls (no trailing slash).
    | Defaults to http://127.0.0.1:{PORT} — same container as artisan serve + schedule:work.
    */
    'api_base_url' => env('LOCATIONS_FEED_API_BASE_URL'),

    'daily_stagger_ms' => (int) env('LOCATIONS_FEED_DAILY_STAGGER_MS', 3000),

    'invalid_resubmit_rate' => (float) env('LOCATIONS_FEED_INVALID_RESUBMIT_RATE', 0.1),
];

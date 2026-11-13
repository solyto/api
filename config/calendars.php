<?php

return [

    /*
    |--------------------------------------------------------------------------
    | External CalDAV Calendars
    |--------------------------------------------------------------------------
    |
    | External calendars are proxied to the user's own CalDAV server; no copy
    | of their events is stored. Every remote call is bounded by the timeout
    | (connect and response, seconds). Remote changes made elsewhere never
    | invalidate our caches, so event caches for external calendars expire
    | after cache_ttl seconds. Remote responses larger than
    | max_response_bytes are refused.
    |
    */

    'external' => [
        'timeout' => (int) env('CALENDARS_EXTERNAL_TIMEOUT', 5),
        'cache_ttl' => (int) env('CALENDARS_EXTERNAL_CACHE_TTL', 300),
        'max_response_bytes' => (int) env('CALENDARS_EXTERNAL_MAX_RESPONSE_BYTES', 10 * 1024 * 1024),
    ],

];

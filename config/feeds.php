<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Item Retention
    |--------------------------------------------------------------------------
    |
    | How many days of feed items are kept before being pruned. Feeds are
    | re-synced hourly, so this only bounds how far back a user can scroll;
    | it does not affect what gets fetched. Raising it costs storage, lowering
    | it means a sync outage longer than this window empties a feed entirely.
    |
    */

    'retention_days' => (int) env('FEEDS_RETENTION_DAYS', 5),

];

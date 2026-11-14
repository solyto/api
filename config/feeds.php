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

    /*
    |--------------------------------------------------------------------------
    | Subscription Limit
    |--------------------------------------------------------------------------
    |
    | How many feeds one user can subscribe to. Every subscribed feed is
    | fetched by the hourly sync, so this bounds what a single account can
    | make the server fetch. Manual subscriptions over the limit get a 422;
    | an OPML import marks the feeds past it as limit_reached.
    |
    */

    'max_subscriptions' => (int) env('FEEDS_MAX_SUBSCRIPTIONS', 1000),

];

## Summary

Added an optional `feed_id` (UUID) filter to `GET /api/v1/feeds/items` so the frontend can load one feed's items in a single paged request instead of walking all feeds. Paging and `has_more` apply to the filtered list; `meta.feed_counts` still counts all feeds.

## Changes

TASK-1: `ListFeedItemsRequest` (new, validates `feed_id` as uuid), `FeedController::listItems` (passes the filter, OpenAPI params), `FeedService::getAggregatedItems` (filters after counting), two tests in `FeedItemsListTest.php`.

## Known issues / follow-ups

none

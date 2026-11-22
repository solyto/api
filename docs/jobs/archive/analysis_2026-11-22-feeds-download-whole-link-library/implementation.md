# Implementation: 2026-11-22-feeds-download-whole-link-library

id: analysis
developer: winifred
date: 2026-11-22

## Summary

API part (A) of the scope agreed in the room: `GET /feeds/items` returns
`saved_link_id` (link UUID or `null`) per item, so the frontend no longer has to
load the whole link library on `/feeds` to un-bookmark. Frontend parts (B, C)
live in the app repo.

## Changes

TASK-1 (A): `FeedService::getAggregatedItems` looks up the user's library links
whose URL exactly equals each item's `link`, one `forUser` query per 500 URLs
(after the per-feed cache read, so nothing user-specific is cached); on
duplicate URLs the newest link wins. `FeedItemResource` exposes
`saved_link_id` and its OpenAPI schema documents it. Additive field (rule 13.2).
Tests in `app/Api/Feeds/Tests/FeedItemsListTest.php`: match, no match, other
user's link ignored, newest wins on duplicates; existing shape test updated.

TASK-2: client contract tests in `tests/Unit/ClientContractTest.php` for what
frontend builds since 1.0.15 read (references supplied from the app repo at tag
1.0.15): `GET /feeds/items` item fields plus `has_more`/`feed_counts` meta,
`GET /libraries/links` fields (id, url, title, is_favorite, created_at,
category, tags), `GET /libraries/links/categories` fields, and the status of
link create (2xx JSON body) and delete (2xx). Also pinned for the next frontend release: `data.id` on link create and `saved_link_id` on feed items.

## Known issues / follow-ups

- Exact URL match only (no normalization of trailing slash, scheme, `utm_`).
- `POST /libraries/links` doesn't dedupe by URL; decided out of scope.
- `LibraryLink` OpenAPI schema types `id` as integer, but it's a UUID.

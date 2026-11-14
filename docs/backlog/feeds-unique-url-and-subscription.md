# Backlog: unique keys for feed URLs and subscriptions

created: 2026-11-14

## What

Add a unique key on `feeds.url` and on `feed_subscriptions(user_id, feed_id)`,
after removing the duplicates that already exist.

- Find duplicate `feeds` rows (same URL), pick one per URL, point every
  subscription and every `feed_items` row of the others at it, then delete the
  others.
- Find duplicate subscriptions (same user and feed), keep one per pair
  (decide which: oldest, or the one with settings such as `whitelist`), delete
  the rest.
- Then add the unique keys in a migration. `feeds.url` can be long; check its
  column length first. If it is too long for an index on older MariaDB, use a
  hash column as `external_calendars.remote_url_hash` does
  (`2026_11_14_000001_add_indexes_to_external_calendars_table.php`).
- Where the code creates feeds or subscriptions (`FeedService::subscribe`,
  `OpmlImportService`), catch the unique violation and reuse the existing row.

## Why

Two concurrent subscribes to the same URL (two tabs, a double click, an OPML
import next to a manual subscribe) each pass the "does it exist?" check and
create their own `feeds` row or subscription. The result is a feed fetched
twice per sync, or a user subscribed twice with duplicate items. Found in the
review of the changes since 1.1.2 (2026-11-14); the gap predates 1.1.2.

## Notes

- The dedupe changes existing data, so the migration needs a
  `MigrationCompatibilityTest` allowlist entry with a reason, and the owner
  should see it before it runs (hard rule).
- Check: `SELECT url, COUNT(*) FROM feeds GROUP BY url HAVING COUNT(*) > 1`
  and the same for `feed_subscriptions(user_id, feed_id)` return nothing,
  and both unique keys exist.

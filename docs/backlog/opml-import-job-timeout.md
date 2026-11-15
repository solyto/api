# Backlog: OPML import can outlive its job timeout and stale window

created: 2026-11-15

## What

A large OPML import (up to 500 feeds) runs in a single job,
`ImportFeedSubscriptions` (`app/Api/Feeds/Jobs/ImportFeedSubscriptions.php`,
`$timeout = 300`, `$tries = 1`). `OpmlImportService::run()`
(`app/Api/Feeds/Services/OpmlImportService.php:149-202`) fetches the new feeds
in chunks of 25 and saves the state after each chunk. An import counts as dead
when its state hasn't been saved for `STALE_AFTER = 300` seconds (`:40`,
`isRunning()` at `:321`).

The comment at `:37-39` estimates the worst case per chunk at 125 s. It leaves
out:

- **DNS:** `OutboundUrlGuard::resolveDns()` (`app/Shared/Services/OutboundUrlGuard.php`)
  resolves each host one after another before a chunk's request pool, with
  `gethostbynamel` and `dns_get_record`. Neither has a timeout we control; a
  dead domain with broken DNS can take about 10 s or more.
- **Redirects:** `FeedReader::fetchMany()` follows up to 5 redirects, one pool
  per hop, each with its own 5 s timeout.
- **Broken compression:** feeds whose body can't be decoded are fetched a
  second time.

The requests within a chunk do run in parallel, so a normal import is fast.
The problem is an old OPML file with many dead domains or slow DNS.

## Why

Two failure modes, both only with real queue workers (tests run the queue
synchronously):

1. **The whole job takes longer than 300 s.** The worker kills it, `failed()`
   (`:205-220`) marks every feed not yet tried as `Unreachable` and finishes the
   import. The user sees feeds as "Didn't respond" that were never requested.
2. **One chunk takes longer than 300 s without a save.** `isRunning()` then
   treats the import as dead, so the user can upload a new file (`start()`,
   check at `:85`). The old job is still running and keeps saving its own
   `$state` after each chunk, because `run()` never reads the state again. It
   overwrites the new import, and the user's new upload is lost.

Found in the 1.1.3 release review (2026-11-15). Not seen in practice; manual
tests with a small OPML file worked.

## Options

**A. One job per chunk** (moderate)

- `run()` dispatches a job per chunk (a chain or a `Bus::batch`), each with
  its own timeout. The state is saved between jobs anyway.
- Removes the total-time problem. The stale window then only has to cover one
  chunk.

**B. Guard against an outdated job** (small, also useful with A)

- Give each import an ID (in `FeedImportStateDTO`), pass it to the job, and
  have `run()` re-read the state before each save and stop when the ID no
  longer matches. Fixes failure mode 2 regardless of timing.

**C. Bound the DNS time** (small to moderate)

- Resolve the hosts of a chunk in parallel, or skip hosts whose lookup takes
  too long, so one chunk has a real upper bound. Then correct the comment at
  `:37-39`.

**D. Honest status for untried feeds** (small, app change)

- In `failed()`, mark feeds that were never requested with a separate status
  (e.g. `not_tried`) instead of `Unreachable`. Needs a label in the app's six
  locales.

## Notes

- Related: each new subscription dispatches a `SyncFeed` job
  (`FeedService.php:182`), so a 500-feed import puts up to 500 jobs on the
  shared default queue, ahead of mail and notification jobs. Worth a separate
  queue for feed syncs if large imports become common.
- Check: an import of an OPML file with 500 dead domains on a real queue
  worker finishes with every feed actually tried, and a second upload during
  a slow import is never overwritten.

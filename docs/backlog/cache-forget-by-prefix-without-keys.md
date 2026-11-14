# Backlog: clear cache prefixes without `KEYS`

created: 2026-11-14

## What

`UserCacheService::forgetByPrefix()` (`app/Shared/Services/UserCacheService.php:113-135`)
finds the keys to delete with Redis `KEYS prefix*`. Replace that with one of
the options below.

Callers (all `calendar_events_{user}_{calendar}_…` except the last):

- `CalendarService` (13×): every event create/update/delete/move, occurrence
  changes, sharing and invite actions
- `CacheInvalidationPlugin` (2×): DAV event writes, sharees on calendar delete
- `InvalidateSharedCalendarCache`: once per member of a shared calendar
- `CalendarImport`
- `UserService:282`: account deletion, once per cache key type (rare)

## Why

`KEYS` walks every key in the Redis database (`cache` connection, DB 1: the
`user_data` store and the default cache) and blocks the whole Redis server
while it runs, so queues (DB 0) and sessions (DB 2) on the same instance wait
too. It runs on every calendar event write. The cost grows with the total
number of keys across all users, not with the user's own keys. Harmless at
small sizes; at 10,000 users it would be noticeable. Present since the
initial commit; found in the 1.1.2 review (2026-11-14).

First check how urgent it is on production:

- `redis-cli -n 1 DBSIZE`: number of keys `KEYS` has to walk
- `redis-cli SLOWLOG GET 20`: whether `KEYS` calls already show up as slow

## Options

**A. `SCAN` instead of `KEYS`** (~10 lines)

- Same search in chunks (about 1,000 keys per call), so Redis is never
  blocked.
- Total work per clear stays the same (all keys), plus one round trip per
  chunk: the writing request gets a bit slower on a large Redis; everyone
  else no longer waits. Never worse than today for the system as a whole.
- Pitfall: phpredis applies `OPT_PREFIX` to `KEYS` patterns automatically
  (the current code relies on that) but not to `SCAN` unless `SCAN_PREFIX`
  is set; `SCAN_RETRY` is needed so empty chunks don't end the loop. Get
  this wrong and nothing is cleared, silently.

**B. Key index per prefix** (moderate, contained in `UserCacheService`)

- When a calendar month entry is stored, also `SADD` its key to
  `calendar_events_{user}_{calendar}_keys` (with an `EXPIRE` up to the
  longest month TTL), in the same pipeline/transaction. Clearing reads the
  set (`SMEMBERS`), deletes those keys and the set. Reads are unchanged; all
  other caches (cleared by exact key) are unchanged.
- Cost no longer depends on the number of users.
- Downsides:
  - a second structure to keep consistent; use `MULTI` so a key is never
    stored without its index entry (otherwise it stays stale until its TTL,
    up to 7 days for past months)
  - month keys cached before the deploy are in no index: change the key
    name once (e.g. `calendar_events_v2`) so old keys are ignored and expire
  - account deletion (`UserService:282`) clears every key type by prefix;
    keep that on `SCAN` (rare) rather than indexing everything

**C. Version in the key** (small to moderate)

- Key contains a per-user/calendar counter
  (`…_{user}_{calendar}_v{n}_{month}`); clearing is one `INCR`.
- Reads need the counter first; old keys stay in memory until their TTL
  (up to 7 days), so memory grows with the write rate. Doesn't fit growth
  well.

## Notes

- Not a fix for any option: a request rebuilding a month while a write
  clears it can store outdated data afterwards. That race exists today too.
- Tests run on the in-memory cache, where `forgetByPrefix` returns early
  (`UserCacheService.php:117`), so the real path is untested today. Whatever
  option is chosen must be checked once against a real Redis (locally or
  staging), or get a test that runs against Redis.
- Check: no `->keys(` call in `app/` (`grep -rn '\->keys(' app` → 0), and a
  calendar event write on a Redis with many keys shows no `KEYS` entry in
  `SLOWLOG`.

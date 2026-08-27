# Verdict: add new long-term cache store

id: tunnel
status: open
reviewer:
date: 2026-08-25

<!-- Produced by @reviewer and/or @security after implementation. -->

## Review

TASK-1: PASS
notes: config/database.php added a `persistent_cache` redis connection
(lines 133-142) mirroring the existing `cache`/`session` shape, with DB index
read from `REDIS_PERSISTENT_CACHE_DB` (default `3`). Existing
`default`/`cache`/`session` connections untouched. Default index 3 is the next
free index after default(0)/cache(1)/session(2), so no collision.

TASK-2: PASS
notes: config/cache.php added a `longterm` store (lines 52-60) with
`driver => redis` and `connection => persistent_cache`, following the existing
store shape. The existing `user_data` store was kept unchanged (still on the
`cache` connection), so the change is purely additive — nothing that previously
existed was modified. Repository-wide grep confirms no code references the
`longterm` store yet; it is available for callers that opt in.

TASK-3: PASS
notes: No consumer was repointed. `UserCacheService::STORE_NAME` remains
`user_data`, so how user data is cached is unchanged. All store/get/has/
remember/forget/forgetByPrefix methods keep using the `user_data` store as
before.

TASK-4: PASS
notes: Audit confirmed in config/cache.php: the default `redis` store
(lines 62-66) still uses connection `CACHE_REDIS_CONNECTION` default `cache`
(DB 1), `user_data` (lines 48-51) and `conversation_state` (lines 42-46) still
use connection `cache`. Passkey challenges / import progress / user-data cache /
bot state remain on the ephemeral DB 1; no long-term data flows through them. A
deployment-side flush of DB 1 is safe.

TASK-5: PASS
notes: tests/TestCase.php overrides `cache.stores.user_data` (kept as-is) and
additionally overrides `cache.stores.longterm` to the in-memory `array` driver
(serialize => false), mirroring the existing overrides. CI stays on array
stores.

TASK-6: PASS
notes: Documented via comments in config/database.php (lines 133-135) and
config/cache.php (lines 52-55) noting which DB is ephemeral (to flush) vs
long-term. Verified no `.env.example` exists in the repo (glob returned no
files), so config comments are the appropriate doc surface.

## Security

None. No secrets touched; env var default is a non-sensitive DB index.

## Overall

APPROVED

The implementation adds a long-term Redis cache store additively: a new
`persistent_cache` Redis connection (DB 3) and `longterm` cache store, without
changing any existing store or `UserCacheService` behaviour. The ephemeral DB (1,
`cache` connection) remains as before, tests route the new store to an
in-memory array store, and the split is documented for deployment tooling.
Commit discipline is clean: each task has its own `[tunnel] TASK-N:` commit and
implementation.md has its own commit.

Non-blocking observation (not required for merge): `tasks.md` — the analyst's
task breakdown — is modified but uncommitted in the working tree (the committed
version is still the scaffold). Consider committing it for provenance, but it
does not affect the implementation.

Deployment coordination (out of scope, flagged in implementation.md) must set
`REDIS_PERSISTENT_CACHE_DB` on the server and configure the deployment to flush
DB 1 before relying on the split in production.
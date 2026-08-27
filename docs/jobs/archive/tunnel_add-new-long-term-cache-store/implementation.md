## Summary

Added a new dedicated long-term Redis cache store (`longterm`) so data that must
survive a deployment can live on its own Redis DB, separate from the ephemeral
`cache` DB that deployment tooling can flush. The change is purely additive:
the existing cache stores and `UserCacheService` behaviour are untouched — the
new `longterm` store is available for callers that opt in.

## Changes

TASK-1: Added a `persistent_cache` Redis connection to `config/database.php`
mirroring the existing `cache`/`session` shape, backed by its own DB index read
from `REDIS_PERSISTENT_CACHE_DB` (default `3`). Existing connections untouched.

TASK-2: Added a `longterm` cache store in `config/cache.php` whose `driver` is
`redis` and `connection` is `persistent_cache`, following the existing store
shape. The existing `user_data` store was left exactly as it was (still on the
`cache` connection) — nothing that previously used it was changed.

TASK-3: No consumer was repointed. `UserCacheService::STORE_NAME` remains
`user_data`; how user data is cached is unchanged. The new `longterm` store is
simply available for future use.

TASK-4: Audit-only. Confirmed the ephemeral stores remain on the existing
`cache` connection: the default `redis` store (used by passkey challenges via
`Cache::put/get`, and calendar/contact import progress), the `user_data` store,
and the `conversation_state` bot-state store. No long-term data flows through
them, so a deployment-side flush of the `cache` DB is safe. No code change
needed.

TASK-5: Updated `tests/TestCase.php` to also override the `longterm` store to
the in-memory `array` driver (mirroring the existing `user_data` /
`conversation_state` overrides), keeping CI on array stores.

TASK-6: Documented the new `REDIS_PERSISTENT_CACHE_DB` env var and the
`longterm` store via comments in `config/database.php` and `config/cache.php`,
noting which Redis DB is ephemeral (to flush) and which is long-term. No
`.env.example` exists in the repo, so config comments are the doc surface.

## Known issues / follow-ups

- The deployment tooling (solyto/deployment, out of scope) still needs to be
  told to flush the ephemeral `cache` DB (Redis DB index 1 via
  `REDIS_CACHE_DB`) and to configure `REDIS_PERSISTENT_CACHE_DB` (default 3) so
  the long-term store is populated on the correct DB index. Nothing currently
  writes to the `longterm` store yet.
- Pre-existing test failures unrelated to this change are present in this
  environment: `LibraryServiceTest` AiService tests fail because the OpenAI API
  key (read via Docker secrets) is unavailable, and `AuthTest` password-reset
  tests fail because the app hash key is null. Cache-related tests
  (JobTest, ClipboardApiTest) pass.
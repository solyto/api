# Brief: Every authenticated request writes to `personal_access_tokens`

type: feature
id: leadership
branch: feature/leadership_every-authenticated-request-writes-to-personal-access-tokens
date: 2026-11-10
author: Leander Muskalla

Source report: `docs/reports/token-last-used-write-per-request-2026-11-10.md`

## Summary

**Every authenticated API call, including every `GET`, runs an `UPDATE` on the default (MariaDB) connection before the controller starts.** This is Sanctum's default `last_used_at` tracking. The column is only read in two places, and both need minutes or days of precision, not seconds. Throttling the write (e.g. at most once per 5 minutes per token) removes almost all of these writes. It needs no package, no migration, and no change to the response contract.

No earlier report covers this. `bot-traffic-detection-2026-10-14.md` and `frontend-backwards-compatibility-2026-10-14.md` mention `last_used_at` only as a data source.

## 1. What happens today

I logged the queries of one `GET /api/v1/todos/categories` with a bearer token and `X-Client-Version` (temporary Pest test, deleted afterwards):

```
select * from "personal_access_tokens" where "id" = ? limit 1          -- Sanctum findToken
select * from "users" where "id" = ? limit 1                           -- tokenable
update "personal_access_tokens" set "last_used_at" = ?, "updated_at" = ? where "id" = ?   -- every request
select * from "todo_categories" where "user_id" = ?                    -- the actual endpoint
update "personal_access_tokens" set "client_version" = ?, ... where "id" = ?             -- only when the version changes
```

- The `last_used_at` write comes from `Laravel\Sanctum\Guard::updateLastUsedAt` (`vendor/laravel/sanctum/src/Guard.php:56-58`). It always runs because `config/sanctum.php` has no `last_used_at` key, and the provider defaults it to `true` (`SanctumServiceProvider.php:112`).
- `now()` changes every second, so the `save()` is never a no-op. It also bumps `updated_at`.
- `RecordClientVersion` (`app/Shared/Middleware/RecordClientVersion.php:28-36`) already does a conditional token update *after* the request, but only when the version changed. On an app update, that request writes the same row twice.

So 1 of the 3 queries that every request pays for auth is a write. On a typical endpoint that is 1 of 4 queries in total.

## 2. Why it matters

- **Writes cost more than reads on MariaDB/InnoDB.** Each autocommitted `UPDATE` takes a row lock and writes redo log. With the default `innodb_flush_log_at_trx_commit=1`, that also means an fsync per request. A read-only `GET` that could be served from the buffer pool now waits on disk.
- **Parallel requests from one client contend on the same row.** A frontend page load that fires several requests at once with the same token makes them all update the same `personal_access_tokens` row. They queue on its row lock one after another.
- **Widgets and polling multiply it.** The `widgets.*` routes allow 60 requests per minute per token (`AppServiceProvider.php:83-87`). Each widget token is its own row (`parent_token_id`), so every poll is a write.
- **It blocks future read scaling.** If the API ever reads from a replica, every request still needs the primary for this write.

## 3. Who reads `last_used_at`

Only two places in `app/`:

| Reader | Use | Precision needed |
|---|---|---|
| `AuthService::listTokens` (`app/Api/Users/Services/AuthService.php:185`) | "last used" on the session/devices list | minutes |
| `StatisticsService::overview` (`app/Api/Statistics/Services/StatisticsService.php:39-41`) | active users = token used in the last 30 days | days |

No scheduled job prunes tokens by `last_used_at` (`routes/console.php`), and no test asserts on the token's `last_used_at`. The one test hit (`PasskeyTest.php`) is about passkeys, a different table.

## 4. Suggestion

Turn Sanctum's tracking off and record the timestamp in the middleware that already updates the token, throttled:

1. `config/sanctum.php`: add `'last_used_at' => false`. Sanctum then skips `updateLastUsedAt`.
2. `RecordClientVersion::handle` (after `$next`): build one update array. Include `last_used_at => now()` when `$token->last_used_at` is `null` or older than a threshold (5 minutes is enough for both readers). Include `client_version` when it changed. If the array is not empty, run **one** `PersonalAccessToken::whereKey(...)->update([...])`. Keep the query update rather than `save()`, for the reason already noted in the middleware's comment (logout/refresh delete the token during the request).
3. Possibly rename the middleware (e.g. `TouchAccessToken`), since it would then do more than record the version. That's an owner call. Keeping the name avoids touching `bootstrap/app.php`.

Effect: one write per token per 5 minutes instead of one per request. On the first request after an app update, one write instead of two. Reads stay unchanged.

Things for the follow-up job to watch:

- The update must still be skipped when there is no `PersonalAccessToken` (e.g. `TransientToken` from a session, or unauthenticated routes). The current `instanceof` check already covers this.
- `RecordClientVersion` runs in the `api` group, so it also covers the public auth routes. There `$request->user()` is null, and nothing changes.
- Tests: one test that two requests within the threshold produce one `last_used_at` write, and one that a request after the threshold updates it (use `travel()`). Also a test that a version change plus a stale timestamp produce a single `UPDATE`.
- Alternative if the owner prefers to keep Sanctum's guard untouched: subclass `PersonalAccessToken` and register it with `Sanctum::usePersonalAccessTokenModel()`, skipping the save when `last_used_at` is recent. This adds a model class the codebase doesn't have today, so the middleware variant fits STANDARDS' "no new abstractions" better.

## 5. Not covered

- I didn't measure on MariaDB. The in-memory SQLite test only proves the query count. The latency gain depends on the production disk and InnoDB flush settings. Pulse's slow-query recorder in production would show it.
- The `select * from users` per request is expected (the user is needed) and is not part of this suggestion.

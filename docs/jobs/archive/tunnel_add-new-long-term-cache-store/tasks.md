# Tasks: add new long-term cache store

id: tunnel
status: open
analyst:
date: 2026-08-25

<!-- Produced by @analyst from brief.md. -->

## Task breakdown

TASK-1: Add a new dedicated long-term Redis connection (e.g. `longterm`) in
`config/database.php`'s `redis` block, backed by its own DB index read from a
new env var (e.g. `REDIS_LONGTERM_DB`, default `3`), mirroring the existing
`cache`/`session` connection shape.
     files: config/database.php
     depends: none
     risk: low — additive config only; existing connections are untouched, so no
     runtime behavior changes until a store points at it.

TASK-2: Add a new long-term cache store in `config/cache.php` (e.g.
`user_data`/`longterm`) whose `driver` is `redis` and `connection` points to
the new long-term Redis connection, following the existing
`conversation_state`/`user_data` store shape.
     files: config/cache.php
     depends: TASK-1 (store references the new connection name)
     risk: low — additive config; nothing uses it yet until a caller is repointed.

TASK-3: Route the long-lived user-data cache to the long-term store by
repointing `App\Shared\Services\UserCacheService::STORE_NAME` (currently
`user_data`) to the new long-term store name, so library recommendations /
release caches survive a deployment that flushes only the ephemeral cache DB.
     files: app/Shared/Services/UserCacheService.php, config/cache.php
     depends: TASK-2 (store must exist before being referenced)
     risk: medium — changes which Redis DB holds long-term data; if the ephemeral
     DB is flushed and the long-term DB is misconfigured on the server, cached
     data would be repopulated or briefly missing; verify DB index mapping.

TASK-4: Keep the ephemeral stores (`conversation_state` bot state and the
default `redis` store used for passkey challenges / calendar & contact import
progress) on the existing `cache` connection, confirming no long-term data is
written through them so a deployment-side flush of that DB is safe.
     files: config/cache.php, config/database.php (verification only)
     depends: TASK-2, TASK-3
     risk: low — audit-only confirmation; only acts if a store mapping needs a
     tweak to stay ephemeral.

TASK-5: Update the test bootstrap (`tests/TestCase.php`) so the long-term
store is overridden to the in-memory `array` driver (mirroring the existing
`user_data`/`conversation_state` overrides), and adjust any references if the
store name changed.
     files: tests/TestCase.php
     depends: TASK-3 (store name/connection changes land first)
     risk: low — test-only; keeps CI on array stores as today.

TASK-6: Document the new env var and store in the appropriate config/doc
surface (e.g. `.env.example` if present, or a comment in `config/database.php`
/ `config/cache.php`) so deployment tooling (solyto/deployment, out of scope)
knows which Redis DB is the ephemeral one to flush and which is long-term.
     files: config/database.php, config/cache.php (comments), possibly .env.example
     depends: TASK-1, TASK-2
     risk: low — documentation only.

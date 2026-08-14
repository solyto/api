# Solyto API

<!--
This is your project context, loaded by the agent at the start of every session.
manigot is vendor-agnostic: it runs Claude Code or OpenCode against the same
project (`mg --profile claude-pro` vs `mg --profile zai`/`--profile
opencode-go`), and this one file serves both — manigot mounts it read-only
wherever the selected tool looks for it
(/workspace/AGENTS.md for OpenCode, /workspace/.claude/CLAUDE.md for Claude
Code). Those mount paths are read-only: to change this context, edit this
file (docs/AGENTS.md), never the mount paths.
The same global agents are available under @name either way, and custom
project agents in docs/agents/ work under both tools — write them in the
built-in format (name:, description:, tools: Read, Grep, ...), no per-tool
format needed. To make a custom agent read-only under OpenCode, add a
`permission:` frontmatter block (the built-in format manigot's conversion
passes through to OpenCode's schema — see the manigot README's agent section);
the read-only built-in agents' blocks deny the destructive git commands
(worktree management, branch -d/-D, reset, checkout, push, ...).
Custom agents that must commit (like the built-in developer/reviewer/quality)
declare `commit: true` in their frontmatter; agents that never commit declare
`commit: false` and get a read-only git mount. The default — no agent named,
file missing, or marker absent/unknown — is a writable git mount, so a
committing agent is never broken by a missing marker.
Agent sessions also restrict git to reading history and making commits (the
session git shim): worktree management, branch deletes, resets, checkouts,
pushes, and the other destructive subcommands are refused.
Keep this file tool-neutral — write it for "the agent", not for one vendor.
-->

Backend API for [solyto.app](https://solyto.app) — a free, private, all-in-one
personal management app (todos, calendar, contacts, notes, feeds, libraries,
time tracking, check-in, and more), self-hostable. This repo is the Laravel
REST API plus a CalDAV/CardDAV server; the user-facing UI lives in a separate
frontend repository. AGPL-3.0.

## Stack
- Backend: PHP 8.4, Laravel 12 — JSON REST API under `/api/v1`
- Frontend: minimal — Vite + Tailwind CSS 4 asset build (`resources/`) only; the actual UI is a separate solyto repository
- Database: MariaDB (default app connection), PostgreSQL (dedicated `pgsql` connection used by SabreDAV + contact photos), SQLite in-memory for tests
- Cache/Queue: Redis
- Key packages: `sabre/dav` (CalDAV/CardDAV server), `laravel/sanctum` (token auth + passkeys), `laravel-notification-channels/webpush`, `openai-php/client` (AI), `simplepie/simplepie` (RSS feeds), `calliostro/php-discogs-api` (music metadata), `intervention/image` (image processing), `johngrogg/ics-parser` (calendar import), `darkaonline/l5-swagger` (OpenAPI docs), `laravel/pulse` + `laravel/telescope` (observability), Pest + `laravel/pint` (tests / style)

## Architecture
- Feature domains are self-contained modules under `app/Api/<Module>/` (Todos, Calendars, Contacts, Libraries, Feeds, Finances, Notes, Telegram, ...), each with its own Controllers, Models, Requests, Resources, Services, Factories, Jobs, Notifications, and Tests. Cross-module code lives in `app/Shared/` (Models, Services like `IntegrationGateway` and `QuickAddService`, Helpers like `DockerSecretHelper`, Providers).
- All API routes are registered in `routes/api.php` under the `v1` prefix and protected by `auth:sanctum` — only `health` and the auth endpoints are public. Every response goes through the standard envelope (`App\Api\ApiResponse`: `success`/`error` + pagination meta); centralized exception → JSON mapping lives in `bootstrap/app.php`. The OpenAPI spec is annotation-driven (`app/OpenApi/OpenApiSpec.php`, l5-swagger).
- `app/Dav/` is the SabreDAV server (CalDAV/CardDAV, sync, sharing) served from `routes/web.php` (`dav/{path?}` and the `dav.solyto.de` domain). Its backends read/write the dedicated `pgsql` connection — app data stays on the default (MariaDB) connection.
- `app/Bots/` is the Telegram bot framework (`SolytoBot` with a state machine in `State/` and translation-key messages), wired via `config/telegram.php` and the `webhooks/telegram/solyto/{token}` route.
- Background work is queued (Redis) and scheduled in `routes/console.php` (hourly feed sync, daily release grabs, retention/cleanup jobs, daily reminders).
- Secrets and third-party API keys (Hardcover, TMDB, Discogs, Mailgun, imgproxy, AI, bot tokens, ...) are read at runtime from Docker secrets via `App\Shared\Helpers\DockerSecretHelper`, not from `.env`.
- Tests are Pest: co-located in `app/Api/<Module>/Tests` plus `tests/Feature` and `tests/Unit`; they run against in-memory SQLite (see `tests/TestCase.php`). UI copy is localized in `resources/lang/{en,de,fr,es}`.

## Commands
- `composer test` (or `php artisan test`) — run the Pest suite (Unit, Feature, Api)
- `composer audit` — check dependencies for vulnerabilities
- `composer run dev` — run all dev processes (artisan serve, queue:listen, pail, Vite)
- `npm run dev` / `npm run build` — Vite dev server / asset build
- `php artisan migrate` — run database migrations
- `make deploy` — Ansible deploy (requires the separate solyto/deployment repo)

## Hard rules
- NEVER modify files outside /workspace
- NEVER run database migrations without showing them first
- NEVER install packages without asking
- NEVER commit or print secrets: `.env` / `.env.testing` contents and anything read through `DockerSecretHelper` (API keys, bot tokens) must stay out of code, logs, and diffs
- NEVER change the `pgsql` (DAV) connection, its schema, or the DAV routes without flagging it — SabreDAV backends depend on that layout
- When scope is unclear: ask, don't guess
- Do not refactor things unrelated to the current task
- Do not add abstractions not already present in the codebase

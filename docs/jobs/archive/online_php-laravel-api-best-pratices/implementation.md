# Implementation: php/laravel/api best pratices

id: online
developer: @developer
date: 2026-10-10

## Summary

Analyzed the Solyto API codebase and turned its existing conventions into a
repository-specific policy set written to `docs/*.md`. Every rule is grounded
in real files (mostly the Todos module, `routes/api.php`, `ApiResponse`,
`HandlesApiAuth`, `UserCacheService`, `DockerSecretHelper`, `bootstrap/app.php`,
Pest files) with short Do/Don't snippets, per the brief's complaint that generic
AI output was often "meh". No application code was changed — documentation only.

## Changes

- TASK-1 — `docs/API-CONVENTIONS.md`: index + golden rules (module layout,
  `app/Shared/` boundary, `ApiResponse` envelope, `auth:sanctum` default,
  ownership checks, `DockerSecretHelper`, service-layer rule, `pgsql` hard
  rule, no-new-abstractions) and the module anatomy diagram grounded in
  `app/Api/Todos/`, linking to TASK-2..9.
- TASK-2 — `docs/CONVENTIONS-CONTROLLERS.md`: thin-controller rule
  (FormRequest → service → `ApiResponse`), constructor property promotion,
  `HandlesApiAuth` ownership checks (incl. nested-child checks), the
  Do/Don't on inline `$request->validate()` for trivial subtask-style
  endpoints, `routes/api.php` conventions (`apiResource`, nested `{model}`
  binding, named custom actions), method/`operationId` naming.
- TASK-3 — `docs/CONVENTIONS-SERVICES.md`: business logic in module Services
  (never controllers/models), one service per aggregate, `list`/`find`/
  `create`/`update`/`destroy` naming, private helpers (`resolveDate`,
  `spawnNextOccurrence`), "functions do one thing" + DRY, caching via
  `UserCacheService` with explicit `forget` on every write, tenant scoping via
  `forUser`, eager loading, `IntegrationGateway` as the cross-module seam.
- TASK-4 — `docs/CONVENTIONS-REQUESTS-RESPONSES.md`: FormRequest per write
  endpoint (`rules()` pipe strings, `attributes()`, no `authorize()` — auth
  stays in controllers), keeping rules and `@OA` in sync, `ApiResponse`
  envelope + pagination meta shape, status codes (201 create / 200 otherwise,
  deletes return `data: null`), and relying on the central exception →
  JSON mapping in `bootstrap/app.php` instead of ad-hoc try/catch.
- TASK-5 — `docs/CONVENTIONS-MODELS-DATABASE.md`: Eloquent conventions
  (`$fillable` not `$guarded`, `$casts`, typed relations, `HasUuids` +
  `HasFactory`, `scopeForUser`/`forUser`, co-located factories wired via
  `newFactory()` with `forUser()` states), migrations as additive source of
  truth, and the MariaDB (app) vs PostgreSQL (`pgsql`/DAV) split incl. the
  hard rule never to touch DAV schema.
- TASK-6 — `docs/CONVENTIONS-RESOURCES-OPENAPI.md`: serialize through
  `JsonResource` only, `whenLoaded(...)` for optional relations (anti-N+1),
  annotation-driven OpenAPI in three layers (global `OpenApiSpec.php`,
  per-resource `@OA\Schema`, per-endpoint `@OA` operations with
  `operationId`/`tags`/`security`/`RequestBody`/error responses).
- TASK-7 — `docs/CONVENTIONS-AUTH-SECURITY.md`: `auth:sanctum` on all
  non-public routes (public = health + auth only, rate-limited),
  tenant isolation via `isResourceOwner` → 403 on every show/update/destroy,
  never trusting route-model binding alone, `$fillable` + validation as
  mass-assignment defense, `DockerSecretHelper` for all secrets (never `.env`,
  never logged/committed), CSRF exemption scope, `composer audit`.
- TASK-8 — `docs/CONVENTIONS-JOBS-QUEUES.md`: queued Jobs in
  `app/Api/<Module>/Jobs` (constructor carries identifiers, state re-read in
  `handle()`, delegates to services, `$tries`/`$timeout` per job, dispatch
  fan-out), schedules in `routes/console.php`, module Commands registered in
  `bootstrap/app.php` `withCommands()`, Notifications extending
  `BaseNotification` for user-facing delivery.
- TASK-9 — `docs/CONVENTIONS-TESTING-STYLE.md`: Pest layout (`describe`/`it`,
  co-located `app/Api/<Module>/Tests` + `tests/Unit|Feature`, `RefreshDatabase`
  wiring via `tests/Pest.php` + `app/Api/Pest.php`), helpers (`makeUser`,
  `sanctumToken`, `authHeaders`, `expectApiSuccess/Error`), envelope JSON
  assertions (`assertJsonPath`/`assertJsonCount`), service tests via
  `app(Service::class)`, running `composer test` / `composer audit`, Pint.
- TASK-10 — `docs/AGENTS.md` + `README.md`: added minimal pointer sentences to
  `docs/API-CONVENTIONS.md` so agents and contributors discover the set.

## Known issues / follow-ups

- The root `AGENTS.md` and `.env.testing` show as modified in `git status`
  purely as harness mount artifacts (root `AGENTS.md` is a hardlink of
  `docs/AGENTS.md`; `.env.testing` is a mounted character device). They were
  modified before this job started and were left untouched — not part of this
  job's changes.
- The `docs/` directory also contains `node_modules/`, `package.json`,
  `package-lock.json` (unrelated to this doc set; not touched). If those were
  not meant to live in `docs/`, that is a separate cleanup.
- `link` is validated by `StoreTodoRequest`/`UpdateTodoRequest` and serialized
  by `TodoResource`, but the `@OA\RequestBody` annotations in
  `TodoController::store`/`update` do not document it — a pre-existing
  spec/rules drift noticed while writing TASK-6; out of scope, listed for
  awareness.
# Verdict: php/laravel/api best pratices

id: online
reviewer: @reviewer
date: 2026-10-10

<!-- Produced by @reviewer after implementation. -->

## Review

Reviewed the full `git diff main...HEAD` (base `main` per
`.manigot/manigot.json`). The branch adds nine `docs/*.md` policy docs plus an
index (TASK-1..9), and pointers from `docs/AGENTS.md` + `README.md` (TASK-10).
No application code was touched — documentation only, as scoped.

Grounding was verified against the real files rather than taken on trust. Spot
checks that all matched: `TodoController` (`store`/`show`/`updateSubtask`
snippets), `TodoService` (`create`/`update`/`find`/`parse`/`resolveDate`/
`spawnNextOccurrence`, cache `forget` calls), `Todo` model (`HasUuids`,
`newFactory()`, `$fillable`, `$casts`, `scopeForUser`), `TodoFactory` states,
`HandlesApiAuth`, `ApiResponse` (envelope + pagination meta), `bootstrap/app.php`
exception map, `routes/api.php` (public vs `auth:sanctum`, `apiResource('/', ...)
->parameters(['' => 'todo'])`), `DockerSecretHelper::get`, `UserCacheService`
method list, `IntegrationGateway::todos`, `routes/console.php`, `SyncFeeds`,
`RefreshCalendarCache`, `CalendarShareNotification`, `OpenApiSpec`, the Pest
files (`tests/Pest.php`, `app/Api/Pest.php`, `tests/TestCase.php`) and the
`tests/Unit/*` names. All the file paths, class names, method names and quoted
snippets resolve to what is actually in the repo.

TASK-1: PASS
notes: `docs/API-CONVENTIONS.md` — index, the ten golden rules, navigation, and
the module-anatomy diagram grounded in `app/Api/Todos/`; links to TASK-2..9 all
resolve.

TASK-2: PASS
notes: `docs/CONVENTIONS-CONTROLLERS.md` — thin-controller rule, constructor
property promotion, `HandlesApiAuth` ownership + nested-child check, inline
`$request->validate()` Do/Don't, `routes/api.php` conventions and naming;
quotes match `TodoController` and `routes/api.php`.

TASK-3: PASS
notes: `docs/CONVENTIONS-SERVICES.md` — services own business logic, one per
aggregate, `list/find/create/update/destroy`, private helpers, DRY,
`UserCacheService` caching with explicit `forget`, `forUser` scoping,
`IntegrationGateway` seam. `app/Shared/Services/` naming list verified.

TASK-4: PASS
notes: `docs/CONVENTIONS-REQUESTS-RESPONSES.md` — FormRequest per write
endpoint, `attributes()`, no `authorize()`, ApiResponse envelope + pagination
meta, status codes, central exception mapping. Quoted rules match
`StoreTodoRequest`/`UpdateTodoRequest`.

TASK-5: PASS
notes: `docs/CONVENTIONS-MODELS-DATABASE.md` — `$fillable`/`$casts`, typed
relations, `HasUuids`+`HasFactory`, `scopeForUser`, co-located factories via
`newFactory()`, additive migrations, and the MariaDB-vs-`pgsql`/DAV hard rule.
The `pgsql` warning is stated accurately (read-only context, no proposed
changes).

TASK-6: PASS
notes: `docs/CONVENTIONS-RESOURCES-OPENAPI.md` — JsonResource-only serialisation,
`whenLoaded(...)`, three-layer annotation-driven OpenAPI. Quoted
`@OA\Schema`/operation blocks match `TodoResource` and `TodoController`.

TASK-7: PASS
notes: `docs/CONVENTIONS-AUTH-SECURITY.md` — `auth:sanctum` default, public =
health + `auth/*` (rate-limited), `isResourceOwner` → 403, never trust route
binding, validation + `$fillable` as mass-assignment defense, `DockerSecretHelper`
only. No secret values printed; quoted helper code is the public method body.

TASK-8: PASS
notes: `docs/CONVENTIONS-JOBS-QUEUES.md` — Jobs in `app/Api/<Module>/Jobs`,
state re-read in `handle()`, `$tries`/`$timeout`, schedules in
`routes/console.php`, Commands registered in `bootstrap/app.php`, Notifications
via `BaseNotification`. `SyncFeeds`/`RefreshCalendarCache`/
`CalendarShareNotification` claims verified.

TASK-9: PASS
notes: `docs/CONVENTIONS-TESTING-STYLE.md` — Pest layout, `RefreshDatabase`
wiring, helpers (`makeUser`, `sanctumToken`, `authHeaders`,
`expectApiSuccess/Error`), envelope assertions, service tests via `app(...)`,
`composer test`/Pint/`composer audit`. Binding lines copied verbatim from
`tests/Pest.php` and `app/Api/Pest.php`.

TASK-10: PASS
notes: `docs/AGENTS.md` gains a 3-line `## Conventions` pointer and `README.md`
one sentence — minimal, non-restructuring, both resolve to
`docs/API-CONVENTIONS.md`.

## Security

No findings. The set documents the security posture (Sanctum, tenant isolation,
`DockerSecretHelper`) and does not reproduce any real secret value or
`DockerSecretHelper` output.

## Overall

APPROVED

Nothing needs to change before merge. The policy set is complete, faithfully
grounded in the actual codebase, and correctly scoped to `docs/` (plus the two
TASK-10 pointer edits). The known issues reported in `implementation.md` (root
`AGENTS.md`/`.env.testing` harness mount artifacts; the pre-existing
`link` rules-vs-`@OA` drift) are accurate and intentionally out of scope.

Non-blocking observations (not required for merge): `TodoTest.php` is labeled
"service/unit-level" in TASK-9 but actually covers factories/models (the
`TodoService::parse` tests live in `TodoApiTest.php`, which the doc states
correctly); and `tests/Feature/` is currently empty, so naming it alongside
`tests/Unit/` as a home for existing suites is aspirational rather than
descriptive.

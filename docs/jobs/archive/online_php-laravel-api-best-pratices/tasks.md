# Tasks: php/laravel/api best pratices

id: online
analyst: @analyst
date: 2026-10-10

<!-- Produced by @analyst from brief.md. -->

## Objective

Produce a repository-specific **policy set** that documents how code is built in
the Solyto API, so that a contributing developer (human or AI) can follow the
existing conventions instead of inventing new ones. The output is Markdown under
`docs/` only — no application code changes.

The core requirement is that every rule is **grounded in the existing codebase**
(optionally with a file path and a short Do / Don't snippet copied from it), not
generic Laravel advice. The brief explicitly complains that AI output is
sometimes "meh"; the value of these docs is that they capture *this* repo's
choices (module layout, `ApiResponse` envelope, `HandlesApiAuth`, service layer,
`UserCacheService`, `DockerSecretHelper`, Pest layout, OpenAPI annotations).

## Proposed file layout

Policy docs live flat in `docs/` to satisfy the brief's `docs/*.md`:

- `docs/API-CONVENTIONS.md` — index, golden rules, module anatomy (entry point)
- `docs/CONVENTIONS-CONTROLLERS.md`
- `docs/CONVENTIONS-SERVICES.md`
- `docs/CONVENTIONS-REQUESTS-RESPONSES.md`
- `docs/CONVENTIONS-MODELS-DATABASE.md`
- `docs/CONVENTIONS-RESOURCES-OPENAPI.md`
- `docs/CONVENTIONS-AUTH-SECURITY.md`
- `docs/CONVENTIONS-JOBS-QUEUES.md`
- `docs/CONVENTIONS-TESTING-STYLE.md`

The developer may collapse two topic docs into one if that reads better, but
should keep the index (`API-CONVENTIONS.md`) as the single entry point. Do not
create files outside `docs/`.

## Task breakdown

TASK-1: Write the conventions index and golden rules — `docs/API-CONVENTIONS.md`.
     Covers: purpose/audience, how to navigate the set, the non-negotiable
     "golden rules" (module layout under `app/Api/<Module>/`, cross-module code
     in `app/Shared/`, everything through `ApiResponse`, `auth:sanctum` on all
     non-public routes, no direct secret reads outside `DockerSecretHelper`), and
     an anatomy diagram of a module (Controllers/Requests/Services/Models/
     Resources/Factories/Tests) grounded in `app/Api/Todos/`. Links to TASK-2..9.
     files: docs/API-CONVENTIONS.md
     depends: none
     risk: low — new doc, no code touched; risk is content drift, mitigated by grounding in real files.

TASK-2: Write the controllers-and-routing policy — `docs/CONVENTIONS-CONTROLLERS.md`.
     Covers: controller responsibilities (validate via FormRequest → delegate to
     service → wrap in `ApiResponse`), thin-controller rule, constructor property
     promotion of services, ownership checks via `HandlesApiAuth`, route
     conventions in `routes/api.php` (v1 prefix, `apiResource`, nested
     `{model}` binding), naming (method names vs `operationId`). Use
     `app/Api/Todos/Controllers/TodoController.php` and `routes/api.php` as the
     reference examples, including a Do/Don't for inline `$request->validate()`
     (allowed for trivial subtask endpoints, but FormRequests preferred).
     files: docs/CONVENTIONS-CONTROLLERS.md
     depends: TASK-1
     risk: low — documentation only.

TASK-3: Write the services-and-business-logic policy — `docs/CONVENTIONS-SERVICES.md`.
     Covers: business logic lives in `app/Api/<Module>/Services` (not controllers,
     not models), one service per aggregate (`TodoService`), method naming
     (`list`/`find`/`create`/`update`/`destroy`), private helpers for
     sub-operations (`resolveDate`, `spawnNextOccurrence`), "functions do one
     thing" and DRY guidance, cache read/write through `UserCacheService` with
     explicit `forget` on writes, and the cross-module `IntegrationGateway`
     boundary. Reference `TodoService`, `UserCacheService`, `IntegrationGateway`.
     files: docs/CONVENTIONS-SERVICES.md
     depends: TASK-1
     risk: low — documentation only.

TASK-4: Write the requests-and-responses policy — `docs/CONVENTIONS-REQUESTS-RESPONSES.md`.
     Covers: `FormRequest` per write endpoint (rules + `attributes()`), keeping
     rules and OpenAPI in sync, the `ApiResponse` envelope (`success`/`message`/
     `data`/`timestamp`/`meta`, pagination meta shape), correct status codes
     (201 create, 200 otherwise), and relying on the centralized exception →
     JSON mapping in `bootstrap/app.php` instead of ad-hoc try/catch. Reference
     `StoreTodoRequest`, `ApiResponse`, `bootstrap/app.php`.
     files: docs/CONVENTIONS-REQUESTS-RESPONSES.md
     depends: TASK-1
     risk: low — documentation only.

TASK-5: Write the models-and-database policy — `docs/CONVENTIONS-MODELS-DATABASE.md`.
     Covers: Eloquent model conventions (`$fillable`/`$casts`, typed relations,
     `forUser` / `scopeForUser` tenant scoping, `HasUuids`, co-located
     `Factories`), migrations as source of truth (flag: never edit DAV/`pgsql`
     schema — SabreDAV depends on it), and the MariaDB (app) vs PostgreSQL
     (DAV/photos) connection split. Reference `app/Api/Todos/Models/Todo.php`
     and `database/migrations`, plus the `pgsql` hard rule.
     files: docs/CONVENTIONS-MODELS-DATABASE.md
     depends: TASK-1
     risk: low — documentation only; the pgSQL warning must be stated accurately.

TASK-6: Write the resources-and-openapi policy — `docs/CONVENTIONS-RESOURCES-OPENAPI.md`.
     Covers: always serialize through `JsonResource` classes, `whenLoaded(...)`
     for optional relations to avoid N+1 over-fetching, per-resource
     `@OA\Schema` and per-endpoint `@OA\*` annotations (`operationId`, `tags`,
     `security`, `RequestBody`, error responses), and the l5-swagger spec being
     annotation-driven. Reference `TodoResource.php`, `TodoController` docblocks,
     `app/OpenApi/OpenApiSpec.php`.
     files: docs/CONVENTIONS-RESOURCES-OPENAPI.md
     depends: TASK-1
     risk: low — documentation only.

TASK-7: Write the auth-and-security policy — `docs/CONVENTIONS-AUTH-SECURITY.md`.
     Covers: `auth:sanctum` default for all non-public routes, tenant isolation
     via `isResourceOwner` (abort 403) on every show/update/destroy, never trust
     route model binding alone for ownership, secrets read at runtime via
     `DockerSecretHelper` (never `.env`, never log/commit them), and validation
     as the first line of defense against mass assignment. Reference
     `HandlesApiAuth`, `DockerSecretHelper`, `routes/api.php`.
     files: docs/CONVENTIONS-AUTH-SECURITY.md
     depends: TASK-1
     risk: low — documentation only; must not print any real secret values.

TASK-8: Write the jobs-queues-and-scheduling policy — `docs/CONVENTIONS-JOBS-QUEUES.md`.
     Covers: heavy/background work goes through queued `Jobs` (Redis) within the
     owning module (`app/Api/<Module>/Jobs`), schedule registration in
     `routes/console.php`, module `Commands` registered in `bootstrap/app.php`,
     and `Notifications` for user-facing delivery. Reference
     `app/Api/Calendars/Jobs`, `routes/console.php`.
     files: docs/CONVENTIONS-JOBS-QUEUES.md
     depends: TASK-1
     risk: low — documentation only.

TASK-9: Write the testing-and-code-style policy — `docs/CONVENTIONS-TESTING-STYLE.md`.
     Covers: Pest conventions (`describe`/`it`, `makeUser()` helper,
     `RefreshDatabase`, co-located `app/Api/<Module>/Tests` plus `tests/Feature`
     and `tests/Unit`), running `composer test`, JSON-assertion style
     (`assertJsonPath`/`assertJsonCount`), and style enforcement via
     `laravel/pint` + `composer audit`. Reference `TodoApiTest.php`, `pest.php`,
     `tests/TestCase.php`.
     files: docs/CONVENTIONS-TESTING-STYLE.md
     depends: TASK-1
     risk: low — documentation only.

TASK-10: Cross-link the policy set for discoverability.
     Add a short pointer from `docs/AGENTS.md` (the sanctioned project-context
     file) to `docs/API-CONVENTIONS.md`, and optionally from `README.md`, so
     agents and contributors find the set. Keep the edit minimal (a sentence or
     two), do not restructure existing content.
     files: docs/AGENTS.md, README.md
     depends: TASK-1 (and ideally TASK-2..9 so links resolve)
     risk: low — docs only; note that `docs/AGENTS.md` is the editing surface
       for project context, not the read-only mount path (`/workspace/AGENTS.md`).

## Open questions / notes for @developer

- The brief asks for the policy set to be written to `docs/*.md`; I read that as
  a small family of flat files plus an index (layout above). If you think a
  single `docs/API-CONVENTIONS.md` is clearer, consolidate — but keep it the
  entry point.
- Grounding matters more than volume: prefer a short rule + a real file
  reference (e.g. "see `TodoService::update`") over long generic prose. Avoid
  recommending abstractions that do not already exist in this codebase (the
  project hard rule forbids introducing new ones).
- Do not state that secrets live in `.env` or reproduce any secret/`DockerSecretHelper`
  output.
- The DAV/`pgsql` boundary is a hard rule: describe it as read-only context, do
  not propose changes there.

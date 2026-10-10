# Solyto API — Conventions

This is the policy set for how code is built in the Solyto API. It is written
for anyone — human or AI — contributing to this repository, so that new code
follows the choices this codebase already made instead of inventing new ones.

Every rule here is grounded in real files. Where a rule is easier to show than
to describe, the doc points at the file and quotes a short Do/Don't.

This repository is unusual in two ways that shape the rules:

- It is not a typical "controllers call models" Laravel app. Business logic
  lives in module Services, controllers stay thin, and every response goes
  through one envelope.
- It is deliberately _not_ abstracted. The project hard rule is
  "do not add abstractions not already present in the codebase". If a pattern
  is not already here, it does not belong in a change.

## The set

| Doc | Covers |
| --- | --- |
| [API-CONVENTIONS.md](./API-CONVENTIONS.md) | This index: purpose, golden rules, module anatomy |
| [CONVENTIONS-CONTROLLERS.md](./CONVENTIONS-CONTROLLERS.md) | Controllers, routing, `HandlesApiAuth` |
| [CONVENTIONS-SERVICES.md](./CONVENTIONS-SERVICES.md) | Business logic, service shape, caching, cross-module boundary |
| [CONVENTIONS-REQUESTS-RESPONSES.md](./CONVENTIONS-REQUESTS-RESPONSES.md) | FormRequests, `ApiResponse`, status codes, exception mapping |
| [CONVENTIONS-MODELS-DATABASE.md](./CONVENTIONS-MODELS-DATABASE.md) | Eloquent models, factories, migrations, MariaDB vs `pgsql` |
| [CONVENTIONS-RESOURCES-OPENAPI.md](./CONVENTIONS-RESOURCES-OPENAPI.md) | `JsonResource`, `whenLoaded`, `@OA` annotations |
| [CONVENTIONS-AUTH-SECURITY.md](./CONVENTIONS-AUTH-SECURITY.md) | Sanctum, tenant isolation, `DockerSecretHelper` |
| [CONVENTIONS-JOBS-QUEUES.md](./CONVENTIONS-JOBS-QUEUES.md) | Queued jobs, schedules, commands, notifications |
| [CONVENTIONS-TESTING-STYLE.md](./CONVENTIONS-TESTING-STYLE.md) | Pest layout, helpers, assertions, Pint |

## Golden rules

These are the non-negotiables. If a change breaks one of them, the change is
wrong regardless of how it reads.

1. **Feature code lives in its module.** Every domain is a self-contained
   folder under `app/Api/<Module>/` — `app/Api/Todos`, `app/Api/Calendars`,
   `app/Api/Contacts`, and so on. A module owns its own Controllers, Models,
   Requests, Resources, Services, Factories, Jobs, Notifications and Tests.

2. **Cross-module code lives in `app/Shared/`.** Anything used by more than one
   module goes under `app/Shared/` (Models, Services, Helpers, Enums,
   Exceptions). Never have one module reach into another module's internals
   when a shared service exists — `App\Shared\Services\IntegrationGateway` is
   the intended seam for external integrations.

3. **Every API response goes through `App\Api\ApiResponse`.** Success and error
   bodies have a fixed shape (`success`, `message`, `data`/`errors`,
   `timestamp`, optional `meta`). Do not hand-roll `response()->json([...])` in
   a controller. See `app/Api/ApiResponse.php`.

4. **All non-public routes require `auth:sanctum`.** Public routes are exactly
   the health check and the `auth/*` endpoints in `routes/api.php`. Everything
   else is inside the `Route::prefix('v1')->middleware('auth:sanctum')` group.

5. **Ownership is checked in the controller, not assumed from route binding.**
   Route-model binding resolves a model for anyone; a non-owner must get a 403.
   Use `HandlesApiAuth::isResourceOwner()` — see
   `app/Api/Todos/Controllers/TodoController.php`.

6. **Secrets are read at runtime through `DockerSecretHelper`.** Never read a
   third-party key from `.env`, never hard-code it, and never log or commit it.
   `App\Shared\Helpers\DockerSecretHelper::get()` resolves either the
   environment variable (debug) or the `*_FILE` Docker secret.

7. **Business logic lives in Services, not controllers or models.** A controller
   validates, delegates, and wraps the result; a model is data and relations.
   See [CONVENTIONS-SERVICES.md](./CONVENTIONS-SERVICES.md).

8. **Serialise through a `JsonResource`.** Controllers return resources, not raw
   models or arrays. `whenLoaded(...)` keeps optional relations honest. See
   [CONVENTIONS-RESOURCES-OPENAPI.md](./CONVENTIONS-RESOURCES-OPENAPI.md).

9. **`app/Dav/` and the `pgsql` connection are off-limits.** The SabreDAV
   backends depend on that schema and those routes. App data is on the default
   (MariaDB) connection; do not mix them. Flag it rather than changing it.

10. **No new abstractions.** Prefer the patterns already in the repo. If the
    codebase has no service/interface/pattern for something, adding one is out
    of scope — ask first.

## Module anatomy

A module is a folder, and its sub-folders map to responsibilities. `app/Api/Todos`
is the reference module; the whole layout is visible there:

```
app/Api/Todos/
├── Controllers/     HTTP entry points — thin, one per resource or resource family
│   └── TodoController.php
├── Requests/        FormRequest validation, one per write action
│   ├── StoreTodoRequest.php
│   └── UpdateTodoRequest.php
├── Services/        Business logic — the heart of the module
│   └── TodoService.php
├── Models/          Eloquent models + relations + scopes
│   └── Todo.php
├── Resources/       JSON serialisation + @OA\Schema
│   └── TodoResource.php
├── Factories/       Co-located model factories (referenced via newFactory())
│   └── TodoFactory.php
├── Jobs/            Queued background work (when the module needs it)
├── Notifications/   User-facing delivery (when the module needs it)
└── Tests/           Co-located Pest tests
    └── TodoApiTest.php
```

Not every module needs every folder — small modules may have no `Jobs` or
`Notifications`. The direction of a request is always the same:

```
route → Controller → FormRequest (validate) → Service (do the work)
      → Model/DB → Resource (serialise) → ApiResponse (wrap)
```

## How to use this set

- Building a new endpoint? Read Controllers, Requests/Responses, and Resources
  first.
- Adding business logic? Read Services, then Models/Database.
- Touching auth, ownership, or a third-party key? Read Auth/Security.
- Adding something slow or periodic? Read Jobs/Queues.
- Writing tests? Read Testing/Style.

## When in doubt

- Match the closest existing example instead of inventing a variant.
- If the conventions conflict with your task, stop and ask — the hard rules in
  `docs/AGENTS.md` win.

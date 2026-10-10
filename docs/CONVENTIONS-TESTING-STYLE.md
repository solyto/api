# Conventions — Testing & Code Style

Reference files:
`tests/Pest.php`, `tests/TestCase.php`, `app/Api/Pest.php`,
`app/Api/Todos/Tests/TodoApiTest.php`, `tests/Unit/ApiResponseTest.php`,
`composer.json`.

## Running the suite

```sh
composer test    # = php artisan config:clear && php artisan test
php artisan test # run the Pest suite directly
composer audit   # dependency vulnerability check (part of the workflow)
```

Tests run against in-memory SQLite (default connection) plus a file-backed
SQLite standing in for the `pgsql` connection; the environment is prepared in
`tests/TestCase.php` (which also swaps Redis cache stores for array stores and
replaces the AI/image services with doubles). Do not add tests that require
MariaDB/PostgreSQL/Redis natively.

## Where tests live

- **Unit/feature tests for a module's internals** are co-located in
  `app/Api/<Module>/Tests/` — `app/Api/Todos/Tests/TodoApiTest.php`
  (API-level) and `app/Api/Todos/Tests/TodoTest.php` (service/unit-level).
- Repo-wide suites live in `tests/Unit/` and `tests/Feature/`
  (`ApiResponseTest`, `OpenApiSpecTest`, `DockerSecretHelperTest`, ...).

Binding: `tests/Pest.php` wires `Tests\TestCase` for `Feature`, `Unit`,
`../app/Api`, `../app/Bots/Tests`, `../app/Dav/Tests`:

```php
// tests/Pest.php
uses(TestCase::class)->in('Feature', 'Unit', '../app/Api', '../app/Bots/Tests', '../app/Dav/Tests');
```

and `app/Api/Pest.php` adds `RefreshDatabase` for everything inside a module:

```php
// app/Api/Pest.php
uses(TestCase::class, RefreshDatabase::class)->in('.');
```

So module tests get `RefreshDatabase` for free; tests under `tests/Feature`
declare it per file (`uses(RefreshDatabase::class);`).

## Structure of a test file

Pest style: `describe()` blocks per resource/flow, `it()` per behaviour,
given-when-then inside each `it`:

```php
// app/Api/Todos/Tests/TodoApiTest.php
describe('Todo CRUD', function () {
    it('lists, stores, shows, updates and deletes todos', function () {
        $user = makeUser();

        $store = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/todos', ['title' => 'Buy milk', 'priority' => 'high'])
            ->assertStatus(201)
            ->assertJsonPath('data.title', 'Buy milk');

        $todoId = $store->json('data.id');
        // ... show, update, delete ...

        expect(Todo::count())->toBe(0);
    });

    it('scopes todos to the authenticated user', function () {
        $user = makeUser();
        $other = makeUser();
        Todo::factory()->forUser($other)->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/todos')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    });
});
```

- **Name the `describe` after the behaviour under test** (`Todo CRUD`,
  `TodoService::parse`) and the `it` after the outcome.
- **Each test creates its own users/models via factories** — `makeUser()`,
  `Todo::factory()->forUser($user)`. No shared/leaked state; `RefreshDatabase`
  handles the reset.
- **One behaviour per `it`.** Readability beats terseness.

## Helpers

`tests/Pest.php` registers global function helpers for authenticated API tests:

| Helper | Purpose |
| --- | --- |
| `makeUser(array $attributes = [])` | creates a verified user (so protected routes work directly) |
| `sanctumToken(User $user)` | issues a fresh Sanctum token |
| `authHeaders(User $user)` | `['Authorization' => 'Bearer ...']` headers |
| `expectApiSuccess($response, int $status = 200)` | asserts status + `success: true` envelope |
| `expectApiError($response, int $status)` | asserts status + `success: false` envelope |

Use these helpers instead of re-rolling the Sanctum setup in every test:

```php
$user = makeUser();

$this->actingAs($user, 'sanctum')
    ->getJson('/api/v1/todos')
    ->assertStatus(200)
    ->assertJsonCount(0, 'data');
```

Per-file `expect()` extensions (`toBeBoolean`, `toBeEmail`, `greaterThan`,
`lessThan`) are also registered in `tests/Pest.php` for the pre-existing suite.

## Asserting against the envelope

Requests go through `/api/v1/...` and are asserted against the `ApiResponse`
envelope shape — not raw model dumps:

```php
->assertStatus(201)
->assertJsonPath('data.title', 'Buy milk')
->assertJsonCount(1, 'data')
// cross-user isolation:
->assertStatus(403)                 // non-owner access
->assertJsonCount(0, 'data')        // other user's data invisible
```

Use `assertJsonPath` for nested fields and `assertJsonCount` for collection
lengths. The ApiResponse unit tests
(`tests/Unit/ApiResponseTest.php`, `covers(ApiResponse::class)`) pin the
envelope itself: `success`/`error`, pagination meta, resource resolution —
extend those when the envelope changes, not the feature tests.

## Service-level tests

Service logic that does not need HTTP is tested directly through the container
with factory-built fixtures — see the `TodoService::parse` block in
`TodoApiTest.php`:

```php
it('extracts #tags from the title and creates missing tags', function () {
    $user = makeUser();

    $data = app(TodoService::class)->parse($user, ['title' => 'Buy milk #groceries']);

    expect($data['title'])->toBe('Buy milk');
    expect($data['tags'])->toHaveCount(1);
    expect(Tag::where('name', 'groceries')->where('user_id', $user->id)->exists())->toBeTrue();
});
```

Prefer `app(Service::class)` over `new Service(...)` so constructor-promoted
dependencies (e.g. `UserCacheService`) resolve correctly.

## What to test

- **API contracts**: status codes, envelope shape (`success: true/false`),
  data fields the frontend depends on.
- **Tenant isolation**: a second user's data returns 403/empty — this is the
  security-critical assertion (see
  [CONVENTIONS-AUTH-SECURITY.md](./CONVENTIONS-AUTH-SECURITY.md)).
- **Validation**: a non-conforming payload returns 422 with field errors.
- **Service logic** that is non-trivial: parsing, recurrence, defaults.
- **Envelope/OpenAPI units**: `tests/Unit/ApiResponseTest.php`,
  `tests/Unit/OpenApiSpecTest.php` keep the shared contracts pinned.

Don't test Laravel itself (route registration, ORM internals) — test behaviour.

## Code style: Pint

`laravel/pint` is the style enforcer (`composer.json`, `require-dev`). Format
new and edited files with it before committing:

```sh
./vendor/bin/pint --dirty
```

Style notes visible across the repo: declare-strict-free PSR-12 layout, typed
properties and return types, constructor property promotion, pipe-string
validation rules, `@OA` annotations under docblocks. Match the file you are
editing — Pint will flag drift.

## Checklist

- [ ] Tests co-located in `app/Api/<Module>/Tests/` (or `tests/Unit`/`tests/Feature` for shared contracts).
- [ ] `makeUser()` + factories (`forUser()`) for fixtures; `RefreshDatabase` in place.
- [ ] Request assertions via `/api/v1/...` and `assertJsonPath`/`assertJsonCount`.
- [ ] Tenant-isolation and validation cases included.
- [ ] `composer test` green; `composer audit` clean; Pint run on changed files.
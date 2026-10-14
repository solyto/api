# Testing

How tests are written in this repository. The rule in one line:

> Test API contracts, tenant isolation, validation and service logic — not
> factories, casts or Eloquent.

Run with `composer test` (clears the config cache, then `php artisan test`)
or `php artisan test` / `./vendor/bin/pest` directly. Filter with
`php artisan test --filter=Todo` or `php artisan test app/Api/Todos/Tests`.
Coverage needs a driver (`pcov` or Xdebug) that is not installed by
default; a coverage driver was not approved (`docs/STANDARDS.md` → Decisions).

Everything here was checked against `tests/TestCase.php`, `phpunit.xml`,
`tests/Pest.php` and `pest.php` on 2026-10-13.

## Layout and suites

`phpunit.xml` defines three suites; `pest.php` (root) lists the same
directories plus two more for Pest's own discovery:

| Suite (`phpunit.xml`) | Path | What lives there |
| --- | --- | --- |
| `Unit` | `tests/Unit/` | shared code: `ApiResponse`, enums, `DockerSecretHelper`, `UrlHelper`, DAV helpers, the OpenAPI drift test, the resource-schema drift test (`ResourceSchemaDriftTest`, rule 2.6) |
| `Feature` | `tests/Feature/` | **empty** (only `.gitkeep`); keep it empty — feature tests are co-located |
| `Api` | `app/Api/*/Tests/` | the module tests: `<Module>ApiTest.php` (HTTP), `<Module>Test.php` (factories/models — legacy, see below), `*ServiceTest.php`, `JobTest.php`, `CommandTest.php` |
| — (Pest only) | `app/Bots/Tests/`, `app/Dav/Tests/` | bot state machine, DAV service |

Fixtures: `tests/Fixtures/` (`feed.rss`, `feed.atom`, `feed_empty.rss`,
`goodreads_book.html`).

Binding: `tests/Pest.php` is the only Pest bootstrap file Pest loads
(nested `Pest.php` files are ignored). It binds `Tests\TestCase` to all five
directories and adds `RefreshDatabase` to everything under `app/Api`, so a
module test does **not** declare its own `uses(RefreshDatabase::class)`
(a duplicate is tolerated but redundant). `tests/Unit` and `app/Bots|Dav`
tests have no database refresh unless they ask for it.

## The test environment (`tests/TestCase.php::createApplication`)

- Default connection → in-memory SQLite; `pgsql` (the DAV connection) →
  a file-backed SQLite at `storage/framework/testing/dav.sqlite`, created
  once per PHP process (the DAV migration is driver-aware). Both
  connections are wrapped in a transaction per test
  (`connectionsToTransact = ['sqlite', 'pgsql']`).
- Cache stores `user_data`, `longterm`, `conversation_state` → `array`
  (Redis in production). `phpunit.xml` sets `CACHE_STORE=array`,
  `QUEUE_CONNECTION=sync`, `MAIL_MAILER=array`, `SESSION_DRIVER=array`,
  Pulse/Telescope off, `PASSWORD_UNCOMPROMISED=false`, `EMAIL_CONFIRMATION=true`.
- `app.key` is a fixed test key and `services.ai.api_key` a stub (both are
  Docker secrets in production); `AiService` is always mocked where it is
  exercised.
- `ImageTransformationService` is bound to a no-op double (no Imagick/imgproxy).
- Consequence: do not write a test that needs MariaDB, PostgreSQL or Redis
  features; SQLite is what runs. (`whereRaw('LOWER(...)')` in `TodoService`
  exists because SQLite comparisons are case-sensitive.)

## Helpers (`tests/Pest.php`)

| Helper | Use |
| --- | --- |
| `makeUser(array $attributes = [])` | a verified user; 305 calls, the standard fixture |
| `$this->actingAs($user, 'sanctum')` | the standard way to authenticate a request (314 calls) |
| `authHeaders($user)` / `sanctumToken($user)` | only when the test is about tokens themselves (`AuthTest`) |
| `expectApiSuccess($response, 200)` / `expectApiError($response, 403)` | status + envelope flag in one line; defined, currently unused — use them |
| `Model::factory()->forUser($user)` | every user-owned factory has this state |

Custom expectations `greaterThan`, `lessThan`, `toBeBoolean` alias Pest
built-ins and are being removed; use `toBeGreaterThan`, `toBeLessThan`,
`toBeBool`. `toBeEmail` stays.

## What gets a test

- **API contracts** — one `describe` per resource in `<Module>ApiTest.php`:
  a CRUD round trip (`TodoApiTest.php:13-44`, categories, is the shape), status codes,
  `assertJsonPath('data.x', …)`, `assertJsonCount(n, 'data')`.
- **Tenant isolation** — for every resource: another user's record returns
  403 on `show/update/destroy` and is absent from `index`
  (`TodoApiTest.php:46-59`); and, once `STANDARDS.md` 3.2 is implemented,
  posting another user's `category_id`/`tags`/`genres`/`parent_id`
  returns 422.
- **Validation** — a non-conforming payload returns 422 with the field in
  `errors`.
- **Service logic** that is not CRUD: parsing (`TodoService::parse`,
  `FeedReader`), recurrence, release filtering, quick-add detection —
  through `app(Service::class)` with factory data, no HTTP.
- **External services** — through the HTTP seams with `Http::fake()` and
  the fixtures; never a live call.
- **Jobs and commands** — assert the effect (rows written, notification
  sent), not that the job was dispatched.
- **Shared contracts** in `tests/Unit` with `covers()`: `ApiResponse`,
  `OpenApiSpecTest` (fails on any drift beyond the two kept entries in
  `OPENAPI_KNOWN_DRIFT`, and on a listed entry that no longer drifts).

Not tested, on purpose: that a factory produces what its state says, that
`$fillable` equals `$fillable`, that casts cast, that a `belongsTo` returns
the parent. 278 of 720 tests today are of that kind (`<Module>Test.php`
files); they are not deleted, but no new ones are written and a touched
file may lose them.

## Shape

```php
describe('Todo CRUD', function () {
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

- `describe` names the resource or behaviour, `it` names the outcome.
- Fixtures per test via factories; nothing shared across tests.
- No narration comments; a comment explains a fixture's boundary when the
  value is not self-evident (`CalendarApiTest.php:141-151`).
- Requests go to `/api/v1/...`; assertions go through the envelope.

## Caveats

- Time: Carbon's `now()` is real unless the test freezes it; a test whose
  outcome depends on "today" uses `Carbon::setTestNow()` (one file does
  today) and resets it — never a hard-coded date that is only true once.
- External HTTP is faked (`Http::fake()`, 8 files) and side effects are
  caught with `Notification::fake()`/`Queue::fake()`/`Bus::fake()` (8 files).
- The DAV SQLite file is shared by all tests in a process; the transaction
  wrapper handles isolation, but a test that commits through SabreDAV
  directly must clean up.
- `tests/Feature/` is not a home for anything; new HTTP tests go next to
  their module.
- New dev dependencies are installed only with approval (`docs/AGENTS.md`).

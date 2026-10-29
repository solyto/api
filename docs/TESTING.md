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

`phpunit.xml` defines the suites below; `pest.php` (root) lists the same
directories for Pest's own discovery:

| Suite (`phpunit.xml`) | Path | What lives there |
| --- | --- | --- |
| `Unit` | `tests/Unit/` | shared code: `ApiResponse`, enums, `DockerSecretHelper`, `UrlHelper`, DAV helpers, the OpenAPI drift test, the resource-schema drift test (`ResourceSchemaDriftTest`, rule 2.6) |
| `Feature` | `tests/Feature/` | **empty** (only `.gitkeep`); keep it empty — feature tests are co-located |
| `Dav` | `app/Dav/Tests/` | DAV service, protocol, isolation, client fixtures (see [DAV tests](#dav-tests)) |
| `Api` | `app/Api/*/Tests/` | the module tests: `<Module>ApiTest.php` (HTTP), `<Module>Test.php` (factories/models — legacy, see below), `*ServiceTest.php`, `JobTest.php`, `CommandTest.php` |
| `Bots` | `app/Bots/Tests/` | bot state machine and commands |
| `Plugins` | `app/Plugins/Tests/`, `app/Plugins/*/Tests/` | the plugin framework and each plugin |

Fixtures: `tests/Fixtures/` (`feed.rss`, `feed.atom`, `feed_empty.rss`,
`goodreads_book.html`) and `tests/Fixtures/dav/` (client-shaped `.ics`/`.vcf`).

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
- `app.key` is a fixed test key (a Docker secret in production). `AiService`
  is either mocked or given an injected OpenAI client (or PSR-18 HTTP client),
  so no test reaches a real AI provider.
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
| `expectApiSuccess($response, 200)` / `expectApiError($response, 403)` | status + envelope flag in one line; `expectApiError` is the standard for error cases, `expectApiSuccess` has no callers yet — use both |
| `Model::factory()->forUser($user)` | every user-owned factory has this state |
| `davUser()`, `davRequest()`, `davXpath()`, `davPropfindBody()`, `davIcs()`, `davVcard()` | DAV protocol tests, see [DAV tests](#dav-tests) |

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
- **`ResourceSchemaDriftTest`** (rule 2.6) reads source statically and
  compares only top-level keys. It does not see nested keys, keys inside
  `merge()`/`mergeWhen()` or a nested array, non-literal keys
  (`self::KEY =>`), or keys added outside the `return [` literal
  (`+ $extra`); a resource built that way passes unchecked, so cover its
  keys in the module's API test (`assertJsonStructure`). A `toArray()`
  without a `return [` literal fails as "no toArray() array literal" —
  restructure it rather than allow-listing it.

- **Client contract tests** — what older frontend builds read
  (`docs/STANDARDS.md` section 13); see below.

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

## Client contract tests

Installed apps (F-Droid, desktop) run older frontend code against the
newest API. These tests pin what those builds read, so a change that would
break them fails `composer test` (`docs/STANDARDS.md` 13.6).

- **One file:** `tests/Unit/ClientContractTest.php`, next to the other
  shared contracts. It declares `uses(RefreshDatabase::class)` itself,
  because `tests/Unit` gets no refresh by default.
- **What goes in:** only the fields that a supported frontend build
  actually reads, found by searching the frontend at that build's tag
  (`solyto/app`). Do not snapshot whole responses: extra fields are always
  allowed (13.2), so a full snapshot would fail on harmless changes.
- **Absence counts too:** for opt-in data (13.3), test that the plain
  endpoint does **not** return it.
- **Header comment per test:** the oldest build that needs it and the
  frontend line that reads it. That tells you when the test may go.
- **One test per thing read, not per version.** When a new build starts
  reading a field, add one test. When support for a build ends, delete the
  tests whose oldest build is the dropped one and that no newer build
  needs.
- **Red means the API is wrong.** Do not edit a contract test to make it
  pass. Make the API change additive (13.2–13.4), or ask the owner whether
  the build can be dropped.

```php
uses(RefreshDatabase::class);

describe('Client contract', function () {
    // Since 1.0.15. solyto/app src/routes/setup/+page.svelte:37
    it('keeps app and version at the top level of health', function () {
        $this->getJson('/api/v1/health')
            ->assertStatus(200)
            ->assertJsonPath('app', 'solyto')
            ->assertJsonStructure(['version']);
    });

    // Since 1.0.15. solyto/app src/lib/types/note.ts:28-36
    it('keeps the note category fields', function () {
        $user = makeUser();
        NoteCategory::factory()->forUser($user)->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/notes/categories')
            ->assertStatus(200)
            ->assertJsonStructure(['data' => [['id', 'title', 'parent_id', 'sort_order', 'created_at', 'updated_at', 'children']]]);
    });
});
```

## DAV tests

`DavController` and `public/dav.php` read the raw PHP request
(`Sapi::getRequest()`), so Laravel's HTTP helpers cannot reach them.
`davRequest($user, $method, $path, $body, $headers)` (`tests/Pest.php`)
builds the server both entry points use (`DavServerFactory`), sends it a
`Sabre\HTTP\Request` with Basic auth (factory password `password`; `null`
user = no credentials) and returns the captured `Sabre\HTTP\Response`.
It runs on the `pgsql` connection's PDO, so the per-test transaction still
isolates DAV writes.

- `davUser()` = `makeUser()` + a DAV principal (the observer creates the
  default calendar `my-calendar` and address book `contacts`, not the
  principal). Paths: `calendars/<email>/<calendar>/<object>`,
  `addressbooks/<email>/<book>/<object>`, `principals/<email>/`.
- `davXpath($response)` registers `d`, `cal`, `card`, `cs`, `apple`, `s`;
  `davPropfindBody(['d:displayname', …])` builds a PROPFIND body;
  `davIcs()`/`davVcard()` build minimal objects.
- Files: `DavAuthTest` (auth, discovery), `CalDavProtocolTest`,
  `CardDavProtocolTest`, `DavIsolationTest` (cross-user access, sharing),
  `DavApiConsistencyTest` (API ↔ DAV, `CacheInvalidationPlugin`),
  `DavClientFixtureTest` (`tests/Fixtures/dav/`), `DavImportTest`
  (`Http::fake()` remote server), `EventOccurrenceServiceTest`,
  `PrincipalsServiceTest`, `DavServiceTest`.
- A DAV-level isolation test belongs next to every new DAV-reachable
  resource or method.

Limits of the setup:

- SQLite, not PostgreSQL: the production `BYTEA` columns are not exercised
  (sabre/dav 4.7.1's `PARAM_LOB` fix is invisible here). Escaped fixtures
  still belong in the suite; they pass on SQLite either way.
- `UserCacheService::forgetByPrefix()` needs Redis and is a silent no-op on
  the array store, so event-cache invalidation is asserted with a
  `UserCacheService` spy, not through a cached API listing.
- XML parsing turns CRLF into LF inside `calendar-data`/`address-data`.

### Coverage map

| Area | Calendar / event | Address book / card | Principal |
| --- | --- | --- | --- |
| Auth (none, wrong, unknown, valid) | `DavAuthTest` | `DavAuthTest` | `DavAuthTest` |
| Discovery (home sets, current principal) | `DavAuthTest` | `DavAuthTest` | `DavAuthTest` |
| MKCALENDAR / extended MKCOL | `CalDavProtocolTest` | `CardDavProtocolTest` | — |
| PROPFIND / PROPPATCH (name, color, transp, description, ctag, sync-token) | `CalDavProtocolTest` | `CardDavProtocolTest` | `DavAuthTest` |
| PUT / GET / DELETE, ETag, `If-Match`, `If-None-Match: *` | `CalDavProtocolTest` | `CardDavProtocolTest` | — |
| Validation (415, unsupported component, repair) | `CalDavProtocolTest` | `CardDavProtocolTest` | — |
| `calendar-query` / `addressbook-query` | `CalDavProtocolTest` | `CardDavProtocolTest` | — |
| multiget | `CalDavProtocolTest` | `CardDavProtocolTest` | — |
| `sync-collection` (initial, delta, deletes, bad token) | `CalDavProtocolTest` | `CardDavProtocolTest` | — |
| `free-busy-query` | `CalDavProtocolTest` | — | — |
| MOVE / COPY (`Depth`), `Range` | `CalDavProtocolTest` | — | — |
| vCard 3.0 ↔ 4.0 negotiation | — | `CardDavProtocolTest`, `DavClientFixtureTest` | — |
| Cross-user read / write / move denied | `DavIsolationTest` | `DavIsolationTest` | `DavIsolationTest` |
| Sharing (read-write, read-only, revoke, decline) | `DavIsolationTest` | — | — |
| API ↔ DAV round trip, cache invalidation | `DavApiConsistencyTest` | `DavApiConsistencyTest` | — |
| API edits keep client data (UID, EXDATE, exceptions, VALARM, attendees) | `DavApiConsistencyTest`, `tests/Unit/Dav/EventDTOUpdateTest` | — | — |
| Series edits through an occurrence (`occurrence_start_date`, wall-clock shift, DST) | `DavApiConsistencyTest` | — | — |
| Switching repetition off (`is_recurring: false`) | `DavApiConsistencyTest` | — | — |
| Single-occurrence edit/delete (original slot, moved occurrences, zones, all-day east of UTC) | `DavApiConsistencyTest`, `EventOccurrenceServiceTest` | — | — |
| API times in the user's zone (UTC/other-zone objects, all-day untouched) | `DavApiConsistencyTest` | — | — |
| Re-sharing keeps the invite status | `DavIsolationTest` | — | — |
| Calendar export writes the stored objects | `app/Api/Export/Tests/CalendarExportServiceTest` | — | — |
| vCard parameters and property-name case | — | `tests/Unit/Dav/ContactDTOParseTest` | — |
| Real-client data (Apple, Google, Outlook, Thunderbird, DAVx5) | `DavClientFixtureTest` | `DavClientFixtureTest` | — |
| Occurrence edits, expansion, DST | `EventOccurrenceServiceTest`, `DavClientFixtureTest` | — | — |
| Import from a remote server | `DavImportTest` | `DavImportTest` | — |
| Service CRUD, sharing, deletion | `DavServiceTest` | `DavServiceTest` | `PrincipalsServiceTest` |

### Known gaps (skipped tests)

Each is a `->skip('Known …')` test that states the wanted behavior; it
turns green once the gap is fixed (remove the `skip`).

- Calendars accept VEVENT and VTODO: `createCalendarCustom()`'s
  `components` key is not mapped by sabre (pinned in `CalDavProtocolTest`;
  VTODOs are skipped by the API listing). Kept on purpose (owner decision,
  2026-10-17): real CalDAV todo sync is planned, so no events-only change.
- Accepted, not skipped: a known email can still be confirmed as a
  principal (207 vs 404), because principal URIs carry the email
  (`DavIsolationTest`).

## Caveats

- Time: Carbon's `now()` is real unless the test freezes it; a test whose
  outcome depends on "today" uses `Carbon::setTestNow()` (one file does
  today) and resets it — never a hard-coded date that is only true once.
- External HTTP is faked (`Http::fake()`, 8 files) and side effects are
  caught with `Notification::fake()`/`Queue::fake()`/`Bus::fake()` (8 files).
- The DAV SQLite file is shared by all tests in a process; the transaction
  wrapper handles isolation, but a test that commits through SabreDAV
  directly must clean up. `davRequest()` stays inside the transaction.
- `tests/Feature/` is not a home for anything; new HTTP tests go next to
  their module.
- New dev dependencies are installed only with approval (`docs/AGENTS.md`).

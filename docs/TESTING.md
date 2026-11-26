# Testing

How tests are written in this repository. The rule in one line:

> Test API contracts, tenant isolation, validation and service logic — not
> factories, casts or Eloquent.

Run with `composer test` (clears the config cache, then `php artisan test`)
or `php artisan test` / `./vendor/bin/pest` directly. Filter with
`php artisan test --filter=Todo` or `php artisan test app/Api/Todos/Tests`.
Coverage needs a driver (`pcov` or Xdebug) that is not installed by
default; a coverage driver was not approved (`docs/STANDARDS.md` → Decisions).

Everything here was checked against `tests/TestCase.php`, `phpunit.xml`
and `tests/Pest.php` on 2026-10-13 and again on 2026-11-23.

## Layout and suites

`phpunit.xml` defines the suites below, and Pest finds the tests through
it. The root `pest.php` is not loaded by Pest (it boots only
`tests/Pest.php`) and lacks `app/Plugins/Tests`:

| Suite (`phpunit.xml`) | Path | What lives there |
| --- | --- | --- |
| `Unit` | `tests/Unit/` | shared code: `ApiResponse`, enums, `DockerSecretHelper`, `UrlHelper`, DAV helpers, Pulse metrics, the OpenAPI drift test, the resource-schema drift test (`ResourceSchemaDriftTest`, rule 2.6), the client contract and migration compatibility tests (section 13) |
| `Feature` | `tests/Feature/` | **empty** (only `.gitkeep`); keep it empty — feature tests are co-located |
| `Dav` | `app/Dav/Tests/` | DAV service, protocol, isolation, client fixtures (see [DAV tests](#dav-tests)) |
| `Api` | `app/Api/*/Tests/` | the module tests: `<Module>ApiTest.php` (HTTP), `<Module>Test.php` (factories/models — legacy, see below), `*ServiceTest.php`, `JobTest.php`, `CommandTest.php` |
| `Bots` | `app/Bots/Tests/` | bot state machine and commands |
| `Mcp` | `app/Mcp/Tests/` | the MCP endpoint and its tools |
| `Plugins` | `app/Plugins/Tests/`, `app/Plugins/*/Tests/` | the plugin framework and each plugin |

Fixtures: `tests/Fixtures/` (feeds and pages for the feed reader and
Goodreads, `ai-models.json`/`models-dev.json` for the AI model snapshot),
`tests/Fixtures/client-contract/` (route and migration baselines of the
oldest supported builds), `tests/Fixtures/dav/` (client-shaped `.ics`/`.vcf`)
and `tests/Fixtures/http/` (the `php -S` routers for
`RemoteCalDavClientLiveTest` and `FeedReaderLiveTest`). Test doubles that
need a class live in `tests/Support/` (`FakeCalDavServer`, the Pulse job and
notification).

Binding: `tests/Pest.php` is the only Pest bootstrap file Pest loads
(nested `Pest.php` files are ignored). It binds `Tests\TestCase` to every
suite directory and adds `RefreshDatabase` to everything under `app/Api`,
`app/Plugins` and `app/Mcp/Tests`, so a module test does **not** declare its
own `uses(RefreshDatabase::class)` (a duplicate is tolerated but redundant).
`tests/Unit` and `app/Bots|Dav` tests have no database refresh unless they
ask for it.

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
- `OutboundUrlGuard` resolves every host name to one public address, so no
  test does real DNS; IP literals are still checked.
- Consequence: do not write a test that needs MariaDB, PostgreSQL or Redis
  features; SQLite is what runs. (SQLite compares case-sensitively, which is
  one reason `TodoService::sameName` compares tag names in PHP, rule 5.5.)

## Helpers (`tests/Pest.php`)

| Helper | Use |
| --- | --- |
| `makeUser(array $attributes = [])` | a verified user, the standard fixture |
| `$this->actingAs($user, 'sanctum')` | the standard way to authenticate a request |
| `authHeaders($user)` / `sanctumToken($user)` | only when the test is about tokens themselves (`AuthTest`) |
| `sessionToken($user)`, `bearer($this, $token)`, `issueWidgetToken($this, $parentToken)` | requests with a real bearer token (widget tokens, token lifecycle); `bearer` resets the guard so each request resolves its own token |
| `webToken()` | a new user's web token without expiry as `[$plainText, $personalAccessToken]` (token bookkeeping: `last_used_at`, client version) |
| `expectApiSuccess($response, 200)` / `expectApiError($response, 403)` | status + envelope flag in one line; the standard for every status assertion |
| `Model::factory()->forUser($user)` | user-owned factories have this state; exceptions set the owner another way: `Alert` (`forTracker`/`forGoal`), `DevRequestComment`/`DevRequestVote` (`forDevRequestAndUser`), `DevRequest` (`created_by_user_id`) |
| `storeEvent($this, $user, $payload)`, `storeDayEvents($this, $user, $timezone)` | events through the API in the user's default calendar; `storeDayEvents` is today's timed, all-day and recurring event plus one tomorrow |
| `builtInTracker($user, $key)`, `ownTracker($user, $type)` | check-in trackers |
| `feedItems('<item>…</item>', …)` | real SimplePie items from RSS `<item>` snippets, for feed sync and subscribe tests |
| `enableMcp()`, `mcpAdmin()` | MCP tests: load `laravel/mcp` as `MCP_ENABLED` does; an admin (MCP is admin-only) |
| `davUser()`, `davRequest()`, `davXpath()`, `davPropfindBody()`, `davIcs()`, `davVcard()`, `davVcard4()`, `davSeriesWithOverride()`, `externalCalendar()` | DAV protocol tests, see [DAV tests](#dav-tests) |
| `photoProcessorSpy()` | binds a `VCardPhotoProcessor` that records each card it gets (`->cards`) and returns it unchanged; for "goes through the photo processor" and for cards whose photo the no-op image double would rewrite (vCard 4 data URIs) |

A helper that a second test file needs moves to `tests/Pest.php` and gets a
row here. The one custom expectation is `toBeEmail`.

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
  the fixtures; never a live call. A loopback listener started by the test
  (`RemoteCalDavClientLiveTest`) is allowed when the transport itself is
  under test; it never leaves the machine.
- **Jobs and commands** — assert the effect (rows written, notification
  sent), not that the job was dispatched.
- **Shared contracts** in `tests/Unit` with `covers()`: `ApiResponse`,
  `OpenApiSpecTest` (fails on any drift not listed in the empty
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
the parent. Older `<Module>Test.php` files are full of that kind; they
are not deleted, but no new ones are written and a touched file may lose
them.

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
  `davIcs()`/`davVcard()` build minimal objects; `davSeriesWithOverride($uid,
  $overrideFirst)` is a weekly series with one moved occurrence, the override
  before or after the master. `externalCalendar($user, $server)` connects a
  calendar of the user to a `FakeCalDavServer` collection.
- Files: `DavAuthTest` (auth, discovery), `CalDavProtocolTest`,
  `CardDavProtocolTest`, `DavIsolationTest` (cross-user access, sharing),
  `DavApiConsistencyTest` (API ↔ DAV, `CacheInvalidationPlugin`),
  `DavClientFixtureTest` (`tests/Fixtures/dav/`), `DavImportTest`
  (`Http::fake()` remote server), `EventOccurrenceServiceTest`,
  `PrincipalsServiceTest`, `DavServiceTest`, `RemoteCalDavClientTest` and
  `ExternalCalendarProxyTest` (external calendars against
  `tests/Support/FakeCalDavServer`), `RemoteCalDavClientLiveTest` (the
  client against a real `php -S` listener on `127.0.0.1`, see
  [Caveats](#caveats)).
- A DAV-level isolation test belongs next to every new DAV-reachable
  resource or method.

Limits of the setup:

- SQLite, not PostgreSQL: the production `BYTEA` columns are not exercised
  (sabre/dav 4.7.1's `PARAM_LOB` fix is invisible here). Escaped fixtures
  still belong in the suite; they pass on SQLite either way.
- The array store supports cache tags, so cache invalidation is asserted
  through a cached listing: read, write, read again
  (`CalendarCacheBehaviourTest`). Its tag implementation is not
  `RedisTaggedCache`, so a change to how entries are tagged or flushed
  still needs a check against a real Redis.
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
| Import from a file (other apps' exports, vCard 2.1/3.0/4.0, broken cards, UIDs) | `CalendarFileImportApiTest` | `ContactFileImportApiTest` | — |
| Outbound client: redirects, weak ETags, credentials | `RemoteCalDavClientTest`, `ExternalCalendarProxyTest` | — | — |
| Outbound client: `CURLOPT_RESOLVE` pinning, response size cap | `RemoteCalDavClientLiveTest` | — | — |
| Service CRUD, sharing, deletion | `DavServiceTest` | `DavServiceTest` | `PrincipalsServiceTest` |

### Known gaps

No test is skipped today. A gap that should be fixed is a
`->skip('Known …')` test stating the wanted behavior; one that is accepted
is pinned by a passing test:

- Calendars accept VEVENT and VTODO: `createCalendarCustom()`'s
  `components` key is not mapped by sabre (pinned in `CalDavProtocolTest`;
  VTODOs are skipped by the API listing). Kept on purpose (owner decision,
  2026-10-17): real CalDAV todo sync is planned, so no events-only change.
- Accepted, not skipped: a known email can still be confirmed as a
  principal (207 vs 404), because principal URIs carry the email
  (`DavIsolationTest`).

## Caveats

- Time: Carbon's `now()` is real unless the test freezes it; a test whose
  outcome depends on "today" freezes it (`Carbon::setTestNow()` and a reset,
  or `$this->travelTo()`) — never a hard-coded date that is only true once.
- External HTTP is faked (`Http::fake()`) and side effects are caught with
  `Notification::fake()`/`Queue::fake()`/`Bus::fake()`.
- `Http::fake()` replaces the transport, so curl options are never applied
  there. `RemoteCalDavClientLiveTest` covers them instead: it starts
  `php -S` on a free loopback port per test (stopped in `afterEach`) and
  binds an `OutboundUrlGuard` that pins the unresolvable `dav.test` to
  `127.0.0.1`, so a successful request proves the pinning.
- The DAV SQLite file is shared by all tests in a process; the transaction
  wrapper handles isolation, but a test that commits through SabreDAV
  directly must clean up. `davRequest()` stays inside the transaction.
- `tests/Feature/` is not a home for anything; new HTTP tests go next to
  their module.
- New dev dependencies are installed only with approval (`docs/AGENTS.md`).

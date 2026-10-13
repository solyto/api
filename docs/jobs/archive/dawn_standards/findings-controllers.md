# Findings — controllers, routing, OpenAPI (TASK-3)

Counts from 2026-10-13 over `app/` unless stated. "owner"/"AI" per
`provenance.md`.

## 1. `@OA` path prefix drift

- `path="/api/…"`: 186 operations / 33 controller files.
  `path="/v1/…"`: 82 / 15 files. `app/OpenApi/OpenApiSpec.php:12` declares
  `@OA\Server(url="/api/v1")`, so **both** groups produce wrong absolute
  URLs in the generated spec; only a bare `/todos` would be right.
- Split by file, not by author: `Todos`, `Notes`, `Users`,
  `Notifications`, `EventAttachmentController` use `/v1/`; `Libraries`,
  `Calendars`, `Contacts`, `Feeds`, `Finances`, `TimeTracking`, `Tags`,
  `Shortcuts`, … use `/api/`. `CheckInController` has both (2 + 1).
- `tests/Unit/OpenApiSpecTest.php:128-137` normalises all three prefixes
  away, so the test cannot catch this.
- Beyond the prefix, 80 operations document paths that do not exist
  (`OPENAPI_KNOWN_DRIFT`): e.g. `/api/check-ins` vs route prefix `check-in`
  (`routes/api.php:151`), `/api/clipboards` vs `clipboard` (`:380`),
  `/api/time-tracking-entries` vs `time-tracking/entries` (`:388`),
  `/api/contacts/address-books` vs `address-books` (`:207`). 80 of 268
  operations = 30 % of the spec points nowhere.

## 2. Envelope schema: inline vs `allOf`

- Inline `@OA\Property(property="success"…)` + `message` + `data` repeated
  per response: 137 occurrences in 20 controller files (all `/api/` files).
  `allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")}`: 81 in
  15 files (all `/v1/` files).
- Example pair: `LibraryBookController.php:37-42` (inline) vs
  `TodoController.php:35-39` (`allOf`).

## 3. 422 schema reference

- `ref="#/components/schemas/ValidationErrorResponse"`: 54 in 21 files.
  `ErrorResponse` for 422: `TodoController.php:157,214,305,367`.
  `OpenApiSpec.php:33-61` defines both with identical shape (`errors` is
  `nullable` in one). One of the two is redundant.

## 4. `operationId`

- 268 operations, 264 `security=` lines (4 public auth operations without).
- Generic / duplicate ids: `search` ×4 (`LibraryBookController:357`,
  `LibraryMovieController:387`, `LibraryGameController:257`,
  `LibraryMusicController:396`), `import` ×5 (`LibraryBookController:398`,
  `LibraryMovieController:331`, `LibraryGameController:307`,
  `LibraryMusicController:446`, `LibraryRecipeController:247`). Duplicate
  `operationId`s are invalid OpenAPI; every other operation follows
  `<resource><Action>` (`todoStore`, `listLibraryBooks`).

## 5. Inline `$request->validate()` vs FormRequest

22 sites / 16 files. By payload size:

| Size | Sites |
| --- | --- |
| one field (`title`, `url`, `name`, `todo_id`, `note_id`, `order`, `shortcuts`) | `TodoController:312`, `PasskeyController:91`, `Library{Book,Movie,Music,Game,Recipe}Controller` `url` ×5, `EventAttachmentController:136,232`, `CalendarController:177`, `ShortcutController:220`, `TodoWorkspaceController:271,333` (`categories.*`) |
| two–three fields | `TodoController:375`, `TimeTrackingEntryController:266` (start), `TimeTrackingEntryController:390` (statistics), `CheckInController:75`, `NoteController:106`, `NotificationSettingsController:107`, `FeedController:212,286` |
| six fields | `TimeTrackingEntryController:158-165` (`update` — the only one that duplicates a FormRequest-sized shape inline; the sibling `store` has a `StoreTimeTrackingEntryRequest`) |

The owner's own `TodoController:312` ("`title` for a subtask") is the model
for the allowed case. Decision proposed: inline is fine for ≤ 3 fields on a
child/action endpoint whose shape exists nowhere else; `update` endpoints
always get a FormRequest.

## 6. `try/catch` in controllers

- 27 `catch` sites in 7 controllers: `CalendarController` 10,
  `ContactController` 6, `AuthController` 5 (`\RuntimeException`),
  `PasskeyController` 2, `FeedController` 2 (domain exceptions),
  `Calendars/ImportController` 1, `Contacts/ImportController` 1
  (`ImportException`).
- 16 of them are the shape `catch (\Exception $e) { report($e); return
  ApiResponse::error('…', 500); }` (`CalendarController:103-108`,
  `ContactController:97-102`). `bootstrap/app.php:41-90` already renders
  any uncaught `Throwable` on `api/*` as `ApiResponse::serverError(...)`,
  and Laravel reports it before rendering — the only thing the catch adds is
  a custom message. Both files are the owner's.
- The other 11 catch **typed** exceptions and map them to a status:
  `AuthController` 5 (`:111` `ValidationException` → 401; `:246`
  `\RuntimeException` → `match` on the message → 401; `:331` → 400; `:358`
  → 401; `:63` → `serverError($e->getMessage())`), `PasskeyController` 2
  (`:34` → 401, `:59` → 422), `FeedController` 2
  (`FeedAlreadySubscribedException` → 409), the two `ImportController`s
  (`ImportException`). A domain error mapped to a status is a different
  case: it could move into the central map (as `FeedException` already
  could) but is not wrong per se. The only one that is effectively the
  report-and-500 shape with a custom message is `AuthController:63-66`.

## 7. Duplicated branches

`LibraryBookController::recommend` `:131-156` and
`LibraryMusicController::recommend` `:131-156`: the `NEW` branch and the
default branch each do `if (! $recommendation) return 404; return success(new
LibraryRecommendationResource(...))` — the same six lines twice, with only
the service call differing. The branch itself exists because
`LibraryBookService::recommend` `:114-116` *also* special-cases `NEW`.
Both services then dispatch with `call_user_func([$recommender, $type->value])`
(`LibraryBookService:118`, `LibraryMusicService:120`).

## 8. Health route

`routes/api.php:60-67`: closure route, `response()->json([...])` (the only
non-`ApiResponse` JSON in the app besides `ApiResponse` itself) and
`env('API_VERSION', '1.0.7')` at request time. Laravel 12 docs
(configuration.md, "Configuration Caching"): after `config:cache` the
`.env` file is not loaded and `env()` returns only system-level variables —
the version silently becomes `1.0.7` in production. `bootstrap/app.php:25`
has the same `env('TRUSTED_PROXIES')` read (bootstrap runs before config is
loaded, so this one is a known Laravel idiom and is fine).

## 9. Route file shape

`routes/api.php` (≈ 240 `Route::` calls): two `Route::prefix('v1')` groups
(public `:59-78`, `auth:sanctum` `:80+`), domain prefixes, `apiResource('/',
X::class)->parameters(['' => 'todo'])` for root resources (`:127,135,148,250,
260,267,273`), custom actions before the resource line, throttled auth
routes via named limiters. Consistent; the Libraries block (`:236-300`)
mixes `prefix()->group()` sub-blocks with flat `recipes/*` lines (`:276-277`).
`->name()` is set on some groups (`todos.`, `notes.`, `music.`, …) and not
others; API route names are never used (`grep -rn "route('" app bootstrap`
→ `TelegramBotService:40` uses the webhook name from `routes/web.php`;
`bootstrap/app.php:97` redirects to `route('login')`, which is **not
registered** anywhere — a redirect to a missing route for non-API
unauthenticated requests).

## 10. `HandlesApiAuth` and ownership

- `abort_unless($this->isResourceOwner($request, $x), 403)` on every
  `show/update/destroy` in all modules checked (`Todos`, `Notes`, `Tags`,
  `Libraries`, `TimeTracking`, …). Good and uniform.
- Resolving a **foreign id from the payload**: `EventAttachmentController:138`
  does it right (`Todo::forUser($request->user()->id)->find(...)`);
  `TimeTrackingEntryController:167-170,271-273` does it right
  (`find` + `isResourceOwner`); the Todos/Notes/Libraries/Workspace services
  do not (→ `findings-data.md` §1).

## 11. Other

- `auth()`/`Auth::` outside tests: `LoginRequest.php:23,29`
  (`Auth::attempt` inside a FormRequest — Breeze pattern),
  `app/Dav/Auth/LaravelAuthBackend.php:19`. `request()` helper: only
  `IsTelegramBot.php:50`. `abort(` bare: `LibraryCoverController:56`.
- Controllers do not extend a base class and do not use middleware
  attributes; constructor-promoted `private readonly` services everywhere.
- Largest: `CalendarController` 1 064 lines / 20 actions,
  `ContactController` 686 / 12, `UserSettingsController` 554 / 15 — mostly
  annotation volume, not logic.

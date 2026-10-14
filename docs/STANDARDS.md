# solyto api — coding standards (DRAFT, in progress)

Status: working draft, first session 2026-10-13. Every rule below was
extracted from a real file in this repository — either code the owner is
unhappy with, or the owner's own reference module (`app/Api/Todos/`) showing
the intended shape. Nothing is imported from a generic Laravel style guide.
Rules are written to be checkable: a reviewer (human or agent) must be able
to say "this line violates rule X.Y", and most rules carry a grep.
How this document is built: `docs/STANDARDS_WORKFLOW.md`.

Structure:

- **Rules** — numbered, grouped by topic. Each has: the rule, why, a
  bad/good example from this repo with file and line, and a check.
- **Decisions** — choices the owner made that are not obvious from the code.
- **Open questions** — things waiting for the owner's answer, each with the
  data point that raised it, including places where the owner's own code
  breaks a proposed rule.

Concrete cleanups found while writing the rules live in
`docs/STANDARDS_BACKLOG.md`; this file describes the target and is never a
todo list. How tests are written: `docs/TESTING.md`. Working notes with the
full counts: `docs/jobs/dawn_standards/findings-*.md`.

Counts are from 2026-10-13 on `app/` and are reproducible with the greps in
each rule's "Check" line. Line numbers refer to that date.

## The recurring themes

This codebase is in better shape than the frontend was: 27 line comments
in all of `app/Api` non-test code, zero `dd()`, zero `env()` in `app/`,
`$fillable` on all 52 models, an ownership `abort_unless` on every
`show/update/destroy`, one response envelope. Where it goes wrong, it goes
wrong in the same few ways — and most of them are the owner's older code,
not the AI's:

1. **Two generations of OpenAPI annotations.** `/api/…` + inline envelope
   (186 operations, June 2026) next to `/v1/…` + `allOf SuccessResponse`
   (82, later). Neither matched `@OA\Server(url="/api/v1")`; 80 of 268
   operations pointed at paths that did not exist. Prefixes and paths are
   fixed; 2 operations remain, on purpose (section 2).
2. **`exists:table,id` taken as an ownership check.** 40 rules; a user can
   link another user's category, tag, genre or folder to their own record
   and read its name back (section 3, backlog item 1).
3. **`try { … } catch (\Exception $e) { report($e); return 500 }` around a
   service call** — 16 sites — when `bootstrap/app.php` already does exactly
   that for every uncaught exception (section 7).
4. **Repetition inside a service that one private method would end**:
   the same two `cache->forget` lines seven times, four byte-identical
   genre sub-CRUDs, two identical `recommend` branches (sections 4, 8).
5. **Dispatch by string and service-locator calls**: `call_user_func([$x,
   $enum->value])`, `app()->makeWith(…, ['user' => $user])`, `$this->{$route}()`
   where a `match` or a method argument would do (section 4).
6. **Formatting by habit, not by tool.** Pint is installed and never run;
   `!$x`/`! $x`, `fn()`/`fn ()`, aligned `=>` all coexist (section 11).

---

## 1. Controllers and routing

### 1.1 A controller action is: ownership check, delegate, wrap

An action receives a `FormRequest` (or a plain `Request` for reads and
one-field actions), checks ownership with `abort_unless($this->isResourceOwner(...), 403)`
where a model is bound, calls one service method, and returns
`ApiResponse::success(new XResource(...), 'message.'[, 201])`. No query, no
branch on data, no loop.

Good (`app/Api/Todos/Controllers/TodoController.php:217-224`):

```php
public function update(UpdateTodoRequest $request, Todo $todo): JsonResponse
{
    abort_unless($this->isResourceOwner($request, $todo), 403);

    $todo = $this->todoService->update($todo, $request->validated());

    return ApiResponse::success(new TodoResource($todo), 'Todo updated successfully.');
}
```

Bad (`app/Api/Calendars/Controllers/CalendarController.php:95-110`): a
`getByName` pre-check that belongs in the service, then the service call
wrapped in `try/catch` (rule 7.2). The whole file is 1 064 lines for 20
actions; the logic in it is maybe 150.

Check: `grep -rn 'foreach\|while (' app/Api/*/Controllers` — 0 is the target
(currently 0 outside annotation blocks); `grep -rn 'catch (' app/Api/*/Controllers`
→ see 7.2.

### 1.2 A foreign id from the payload is resolved through the user's scope

When an action receives an id of *another* record (a project to attach, a
todo to link), it is loaded through `Model::forUser($request->user()->id)->find($id)`
or `find` + `isResourceOwner`, never trusted because validation said it
exists.

Good (`app/Api/Calendars/Controllers/EventAttachmentController.php:136-141`):

```php
$request->validate(['todo_id' => 'required|uuid']);

$todo = Todo::forUser($request->user()->id)->find($request->todo_id);
if (!$todo) {
    return ApiResponse::notFound('Todo not found.');
}
```

Also good (`app/Api/TimeTracking/Controllers/TimeTrackingEntryController.php:167-170`).

Bad: every `category_id`, `tags.*`, `genres.*`, `parent_id`,
`categories.*`, `category_ids.*` rule listed under rule 3.2 — the id goes
from `$request->validated()` straight into `Model::create()` / `attach()`.

### 1.3 Two identical branches are one branch

If both arms of an `if` end in the same `if (! $x) return 404; return
success(new Resource($x))`, the difference is a value, not a flow.

Bad (`app/Api/Libraries/Controllers/LibraryBookController.php:131-156`, same in
`LibraryMusicController.php:131-156`):

```php
if ($type === LibraryRecommendationEnum::NEW) {
    $recommendation = $this->libraryBookService->recommendNew($request->user());

    if (! $recommendation) {
        return ApiResponse::error('No recommendation found.', 404);
    }

    return ApiResponse::success(new LibraryRecommendationResource($recommendation), '…');
}

$recommendation = $this->libraryBookService->recommend($request->user(), $type);

if (! $recommendation) {
    return ApiResponse::error('No recommendation found.', 404);
}

return ApiResponse::success(new LibraryRecommendationResource($recommendation), '…');
```

Good: the service decides (`recommend(User $user, LibraryRecommendationEnum
$type)` handles `NEW` internally, see 4.3); the controller has one call and
one `if`.

### 1.4 Routes: one file, two `v1` groups, domain prefixes, resource line last

`routes/api.php` only. Public group (`health`, `auth/*`, throttled by named
limiters) and the `auth:sanctum` group. Inside: `Route::prefix('<domain>')->group()`,
custom actions first, `Route::apiResource('/', XController::class)->parameters(['' => 'x'])`
last so `due-date` is not swallowed by `{todo}` (`routes/api.php:121-136`).
Sub-resources are explicit lines next to the resource (`:132-134`). Flat
lines inside a domain block (`:276-277` `recipes/import/{service}` then
`apiResource('recipes')`) are the exception; they should be a `recipes`
sub-block like the siblings.

`->name('x.')` is set on seven groups and used by nothing (`grep -rn "route('" app`
finds only the Telegram webhook name from `routes/web.php`); keep it or
drop it, but do not add names to new groups without a caller.

Check: `grep -c 'Route::' routes/*.php` — only `api.php` and `web.php`
exist; a new route file is a violation.

### 1.5 The health route follows the same rules as everything else

`routes/api.php:60-67` is a closure returning `response()->json([...])` with
`env('API_VERSION', '1.0.7')`. Laravel 12 (configuration.md, "Configuration
Caching", confirmed 2026-10-13): after `config:cache` the `.env` file is not
loaded and `env()` returns only real OS environment variables, so the
version silently falls back to `'1.0.7'` in production. `env()` belongs in
`config/*.php`; the route reads `config('app.version')` and returns through
`ApiResponse::success`.

Check: `grep -rn "env(" routes app` → 0 (`bootstrap/app.php:25`
`env('TRUSTED_PROXIES')` is the one legitimate call: bootstrap runs before
config exists).

## 2. OpenAPI annotations

The spec is generated from `@OA` docblocks on every action (268 operations)
and `@OA\Schema` on 64 of 68 resources. `tests/Unit/OpenApiSpecTest.php`
checks every documented path (server URL + operation path) against the
route table and pins the 2 operations that are kept on purpose as
`OPENAPI_KNOWN_DRIFT` (rule 2.2).

### 2.1 Operation paths are relative to `@OA\Server`

`app/OpenApi/OpenApiSpec.php:12` declares `@OA\Server(url="/api/v1")`, so
operation paths carry no prefix: `path="/todos"`, never `/api/todos` or
`/v1/todos` (those generate `/api/v1/api/…` and `/api/v1/v1/…`). All 268
operations follow this since OQ-1 was decided (2026-10-13), and
`OpenApiSpecTest` prepends the server URL instead of normalising prefixes.

Good: `app/Api/Todos/Controllers/TodoController.php:25` `path="/todos"`.

Check: `grep -rcE 'path="/(api|v1)/' app/Api/*/Controllers` → all 0.

### 2.2 The path in the annotation is the path in `routes/api.php`

The route table is the source of truth; when they disagree, the annotation
is fixed, not the route. The 80 drifting operations of 2026-10-13
(`/check-ins` for prefix `check-in`, `/time-tracking-entries` for
`time-tracking/entries`, `/user-settings/*` for `users/me/settings/*`, …)
were fixed module by module (job `fix`, 2026-10-13).

One operation still documents a route that does not exist, and stays on
purpose (owner decision, 2026-10-13): `LibraryMusicController::searchAlbumOnDeezer()`
(`GET /libraries/music/search/deezer/{artist}/{album}`). The method has no
route and is left untouched until the owner decides what to do with it.
So the constant is not empty and is not deleted. (The empty
`TelegramBotConnectionController::confirmToken()` stub and the empty
`GET notes/export` stub were deleted with their annotations and route,
owner decision, 2026-10-14.) `OpenApiSpecTest` compares
the drift exactly, so a new drift and a silently fixed one both fail.

Check: `OPENAPI_KNOWN_DRIFT` length = 1 (the method above).

### 2.3 Responses reference the envelope, never redefine it

Success bodies are `allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")}`
plus one `data` property; errors are `@OA\JsonContent(ref="#/components/schemas/ErrorResponse")` (validation 422s: `ValidationErrorResponse`, rule 2.4).

Good (`TodoController.php:35-39`):

```php
 *         @OA\JsonContent(
 *             allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")},
 *
 *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/Todo"))
 *         )
```

Bad (`LibraryBookController.php:37-42`, 137 times in 20 files):

```php
 *         @OA\JsonContent(
 *
 *             @OA\Property(property="success", type="boolean", example=true),
 *             @OA\Property(property="message", type="string", example="Books retrieved successfully."),
 *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/LibraryBook"))
 *         )
```

Check: `grep -rn '@OA\\Property(property="success"' app/Api/*/Controllers` → 0.

### 2.4 422 validation errors use `ValidationErrorResponse`

A FormRequest or `validate()` failure always returns per-field `errors`
(`bootstrap/app.php` → `ApiResponse::validationError()`), so its 422 response
references `ValidationErrorResponse`. `ErrorResponse` is for 422s the code
raises itself without field errors (`FeedController::testFeed`,
`storeSubscription`: "Feed URL doesn't seem valid"). Owner decision OQ-2.

Check: `grep -rn 'response=422, description="Validation error"' app/Api/*/Controllers | grep -c 'schemas/ErrorResponse'` → 0.

### 2.5 `operationId` is `<resource><Action>`, unique

Duplicate `operationId`s are invalid OpenAPI and break generated clients.
`search` appears 4× (`LibraryBookController.php:357`, `LibraryMovieController.php:387`,
`LibraryGameController.php:257`, `LibraryMusicController.php:396`) and
`import` 5× (`…BookController:398`, `MovieController:331`, `GameController:307`,
`MusicController:446`, `RecipeController:247`). Everything else follows
`todoStore`, `listLibraryBooks`, `timeTrackingEntryDestroy`.

Check: `grep -rhoE 'operationId="[^"]+"' app/Api | sort | uniq -d` → empty.

### 2.6 Rules, request-body annotation and resource schema change in the same commit

The FormRequest is the contract; the `@OA\RequestBody` and the resource's
`@OA\Schema` are its documentation. Drift today, all in the owner's
reference module: `StoreTodoRequest` has 14 rules, `TodoController.php:131-143`
documents 9 (`link`, `recurrence_*` missing); `TodoResource::toArray` emits
20 keys, its schema lists 14; `{todo}` is documented `type="integer"`
(`:93,181,240`) while `todos.id` is a UUID. Nine other resources drift
(`docs/jobs/dawn_standards/findings-data.md` §3).

Check: `php artisan test tests/Unit/ResourceSchemaDriftTest.php` compares
each resource's top-level `@OA\Schema` properties with its `toArray()` keys
(known drift is allow-listed in the test). `rules()` vs `@OA\RequestBody` has
no check; the reviewer diffs them for the touched endpoint.

## 3. Requests and validation

### 3.1 A write endpoint has a FormRequest; a one-to-three-field action may validate inline

`app/Api/<Module>/Requests/<Verb><Resource>Request.php`, pipe-string rules,
`sometimes` on update rules, `attributes()` where a field name would read
badly (`StoreTodoRequest.php:29-34`). Inline `$request->validate([...])`
is allowed when the shape is tiny and exists nowhere else — the owner's own
`TodoController.php:312` (`['title' => 'required|string']`) is the model;
19 of the 22 inline sites are of that kind. `update` actions always get a
FormRequest: `TimeTrackingEntryController.php:158-165` validates six
fields inline while its sibling `store` has `StoreTimeTrackingEntryRequest`.

Arrays instead of pipe strings only when a rule object is in the list
(`RegisterRequest.php:15` `['required', 'confirmed', Password::defaults()]`).

Check: `grep -rn '\$request->validate(' app/Api/*/Controllers` — each hit
≤ 3 fields and not an `update`.

### 3.2 `exists:` proves existence, not ownership

`exists:todo_categories,id` accepts any user's category. Because
`todo_categories`, `tags`, `note_categories` and the genre tables use
auto-increment ids, another user's id is a small integer away. The rule:
a foreign id that must belong to the caller is validated with a scoped
rule **or** resolved through `forUser` in the service — one of the two,
on every such field.

Bad (`app/Api/Todos/Requests/StoreTodoRequest.php:20-22`, flows unchecked
through `TodoService::create:159-168` into `Todo::create($data)` and
`tags()->attach`; the response then serialises the foreign category via
`TodoResource.php:45`):

```php
'category_id' => 'nullable|exists:todo_categories,id',
'tags' => 'nullable|array',
'tags.*' => 'exists:tags,id',
```

Good — scoped rule (Laravel 12 validation docs, `Rule::exists(...)->where(...)`,
confirmed 2026-10-13):

```php
'category_id' => ['nullable', Rule::exists('todo_categories', 'id')->where('user_id', $this->user()->id)],
```

or scoped lookup in the service, as `EventAttachmentController.php:138` and
`TimeTrackingEntryController.php:167-170` already do.

Affected today (all unchecked): Todos (`category_id`, `tags.*`), Todo
workspaces (`categories.*` ×3), Notes (`category_id`, `tags.*`), Note
categories (`parent_id`), Libraries books/movies/games/music (`genres.*`,
`tags.*`), links (`category_id`, `tags.*`), quotes (`tags.*`), time-tracking
projects (`category_ids.*`); plus `StoreDevRequestRequest.php:19`
`created_by_user_id`, which the caller picks and `DevRequestService::create:27`
stores — attribution must come from `$request->user()`.

Check: `grep -rn "exists:" app/Api --include=*.php | grep -v Tests` — every
hit either uses `Rule::exists(...)->where('user_id', …)` or names a service
line that re-scopes it. Currently 40 hits, 0 scoped.

### 3.3 `authorize()` stays empty; ownership is a controller line

0 of 106 requests implement `authorize()`; `HandlesApiAuth::isResourceOwner`
in the controller is the single place. Do not start a second one.

### 3.4 A request validates; it does not act

`LoginRequest::authenticate()` (`app/Api/Users/Requests/LoginRequest.php:21-38`)
runs `Auth::attempt` and the email-verification check — Laravel Breeze's
pattern, the owner's code, and the only request with behaviour. Accepted
as is (Decisions); new requests do not get methods beyond `rules()`,
`attributes()`, `messages()`.

## 4. Services

Reference: `app/Api/Todos/Services/TodoService.php`. 81 services share one
shape: constructor-promoted `private readonly` collaborators, typed class
constants for cache keys, `list/find/create/update/destroy`, reads through
`UserCacheService::remember`, writes ending in `forget`. The rules below
are about the places that drift from it.

### 4.1 A service takes the user as a method argument, never in its constructor

Services are container singletons; a `User` in the constructor makes the
class un-injectable and forces every caller through `app()->makeWith`.

Bad (`app/Api/Libraries/Services/LibraryReleases.php:22-28` → 7 call sites
`app()->makeWith(LibraryReleases::class, ['user' => $user])` in
`LibraryBookService.php:143`, `LibraryMusicService.php:145`,
`LibraryMovieService.php:116`, `Jobs/Grab{Book,Movie,Music}Releases.php:36`,
`Jobs/ScaleCovers.php:70`).

Good: every other service — `TodoService::list(User $user)`,
`CalendarService::listEvents(User $user, string $yearMonth)`.

Check: `grep -rn 'makeWith' app` → 0; `grep -rn 'User \$user' app/Api/*/Services --include=*.php | grep __construct` → 0.

### 4.2 Collaborators are injected, not located

`app(XService::class)` inside a service method is acceptable only across the
`IntegrationGateway`/`QuickAddService` seam, where the gateway deliberately
resolves module services late (`app/Shared/Services/IntegrationGateway.php:26,36,46`)
and in `ExportService::export:45-61` (15 feature services behind a `match`;
injecting all 15 would be worse). Inside a module service, inject.

Check: `grep -rn 'app(' app/Api/*/Services` → target is `ExportService.php:46-60`
only. Today it also hits the three `Library*Service.php` `makeWith` sites (rule
4.1) and `LibraryRecommender.php:116` `app(AiService::class)` — the latter is a
violation of this rule (backlog, Rewrites).

### 4.3 Dispatch on an enum is a `match`, not a string call

`call_user_func([$recommender, $type->value])`
(`app/Api/Libraries/Services/LibraryBookService.php:118`,
`LibraryMusicService.php:120`) and `$this->{$this->routes[$name]}()`
(`app/Bots/Traits/IsTelegramBot.php:162`) hide the four possible targets from
the reader, the IDE and a static analyser. `LibraryRecommendationEnum` has
four cases, `LibraryRecommender` four public methods.

Good shape (owner's `app/Shared/Services/QuickAddService.php:81-130`):

```php
return match ($contentType) {
    QuickAddContentType::Todo   => $this->commitTodo($user, $content, $metadata),
    QuickAddContentType::Music  => $this->commitMusic($user, $content),
    // …
};
```

Check: `grep -rn 'call_user_func\|\$this->{\$' app` → 0.

### 4.4 Repeated housekeeping lines become one private method

The pair `forget([CACHE_KEY, $id]); forget([CACHE_KEY_DUE, $id]);` appeared
seven times in `TodoService.php`; it is now `forgetUser()` (`TodoService`,
`TimeTrackingService`, `NoteService`). Rule: when the same two or more
statements appear in a third method of one class, they are a `private function forgetUser(string $userId): void`.
This applies to the reference module too (OQ-3, 2026-10-13).

Check: `grep -c 'cache->forget' app/Api/*/Services/*.php` — a service with
more `forget` lines than public methods has copies.

### 4.5 A method that returns its argument does not exist

`findGenre(LibraryBookGenre $genre): LibraryBookGenre { return $genre; }`
(`LibraryBookService.php:170-173`, same in `LibraryMovieService.php:166`,
`LibraryGameService.php:126`, `LibraryMusicService.php:174`). `TodoService::find:51-56`
loads relations and so earns its place; a pass-through is deleted and the
controller returns the bound model.

Check: `grep -rn -A3 'function find' app/Api/*/Services | grep -B2 'return \$[a-z]*;$'`.

### 4.6 Return types say what comes back

`: mixed` on 20 methods (11 files), `: ?array` on 42 (19 files), almost all
in `Libraries`. `recommendNew(User $user): mixed` (`LibraryBookService.php:121`)
returns a recommendation array or `null`; `import(...): mixed`
(`LibraryGameService.php:113`) returns a model or `null`. For external JSON
the repo already has DTOs (`app/Api/Libraries/DTOs/*ReleaseDTO.php`,
`*SearchResultDTO.php`): `search()` returns `array<XSearchResultDTO>`,
`import()` returns `?XDTO`. `mixed` is only honest on a generic callback
wrapper (`UserCacheService::remember:56`, `BaseNotification::withLocale:24`).

Check: `grep -rn '): mixed' app/Api/*/Services` → the two wrapper cases only.

### 4.7 One aggregate, one service; sub-resources inside it

`TodoService` covers todos + subtasks + recurrence; `TodoCategoryService`
and `TodoWorkspaceService` are separate aggregates. The four
`Library{Book,Movie,Game,Music}Service` each carry a genre sub-CRUD that is
byte-identical after renaming (`diff` of the genre blocks: 2 lines) plus
four `Library*GenreController` files of 220 lines each. They stay as four
copies (OQ-4, decided 2026-10-13: leave as is): no `LibraryGenreService`
parameterised by model class, because "no new abstractions" wins here. A
genre change is made in all four; no fifth copy is added.

### 4.8 Catch what you can handle, by type

Services catch the exception their call can raise (`ConnectionException`
in `DeezerService.php:52`, `UniqueConstraintViolationException` in
`FeedService.php:320`, `InvalidFormatException` in `SteamService.php:47`)
and return a domain value. `catch (\Exception)` is acceptable only when
probing (`TodoService::resolveDate:137-153` tries four date formats). It is
not acceptable to swallow a handler's error and return `false`
(`IsTelegramBot.php:161-167`), or to turn "service down" and "no results"
into the same `null` (`TmdbService.php:234,265,287`, `GoodreadsService.php:47,113`).

Check: `grep -rn 'catch (\\Exception\|catch (\\Throwable' app/Api/*/Services app/Shared/Services`
— each hit is a probe or has a why-comment.

### 4.9 `Log::info` is not a trace

Logging in services is for failures the code cannot raise
(`UserCacheService.php:39,50,84,104` warn with the key, never the payload).
`NoteImportService.php:59,63,81,89,95` logs every path it walks at `info`
level; `RefreshCalendarCache.php:26` announces that it is running. Delete
those; the queue and `pail` already show the job.

Check: `grep -rn 'Log::info' app` → 11 today, target 0 outside commands.

## 5. Models and queries

### 5.1 A model is `$fillable`, casts, typed relations, `scopeForUser`, factory wiring — nothing else

Reference `app/Api/Todos/Models/Todo.php`. All 52 models use `$fillable`
(`$guarded`: 0). Relations declare their return type everywhere except
`app/Api/Users/Models/User.php:101-126` (`friends()`,
`sentFriendRequests()`, `receivedFriendRequests()`, `allFriendRequests()` —
the first returns a query builder, which the missing type conceals).
Business logic beyond role checks (`User::isAdmin`) and relations belongs in
the service: `NoteCategory::getAncestors()` (`:97`) is a tree walk that
should live in `NoteCategoryService`.

Check: `grep -rnE 'public function [a-zA-Z]+\(\)$' app/Api/*/Models` → 0.

### 5.2 `scopeForUser` is the tenant scope and every list query starts with it

37 of 37 user-owned models have it; every `list()` in every service starts
with `Model::forUser($user->id)`. Keep it that way. The signature is
`scopeForUser(Builder $query, string $userId)` (Eloquent `Builder`; user ids
are UUID strings) in all 37 models (OQ-5, decided 2026-10-13).

Check: `grep -rn '::where(' app/Api/*/Services | grep -v forUser` — each
hit must be a non-user-owned table.

### 5.3 Casts in `$casts`

45 models use `protected $casts`, 2 use the `casts()` method
(`User.php`, `Passkey.php`). Both are documented in Laravel 12; this repo
uses the property. Do not mix within a module.

Check: `grep -rl 'function casts()' app/Api/*/Models` → the two existing.

### 5.4 UUID or auto-increment follows the migration, and the annotation follows the model

22 models carry `HasUuids` (`todos`, `notes`, `users`, projects, …), 30 are
integer-keyed (categories, genres, tags, subtasks, settings). That split is
by design and is why rule 3.2 matters for the integer ones. The `@OA`
parameter type must match: `TodoController.php:93` says `type="integer"`
for a UUID.

### 5.5 Raw SQL only when Eloquent cannot express it, and never on `pgsql` from the app

`whereRaw('LOWER(name) = ?', …)` (`TodoService.php:73,86`) is the only raw
fragment in services — a case-insensitive match that MariaDB does by
collation and SQLite (tests) does not. `DB::connection('pgsql')` outside
`app/Dav`: `StatisticsService.php:35` (read) and the two Contacts repair
commands (write). Nothing new touches `pgsql` from `app/Api` without being
flagged first (project hard rule).

Check: `grep -rn "connection('pgsql')" app/Api app/Shared` → the three existing files.

## 6. Resources

### 6.1 Every relation is `whenLoaded`; the service decides what is loaded

`TodoResource.php:45-50`: `category`, `tags`, `subtasks` through `whenLoaded`,
`TodoService::list` eager-loads the three. Three resources read a relation
directly: `DevRequestCommentResource.php:36` (`$this->user->name`),
`FeedSubscriptionResource.php:34` (`$this->feed->url`),
`DevRequestResource.php:64` (`$this->comments->count()` — a query per item
in a list). Either `whenLoaded` + eager load, or `withCount('comments')`.

Check: `grep -rnE '\$this->(user|feed|comments)->' app/Api/*/Resources` → 0.

### 6.2 Every resource has a schema, and the schema lists what `toArray` returns

64 of 68 resources carry `@OA\Schema`; the four `*SearchResultResource`
files do not. Ten schemas drift from their `toArray()` (rule 2.6 and
`findings-data.md` §3). Field names are the column names, snake_case.

Check: `grep -rL '@OA\\Schema' app/Api/*/Resources` → 0.

### 6.3 Closing parens stay with the call

`TodoResource.php:45-50` puts the `)` of each `whenLoaded(…, fn () => …)` on
its own line; nothing else in the repo does. One line per field.

## 7. Errors and responses

### 7.1 Every JSON body is built by `ApiResponse`

`app/Api/ApiResponse.php` is the only `response()->json` in `app/`
(verified: 0 other hits). Status codes: 201 create, 200 everything else
including deletes (`data: null`), 422/401/403/404/405/429 from the central
map. The health route (rule 1.5) is the one exception to fix.

### 7.2 Throw; do not catch what `bootstrap/app.php` already renders

`bootstrap/app.php:41-90` maps `ValidationException`, `AuthorizationException`,
`AuthenticationException`, `ModelNotFoundException`, `NotFoundHttpException`,
`MethodNotAllowedHttpException`, `QueryException`, `ThrottleRequestsException`,
`HttpException` and finally any `Throwable` → `ApiResponse::serverError`.
Laravel reports the exception before rendering it. So this, 16 times in
`CalendarController` (10) and `ContactController` (6), adds nothing but a
different sentence:

Bad (`app/Api/Calendars/Controllers/CalendarController.php:103-108`):

```php
try {
    $calendar = $this->calendarService->create($request->user(), $data);
} catch (\Exception $e) {
    report($e);
    return ApiResponse::error('Error creating calendar', 500);
}
```

Good (`TodoController.php:160-165`): call the service, return success.

A **typed** domain exception mapped to a non-500 status is different and
fine where it happens (`FeedController.php:350-355` `FeedAlreadySubscribedException`
→ 409, `ImportController.php:68` `ImportException`); when the same
exception is caught in a second controller, it moves into the central map
instead. `AuthController.php:111,246,331,358` and `PasskeyController.php:34,59`
(`\RuntimeException`/`ValidationException` → 401, 400 or 422) are this kind
and stay. The one odd member is `AuthController.php:63-66`: `\RuntimeException`
→ `serverError($e->getMessage())` is still a 500, only with the service's
message in the body — a custom-message catch like the Bad example, not a
status mapping (backlog, Rewrites).

Check: `grep -rn -A2 'catch (\\Exception' app/Api/*/Controllers | grep -c 'report('` → 0.

### 7.3 Pre-checks that return a status are service questions

`if ($this->calendarService->getByName(...) !== null) return error(409)`
(`CalendarController.php:99-101`, `ContactController.php:93-95`): the
"already exists" decision is the service's; it throws a typed exception
(or returns a result the controller maps once). A controller never queries
to decide.

### 7.4 Messages are sentences, ending in a period, English, no interpolation of user data

`'Todo created successfully.'`, `'No recommendation found.'`. Error messages
never echo input; `bootstrap/app.php:62-63` interpolates only the model
class name.

## 8. Caching

### 8.1 Per-user cache goes through `UserCacheService` with `[CACHE_KEY, $user->id]` keys

`app/Shared/Services/UserCacheService.php` (store `user_data`, keys joined
with `_`, failures logged with the key only). The `Cache::` facade is for
short-lived non-user data only: the Spotify client token
(`SpotifyService.php`) and WebAuthn challenges keyed by user/ip for 60 s
(`PasskeyService.php:25,128`); the bot's `ConversationState` has its own
store. Keys are `private const string CACHE_KEY = 'todos'` on the service
that owns them; TTL a `private const int`.

Check: `grep -rln 'Cache::' app/Api/*/Services` → the two files above.

### 8.2 Every write forgets every key its reads use

`TodoService::create/update/destroy/addSubtask/updateSubtask/destroySubtask`
all forget both `todos` and `todos_due` because subtasks appear in the todo
list payload. Per-calendar event caches are prefix-deleted
(`CalendarService.php:67,127,138-139` `forgetByPrefix`). Rule 4.4 governs the
shape of the forget block.

### 8.3 `remember` reads once

`UserCacheService::remember:56-71` does `has()` → `get()` → `get()` again
on hit (three round trips). Keep the method; the body should be one
`get()` with a null check. Owner's code; backlog.

## 9. Jobs, commands, schedule

### 9.1 A job carries ids, not models

`SyncFeed(string $feedId)`, `ProcessExport(string $userId, int $jobId)`,
`ScaleProfileImage(string $path)` re-read state in `handle()`. Four jobs
take `public User $user` (`Calendars/Jobs/RefreshCalendarCache.php:18-20`,
`ImportCalendars.php:17-19`, `Contacts/Jobs/RefreshContactsCache.php:20`,
`ImportContacts.php:18`) and **no job uses `SerializesModels`**, so the whole
user row is serialised into Redis and used stale. Pass `$userId` (or add
the trait — Laravel 12 queues.md documents it as the default for model
payloads; decision in Decisions).

Check: `grep -rln 'User \$user' app/Api/*/Jobs` → 0, or every such file has `SerializesModels`.

### 9.2 `$tries`/`$timeout` are set when the default is wrong, with the reason

`SyncFeeds` (`tries = 1`, `timeout = 60`) and `SyncFeed` (`tries = 2`,
`timeout = 240`) say why in the owner's commit (one poisoned feed must not
retry forever). The `Grab*Releases` jobs call three external APIs per user
and run on defaults; decide per job, write the number, not a comment.

### 9.3 Schedule in `routes/console.php`, grouped by module; commands in the module, registered in `bootstrap/app.php`

`routes/console.php` is the model (grouping comments, `Schedule::job` /
`Schedule::command`, a deactivated line keeps a reason naming the job,
`:21-24`). Commands live in `app/Api/<Module>/Commands` or `app/Bots/Commands`
and the directory must be in `bootstrap/app.php:32-40` — a new module's
first command adds a line there. No `app/Console`.

### 9.4 User-facing messages are Notifications extending `BaseNotification`

`app/Shared/Notifications/BaseNotification.php` resolves channels from the
user's per-type settings and wraps copy in `withLocale`. Copy comes from
`resources/lang/*` (`__('notifications.…')`); the bot uses the
`SolytoMessage` enum of translation keys (`app/Bots/Messages/SolytoMessage.php`).
No literal user-facing string in a notification or bot reply.

Check: `grep -rn "sendMessage(\|replyWithText('" app/Bots | grep -v trans` → 0.

## 10. Comments and docblocks

### 10.1 The default is zero comments; a comment says why, never what

27 `//` lines in all non-test `app/Api` code. The ones that exist are the
right kind: `LibraryReleases.php:33-37` (a deferred decision, names the
job), `RepairCorruptedVCards.php:101-113` (two repair strategies),
`UserCacheService.php:19-20` (why a non-positive TTL means forever),
`BaseNotification.php:50-51` (why `refresh()` after `firstOrCreate`). Tests
follow the same rule (`CalendarApiTest.php:141-151` explains a fixture's
boundary, not the assertion).

Not wanted: narration (`// fall through to strategy 2`), restating the
code, `TODO` without an issue (0 today — keep it).

### 10.2 Docblocks only for `@OA` and for a `@throws` the type system cannot say

Controllers carry the `@OA` operation; resources the `@OA\Schema`;
`IntegrationGateway` methods `@throws IntegrationAuthException`. A
`@return array<string, mixed>` on `toArray()` (20 resources, Laravel's stub
line) and `@return array<int, string>` where the generic shape is the
information (`CheckInService.php:38`, `SyncFeed.php:32`) are fine; no
`@param`/`@return` that repeats a native type, no class-level summary
blocks. (`tests/TestCase.php` is the one file with prose docblocks and
earns them.)

Check: `grep -rn '@param\|@return' app/Api app/Shared | grep -v 'array<'` → 0 today.

## 11. Formatting

### 11.1 Pint, `laravel` preset, run on every change — once the owner says so

`laravel/pint` is installed and has never been run on the tree
(`docs/jobs/dawn_standards/tooling-report.md` lists the evidence). Until
the owner decides (OQ-6), the shapes below are the hand-written target and
match the preset's defaults (preset read from `laravel/pint`
`resources/presets/laravel.php`, 2026-10-13):

| Shape | Preset rule | Today |
| --- | --- | --- |
| `! $x` | `not_operator_with_successor_space` | 54 `! $` vs 137 `!$` |
| `fn () =>` | `function_declaration` (default closure spacing) | 313 vs 48 `fn(` |
| `'key' => value` single space, no column alignment | `binary_operator_spaces: single_space` | 152 aligned lines in 30 files (`LibraryBookService.php:20-23`, `TodoService.php:236-248`, `SolytoMessage.php`) |
| `{` on the next line for functions | `braces_position` | `ApiResponse.php:69`, `ImportFeeds.php:102` |
| imports alphabetical | `ordered_imports` | `bootstrap/app.php:3-15` |
| one blank line max | `no_extra_blank_lines` | `ApiResponse.php:104-105` |

Until then: match the file you are in, and do not reformat lines you did
not change.

### 11.2 Typed constants, promoted constructors, `readonly`

`private const string CACHE_KEY`, `public function __construct(private
readonly TodoService $todoService) {}` — PHP 8.3/8.4 idioms used
consistently across the repo. No `declare(strict_types=1)` anywhere (0
files); do not start adding it file by file (Decisions).

### 11.3 Annotation blocks keep the blank `*` line before nested `@OA\JsonContent`/`@OA\Property`

The owner's shape (`TodoController.php:31-40`): blank ` *` after
`@OA\Response(` arguments and after the `allOf` line. Pint's phpdoc rules
do not touch `@OA` blocks; consistency is by hand.

## 12. Architecture conventions

The shape of the app that the other sections assume. This is the still-valid
part of the policy set written on 2026-10-10 (`docs/API-CONVENTIONS.md` and
eight `docs/CONVENTIONS-*.md`, AI-written), re-verified line by line against
the code on 2026-10-13 and folded in here; the originals are deleted. Where
this section and anything older disagree, this section wins. What was
dropped or corrected is listed under Decisions.

### 12.1 A feature is a module under `app/Api/<Module>/`

Controllers, Requests, Services, Models, Resources, Factories, Jobs,
Commands, Notifications, Enums, DTOs, Tests — each a sub-folder, only the
ones the module needs (`Tags` has six folders, `Libraries` eleven; 21
modules today). Cross-module code lives in `app/Shared/` (`Services`,
`Helpers`, `Models`, `Enums`, `Exceptions`, `Notifications`, `Providers`).
`app/Dav/` (SabreDAV server) and `app/Bots/` (Telegram) are peers, not
modules. There is no `app/Http`, `app/Models`, `app/Console`.

Request flow: route → controller → FormRequest → service → model →
resource → `ApiResponse`. Factories are co-located and wired with
`newFactory()` (`Todo.php:19-22`), carry a `forUser(User $user)` state
(39 of 47) and use `User::factory()` as the default owner
(`TodoFactory.php:28`).

### 12.2 One route file, Sanctum on everything but health and `auth/*`

`routes/api.php`: public `v1` group (`health`, seven `auth/*` routes
throttled by the four named limiters in
`app/Shared/Providers/AppServiceProvider.php:44-59`) and the
`auth:sanctum` group for everything else. `routes/web.php` has only the
Telegram webhook and the two DAV catch-alls. Token issuing lives in
`AuthService`; passkeys in `PasskeyService`; controllers read
`$request->user()` (212 uses) and never `auth()`.

### 12.3 Ownership: route binding resolves, `HandlesApiAuth` authorises

`app/Api/HandlesApiAuth.php`: `isResourceOwner($request, $resource)`,
`isAdmin`, `isSuperAdmin`. Every `show/update/destroy` and nested action
opens with `abort_unless(...)`, children are checked against their parent
(`TodoController.php:372-373`), and list queries start with `forUser`.
Shared data that is **not** per-user — `DevRequest` (community feature
requests, `isAdmin` for status changes, `DevRequestController.php:155`),
`Feed`/`FeedItem` (shared feed rows under per-user `FeedSubscription`) —
is the explicit exception; everything else has a `user_id`.

### 12.4 One envelope, one exception map

`App\Api\ApiResponse` builds every body (`success`, `message`, `data`,
`timestamp`, optional `meta` with `pagination` for paginators);
`bootstrap/app.php:41-90` renders exceptions on `api/*` into it. Sections 1
and 7 are the consequences.

### 12.5 Secrets through `DockerSecretHelper`, config through `config()`

`App\Shared\Helpers\DockerSecretHelper::get($id)` (`app/Shared/Helpers/DockerSecretHelper.php:7-20`)
returns `getenv($id)` when `APP_DEBUG=true`, otherwise the contents of the
file named by `$id_FILE`. It is called from four config files only
(`config/app.php`, `services.php`, `telegram.php`, `webpush.php`) — app
code reads `config('services.x.key')`. Nothing under `app/` calls `env()`
(0 hits); tests set a fixed app key and stub the AI key in
`tests/TestCase.php`. Values from the helper are never logged or committed.

### 12.6 Two databases; `pgsql` belongs to DAV

Default connection MariaDB for all module data; `pgsql` for the SabreDAV
tables and contact photos (`config/database.php`, DAV migration
`2025_12_08_161442_create_dav_tables.php` is driver-aware so tests can
remap it to SQLite). App migrations are additive, one per change, never
edited after deploy (`2026_05_20_000001_add_link_to_todos.php` is the
shape). Nothing outside `app/Dav` writes to `pgsql` except the two Contacts
repair commands (rule 5.5); changing the DAV schema, backends or routes is
flagged before it is done.

### 12.7 Background work: Redis queue, jobs in the module, schedule in `routes/console.php`

`config/queue.php` and `config/cache.php` default to `redis`; stores
`user_data` and `longterm` (survives deploys) are separate Redis databases.
Rules 9.1–9.4 give the shapes.

### 12.8 Cross-module calls go through `app/Shared`

A module imports another module's **models** when it needs a relation
(`Todo` ↔ `Tag`, `CalendarEventTodo`), not another module's service. The
bot and QuickAdd reach todos/calendars/libraries through
`App\Shared\Services\IntegrationGateway` (`setUser()`, `checkAuth()` on
every user-bound method, `:32-78`) and `QuickAddService`. A new external
entry point adds a gateway method.

### 12.9 Tests

Pest 4, three PHPUnit suites plus the Bots/DAV directories, in-memory
SQLite, `RefreshDatabase` applied to all module tests by `tests/Pest.php`.
What gets a test and how: `docs/TESTING.md`.

### 12.10 Localisation

User-facing copy (notifications, mails, bot replies) comes from
`resources/lang/{en,de,fr,es}` through `__()` / the `SolytoMessage` enum
(rule 9.4). API `message` strings in the envelope are English and are not
translated (Decisions).

---

## Decisions

- **Reference module is `app/Api/Todos/`.** Owner's statement (job
  `online`, 2026-10-10); when a rule and the Todos code disagree it is listed
  under Open questions, not silently resolved either way.
- **Inline `$request->validate()` is allowed** for ≤ 3 fields on a child or
  action endpoint whose shape exists nowhere else (`TodoController.php:312`);
  never on `update`.
- **No `authorize()` in FormRequests.** Ownership is `HandlesApiAuth` in the
  controller.
- **`LoginRequest::authenticate()` stays** (Breeze pattern); no other request
  gets behaviour.
- **Deletes return 200 with `data: null`**, not 204 — the frontend expects the
  envelope.
- **No `strict_types`.** 0 files today; adding it piecemeal creates two
  dialects.
- **Casts via `$casts` property** (45 files); `casts()` only where it already
  is.
- **`scopeForUser` is the tenant scope**; no global scopes, no policies, no
  repositories.
- **`SerializesModels`**: default for new jobs is ids in the constructor (as
  `ProcessExport`); the trait is the fallback when a model must be passed.
- **`@OA` operation paths are relative to the server URL `/api/v1`**
  (OQ-1, 2026-10-13): `path="/todos"` (rule 2.1).
- **422 validation errors reference `ValidationErrorResponse`** (OQ-2,
  2026-10-13): the schema stays because `errors` is always filled on a
  validation 422; `ErrorResponse` is for other errors (rule 2.4).
- **Rule 4.4 applies to the reference module** (OQ-3, 2026-10-13):
  `TodoService` has `forgetUser()` like every other service.
- **The four Libraries genre CRUDs stay as they are** (OQ-4, 2026-10-13):
  no parameterised `LibraryGenreService`; the duplication is accepted over a
  new abstraction (rule 4.7).
- **Dependabot stays composer-only until the owner adds npm**. Tooling
  (2026-10-13): Larastan is adopted (level 5, `phpstan.neon`, `app/Dav`
  and test directories excluded (2026-10-14), existing errors in
  `phpstan-baseline.neon`; run `composer analyse`, one process capped at
  500M). A Pint config, CI, npm in Dependabot and
  a coverage driver were not approved.
- **The old policy set** (`docs/API-CONVENTIONS.md` + eight
  `docs/CONVENTIONS-*.md`, AI-written 2026-10-10) is folded into section 12
  and deleted; where it and this document disagree, this document wins.
  What was dropped or corrected is listed at the end of section 12.
- This document must stay correct and may carry counts and line numbers as
  long as they will not go stale within weeks; no per-class inventories.
- **Envelope `message` strings stay English** and untranslated; they are
  for developers and logs, the frontend renders its own copy.
- **From the old policy set, dropped or corrected on 2026-10-13:**
  - "`HasUuids` + `HasFactory` are the two traits on app models" — wrong
    for 30 of 52 models; UUID vs auto-increment follows the migration (5.4).
  - "All feature data is per-user" — `DevRequest` and `Feed`/`FeedItem`
    are shared by design (12.3).
  - "Jobs: constructor only carries identifiers … `RefreshCalendarCache`
    takes the `User`" — the example contradicted the rule; 9.1 is the rule
    and the four `User`-carrying jobs are backlog.
  - "`@OA` `path` is `/v1/<route>`" — stated as if settled; 186 of 268
    operations use `/api/`, and neither matches `@OA\Server`. Was OQ-1; decided: relative to `/api/v1` (rule 2.1).
  - "Pint is the style enforcer; run `./vendor/bin/pint --dirty`" — Pint has
    never been run on this tree (`tooling-report.md`); now OQ-6 and 11.1.
  - "Repo-wide suites live in `tests/Unit` and `tests/Feature`" and
    "`tests/Feature` tests declare `RefreshDatabase` per file" —
    `tests/Feature/` contains only `Pest.php`; corrected in `TESTING.md`.
  - "The `cache->forget` repetition in `create()`/`update()` is the accepted
    pattern because the two paths differ in the details" — the lines are
    byte-identical; rule 4.4 says extract (OQ-3: the reference module too).
  - "`IntegrationGateway` guards every call with `checkAuth()`" —
    `detect()` is deliberately unauthenticated (content detection needs no
    user); 12.8 says "every user-bound method".
  - "`whenLoaded` … never `$this->category` without it" — kept as 6.1, with
    the three violations named instead of asserted away.
  - "Validation is the first line of defense … existence via `exists:`" —
    `exists:` is not an ownership check; replaced by 3.2 and the backlog's
    first item.
  - The ten-rule "golden rules" list, the checklists at the end of each old
    doc and the module anatomy tree are not carried over; sections 1–12
    cover them with file references.

## Open questions

- **OQ-6 — Pint.** Commit `pint.json` (preset `laravel`) and run it once
  over the tree (large diff, ends the `!$`/`fn()`/alignment mix), or remove
  `laravel/pint` from `require-dev`? The frontend removed its formatter
  because it fought hand formatting; here the hand formatting mostly already
  matches the preset.
- **OQ-7 — `ShareCalendarRequest` `friend_id`** accepts any user id; is
  sharing meant to be restricted to friends (`Friend` model exists) or is the
  name just historical?
- **OQ-8 — `StoreDevRequestRequest` `created_by_user_id`** is client-chosen;
  intended (admins filing on behalf of users) or an oversight?

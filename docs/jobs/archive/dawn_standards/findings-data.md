# Findings — requests, resources, models (TASK-5)

106 FormRequests, 68 resources, 52 models, 47 factories. Counts 2026-10-13.

## 1. Tenant isolation: bare `exists:` on foreign ids — CONFIRMED

`exists:table,id` only proves the row exists; it does not prove the caller
owns it. 40 such rules in 26 files
(`grep -rn 'exists:' app/Api --include=*.php | grep -v Tests`). Traced end to
end:

| Rule | Consumer | Check? | Effect |
| --- | --- | --- | --- |
| `StoreTodoRequest:20` `category_id` → `exists:todo_categories,id`; `UpdateTodoRequest:21` | `TodoService::create:159-162` → `Todo::create($data)`; `update:196` | **none** | `todo_categories.id` is auto-increment (`2025_08_12_121243_create_todo_categories.php`), so ids are guessable. User A posts `category_id` of user B's category; `create` then `load(['category'])` and `TodoResource:45` serialises it → **B's category title is returned to A**, and A's todo is linked into B's category (B's workspace views that filter by category id would include it). |
| `StoreTodoRequest:22` / `UpdateTodoRequest:23` `tags.*` → `exists:tags,id` | `TodoService::create:165` `attach`, `update:203` `sync` | none | same leak for `tags.name` (integer ids, `2025_08_12_111906_create_tags.php`) |
| `StoreTodoWorkspaceRequest:14`, `TodoWorkspaceController:273,335` `categories.*` | `TodoWorkspaceService::create:39`, `attachCategories:71` | none | attach B's categories to A's workspace |
| `StoreNoteRequest:14,16`, `UpdateNoteRequest:14,16` `category_id`, `tags.*` | `NoteService::create:75-84`, `update:92-100` | none | same as todos (`note_categories.id` integer) |
| `StoreNoteCategoryRequest:13` `parent_id` → `exists:note_categories,id` | `NoteCategoryService::create:38-47` | none | nest A's folder under B's folder; `getAncestors()` (`NoteCategory:97`) then walks into B's titles |
| `Store/UpdateLibrary{Book,Movie,Game,Music}Request` `genres.*`, `tags.*`; `UpdateLibraryLinkRequest:18` `category_id`; `Store/UpdateLibraryQuoteRequest` `tags.*` | `LibraryBookService::create:61-67` and siblings | none | same (genre/category ids integer) |
| `StoreTimeTrackingEntryRequest:17` `project_id`; inline `TimeTrackingEntryController:164,267` | controller `:167-170`, `:271-273` `TimeTrackingProject::find` + `isResourceOwner` | **yes** (owner's code) | the correct pattern, in the owner's hand |
| `Store/UpdateTimeTrackingProjectRequest:16` `category_ids.*` | `TimeTrackingService::createProject:203-210` | none | attach B's categories to A's project |
| `TimeTrackingEntryController:393` `category_id` (statistics filter) | `getStatistics` filters A's own entries by that category | harmless | read-only filter over own rows |
| `EventAttachmentController:136,232` `todo_id`/`note_id` (`required|uuid`, no `exists`) | `:138` `Todo::forUser($request->user()->id)->find(...)` | **yes** | the other correct pattern (scope then find) |
| `ShareCalendarRequest:14` `friend_id` → `exists:users,id` | `CalendarController:809` `User::find` | by design | shares with any user id, not only friends; "friend" in the name is aspirational |
| `StoreFriendRequestRequest:12` `receiver_id` | friend request | by design | |
| `StoreDevRequestRequest:19` `created_by_user_id` → `exists:users,id` | `DevRequestService::create:21-27` `DevRequest::create($data)` | none | caller chooses who the request is attributed to; `createComment:93-97` then notifies that user. Should be `$request->user()->id` (or null for anonymous), never client input |

Severity: medium. Cross-tenant **read** of category/tag/genre names and
cross-tenant **write** of relation rows; no access to another user's
todos/notes themselves. The fix shape already exists in the repo:
`Rule::exists('todo_categories', 'id')->where('user_id', $this->user()->id)`
(Laravel 12 validation docs, "Rule::exists … ->where") in the FormRequest,
or `TodoCategory::forUser($user->id)->findOrFail($id)` in the service as
`EventAttachmentController:138` does. No test covers the case
(`findings-tests.md`).

## 2. FormRequests

- `authorize()`: 0 of 106. Fine — ownership is in controllers (`HandlesApiAuth`),
  the FormRequest base returns `true`.
- Rule style: pipe strings in 124 files (515 lines); arrays in 6 Users
  requests (`RegisterRequest:15` `['required', 'confirmed', Password::defaults()]`,
  `ResetPasswordRequest`, `ChangePasswordRequest`, `StoreUserRequest`,
  `UpdateUserRequest`, `CommitRequest`) — all because a `Rule`/`Password`
  object is in the list. That is the only legitimate reason.
- `attributes()`: 5 files; `messages()`: 0. `StoreTodoRequest:29-34` is the
  example.
- Logic inside a request: `LoginRequest::authenticate():21-38` (`Auth::attempt`
  + verification check, Breeze pattern), `UpdateUserRequest:13`
  (`$this->route('user')->id` for a unique rule). Both owner's; acceptable.
- Request body annotation vs rules: `TodoController:131-143` lists 9
  properties, `StoreTodoRequest` has 14 rules (`link`, `recurrence_frequency`,
  `recurrence_interval`, `recurrence_ends_at` missing); same for `update`
  `:187-199`. Owner's file.

## 3. Resources

- 68 resources, 64 with `@OA\Schema`; missing: `BookSearchResultResource`,
  `GameSearchResultResource`, `MovieSearchResultResource`,
  `MusicSearchResultResource` (June 2026, owner).
- `whenLoaded`: 23 uses / 14 files. Relations read without it:
  `DevRequestCommentResource:36` `$this->user->name`,
  `FeedSubscriptionResource:34` `$this->feed->url`,
  `DevRequestResource:64` `$this->comments->count()` (a query per item in a
  list). `LibraryMovieResource:40` `category` is a column, not a relation
  (false positive).
- Schema ↔ `toArray()` drift (script over `property="x"` vs top-level keys):
  `TodoResource` — 6 keys in `toArray` absent from the schema (`link`,
  `recurrence_frequency`, `recurrence_interval`, `recurrence_ends_at`,
  `parent_task_id`, `auto_generated`); `NotificationSettingsResource` — 4
  `export_ready_*` keys; `UserResource` `confirmed`; `UserSettingsResource`
  `temperature_unit`; `DailyWeatherForecastResource` — schema lists 13
  properties the resource never emits (nested shape). Owner's files; the
  rule "change rules/annotation/resource in the same commit" is not kept
  even by its author.
- `TodoResource:45-50`: closing parens of `whenLoaded(…, fn () => …\n)` on
  their own line — the only formatting oddity in the reference module.
- Path parameter types: `TodoController:93,181,240` declare `{todo}` as
  `type="integer"`; `todos.id` is a UUID (`Todo` uses `HasUuids`,
  migration `$table->uuid('id')`).

## 4. Models

- `$fillable` on 52/52 (`$guarded`: 0). Good.
- `HasUuids` on 22 models; the other 30 are integer-keyed (categories,
  genres, tags, subtasks, settings, pivots). Matches the migrations; the
  mix is by design (`todos` uuid, `todo_categories` id).
- Casts: `protected $casts` in 45 files, `casts()` method in 2
  (`User`, `Passkey`). Laravel 11+ documents `casts()`; both work. Pick one
  (decision).
- Relation return types: declared everywhere except `User.php:101-126`
  (`friends()`, `sentFriendRequests()`, `receivedFriendRequests()`,
  `allFriendRequests()` — the first builds a query, not a relation, so a
  return type would have made that visible).
- `scopeForUser($query, $userId)` untyped in 35 models, `string $userId` in
  2 (`Notes` journal job). Laravel docs type it `Builder $query`.
- Business logic in models: `User::isAdmin/isSuperAdmin` (role checks),
  `NoteCategory::getAncestors()` (`:97`, tree walk — service material),
  otherwise relations/scopes only. Good.
- Factories: `forUser(User $user)` state on 39 of 47; the 8 without are
  user-less or differently-keyed models (`Feed`, `FeedItem`, `User`,
  `TodoSubtask`, `WealthValue`, `DevRequest`, `DevRequestVote`,
  `DevRequestComment`).

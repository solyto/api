# Implementation: Standards: extract to helpers

id: northern
developer: @developer
date: 2026-10-13

## Summary

Implemented every task that does not need an owner decision: cache-forget helpers
for TimeTracking and Notes, a shared `CsvHelper::write()` for all ten CSV exporters, the
`match`-based library recommender, removal of the dead `findGenre()` methods,
eager-loading fixes in three resources, and OpenAPI schema/annotation alignment.
TASK-1 and TASK-4 were finished after the owner decided: OQ-3 is closed (rule 4.4
applies to the reference module), and Calendar/Contact are skipped.

**Tests: green.** After the owner approved it, `composer install` was run. `php artisan test` reports all
717 tests passing (1922 assertions). The worktree needed the gitignored runtime dirs
`storage/framework/*` and `storage/app/public/user` to exist. Every test shows the same
warning, which comes from the missing `.env` in this worktree and is environmental only.
The OpenAPI spec generates cleanly with swagger-php directly (175 paths, all new or changed
schemas present, no warnings). `php artisan l5-swagger:generate` fails, but not because of
this branch (see follow-ups).

## Changes

TASK-1: The owner closed OQ-3: rule 4.4 applies to the reference module. `TodoService` now has `private forgetUser(string $userId)` (CACHE_KEY + CACHE_KEY_DUE), and it replaces all 7 pairs. `docs/STANDARDS.md`: OQ-3 was moved from Open questions to Decisions, and the rule 4.4 text no longer describes the old copies.
TASK-2: `TimeTrackingService` now has `private forgetUser(string $userId)` (ENTRIES + PROJECTS). It replaces all 8 pairs. The three single CATEGORIES forgets are unchanged.
TASK-3: `NoteService` now has `private forgetUser(string $userId)` (CACHE_KEY + NEWEST). It replaces the 3 pairs. `forgetNewest()` is unchanged.
TASK-4: Owner decision: option (a), skip. No code changes. The backlog item in `docs/STANDARDS_BACKLOG.md` is checked off, the wrong Calendar/Contact counts are removed, and the skip reason is recorded (the keys carry a calendar/address-book id, and Calendar clears the share recipient's cache).
TASK-5: New helper `App\Shared\Helpers\CsvHelper::write(string $path, array $header, iterable $rows)`, a static method like `DockerSecretHelper` and `UrlHelper`. `TodoExportService` uses it. The first version was a `WritesCsv` trait, and the owner asked for a helper instead, so the trait is removed.
TASK-6: Book, Music, Game, Recipe, Quote, CheckIn, TimeTracking, FinanceIncome and FinanceWealth exporters now use `CsvHelper::write()` and pass mapped collections. FinanceWealth uses `flatMap`. The unused `use` lines were left alone. Added an `ExportTest` case for `finances_wealth.csv` that checks the header, a row, and the row count. The non-CSV exporters were not touched.
TASK-7: `LibraryBookService::recommend` and `LibraryMusicService::recommend` now use `match` over all four enum cases. `NEW` goes to `recommendNew()`, which is now private, takes the recommender, and calls `$recommender->new()` directly, so there is no recursion. Both controllers' `recommend()` now make one call with one 404 check. Added API tests for `recommend/random` (404 when the library is empty, 200 with an item) for books and music. `grep call_user_func app` → 0.
TASK-8: Deleted the four unused `findGenre()` methods (Book, Movie, Game, Music services).
TASK-9: `DevRequestService` now uses `with('votes')->withCount('comments')` in `list()` and `load('votes')->loadCount('comments')` in create/update/vote. `DevRequestResource` emits `comment_count` from `comments_count`, so the JSON key is unchanged. Added assertions on `data.0.comment_count` (list) and `data.comment_count` (vote).
TASK-10: `DevRequestCommentResource` `user` now goes through `whenLoaded('user', fn ($user) => [...])`. Both producers already load `user`.
TASK-11: `FeedSubscriptionResource` `url` now goes through `whenLoaded('feed', ...)`. `FeedService::createSubscription` and `updateSubscription` now `load('feed')`. For `showSubscription` I added `FeedService::findSubscription()`, which loads `feed`, because that follows the existing `XService::find(Model)` pattern (for example `TodoService::find`) and rule 6.1 ("the service decides"). The controller calls it. Added `url` assertions for list, show, update and store in `FeedApiTest`.
TASK-12: Added `@OA\Schema` `BookSearchResult`, `GameSearchResult`, `MovieSearchResult` and `MusicSearchResult`, typed from the DTO getters. The music `id` is `int|string`, so it is documented as `oneOf`. All four `search` operations used an inline `@OA\Items(type="object")`, so I pointed them at the new schemas. `grep -rL '@OA\\Schema' app/Api/*/Resources` → empty.
TASK-13: `Todo` schema: added `link`, the 3 `recurrence_*` fields, `parent_task_id` (uuid) and `auto_generated`. `NotificationSettings`: added the 4 `export_ready_*` keys. `User`: added `confirmed`. `UserSettings`: added `temperature_unit`. `DailyWeatherForecast`: the schema was already nested like `toArray()`. The findings script was naive and counted the nested properties as top-level, so the only real drift was a missing top-level `temperature_unit`, which I added. No rewrite was needed.
TASK-14: `DevRequestComment` and `FriendRequest`: changed `user.id` from integer to string/uuid. `LibraryRecipe`: changed `id` to string/uuid (`HasUuids`), changed `time_to_make` from string to integer (integer column), and removed `nullable` from `ingredients`/`steps` (`toArray` uses `?? []`). `RecipeReleaseImport`: no drift found against `RecipeReleaseDTO`. Gap: the brief says "ten resources" but names nine. I did not look for a tenth.
TASK-15: Both `TodoController` request bodies (store and update) now document `link`, `recurrence_frequency`, `recurrence_interval` and `recurrence_ends_at`, with values from the FormRequests. Review fix: the seven added `@OA\Property` lines were re-indented to the method-docblock indent (`     *`), so `pint --test` passes on the file again.

## Known issues / follow-ups

- `php artisan l5-swagger:generate` finds no paths ("Required @OA\PathItem() not found") on every branch. With `'analyser' => null` (`config/l5-swagger.php:129`), l5-swagger 10.1 uses an attribute-only analyser (`vendor/darkaonline/l5-swagger/src/Generator.php:253-255`), but this codebase uses docblock annotations. `tests/Unit/OpenApiSpecTest.php` calls swagger-php directly, so it does not catch this. The fix is a config change and is out of scope here.
- The brief says ten drifting resources but names nine (TASK-14). The owner doesn't know which one is missing and is fine with nine.
- `DevRequestController::vote` still runs `$devRequest->load(['votes', 'comments'])` before the service call. That is now redundant, because the service reloads votes and the comment count afterwards. I left it unchanged as out of scope.
- `Todo` schema still documents `id` as `integer`. `todos.id` is a UUID. This was not in the task list.
- `LibraryRecommender::favorite()` / `unrated()` read `->id` on a possibly-null model. On an empty library, `recommend/favorite` and `recommend/unrated` probably fail with a 500 instead of returning 404. This predates this job (the tests cover `random` only).
- The `recommend` operation's path-parameter enum lists only `random, favorite, new`. It is missing `unrated`.
- `.env.testing` shows up as modified in `git status` because in this sandbox it is mounted as a character device. I did not stage or touch it.

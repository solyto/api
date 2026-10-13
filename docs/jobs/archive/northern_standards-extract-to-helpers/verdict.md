# Verdict: Standards: extract to helpers

id: northern
reviewer: @reviewer
date: 2026-10-13

## Review

Reviewed `git diff main...HEAD` on `feature/northern_standards-extract-to-helpers`.
`php artisan test`: 717 tests, 1922 assertions, all green (the per-test warnings
come from the missing `.env` in the worktree). Verification greps from tasks.md:
`call_user_func` → 0, `findGenre` → 0, `grep -rL '@OA\\Schema' app/Api/*/Resources`
→ empty, `\$this->(user|feed|comments)->` in Resources → 0. I ran Pint `--test`
on every changed PHP file against its `main` version. Each failure was already
there on main except the one in TodoController (TASK-15 below).

TASK-1: PASS
notes: The owner closed OQ-3. All 7 pairs now call `forgetUser()`, and it forgets the same two keys.

TASK-2: PASS
notes: All 8 pairs replaced. `destroyProject` already forgot both keys on main, so behaviour is unchanged. The CATEGORIES forgets are untouched.

TASK-3: PASS
notes: 3 pairs replaced. `forgetNewest()` is untouched.

TASK-4: PASS
notes: Owner chose option (a), skip. The only changes are to `STANDARDS_BACKLOG.md` (item checked off, reason recorded) and to `STANDARDS.md` (OQ-3 moved from Open questions to Decisions, rule 4.4 text updated). Both are owner-sanctioned.

TASK-5: PASS
notes: At the owner's request the helper is `App\Shared\Helpers\CsvHelper::write()`, a static helper like `DockerSecretHelper`. Same fopen/header/rows/fclose behaviour as before. `ExportTest` still covers the todos CSV.

TASK-6: PASS
notes: All nine exporters converted. The row closures moved unchanged, and FinanceWealth uses `flatMap`, which keeps the per-field ordering. The non-CSV exporters are untouched. The new `finances_wealth.csv` test checks the header, a row, and the row count.

TASK-7: PASS
notes: `match` covers all four cases. `recommendNew()` is now private and calls `$recommender->new()` directly, so there is no recursion. Its result is an array or null, which fits `recommend(): ?array`. Both controllers make one call with one 404 check. Random/404 tests added for books and music.

TASK-8: PASS
notes: Four dead methods deleted. No callers.

TASK-9: PASS
notes: `withCount`/`loadCount` is used on every service path that feeds `DevRequestResource` (list, create, update, vote). The JSON key stays `comment_count`. Both new assertions cover it.

TASK-10: PASS
notes: `whenLoaded('user', fn ($user) => …)`. Both producers load `user`.

TASK-11: PASS
notes: All four producers load `feed`: list (already did), create, update, and show via the new `FeedService::findSubscription()`. That covers the "service decides" open point. `url` is asserted on list, show, update and store.

TASK-12: PASS
notes: Four schemas added. The four `search` operations now reference them. That optional part of the task explains the small `LibraryGameController`/`LibraryMovieController` docblock edits, so it is in scope.

TASK-13: PASS
notes: Annotations only. The DailyWeatherForecast schema was already nested, and the explanation for adding only `temperature_unit` holds up.

TASK-14: PASS
notes: Fixed the user-id uuid typing in DevRequestComment and FriendRequest, and the id, `time_to_make` and nullability in LibraryRecipe. RecipeRelease has no drift. The missing tenth resource is reported, not guessed.

TASK-15: PASS
notes: The content is correct: all four fields are on both request bodies, with values matching the FormRequests. Re-review: commit 0c8c9de re-indents the seven added `@OA\Property` lines to the method-docblock indent (`     *`). That change is whitespace only. `vendor/bin/pint --test app/Api/Todos/Controllers/TodoController.php` now passes.

## Re-review (after d413dee)

`git diff d413dee..HEAD -- app` touches only the TodoController indentation fix. I also re-checked the main code paths in the full `main...HEAD` diff: `CsvHelper`, the FinanceWealth `flatMap`, the `match`/`recommendNew` recursion fix, `withCount`/`loadCount`, and the feed `load('feed')` producers. No new findings. `php artisan test`: 717 tests, 1922 assertions, all green (warnings only, from the missing `.env`). `call_user_func` → 0, `findGenre` → 0, and `grep -rL '@OA\\Schema' app/Api/*/Resources` → empty.

## Security

Not run.

## Overall

APPROVED

There are no blockers. The earlier blocker, the TodoController docblock indent, is fixed.

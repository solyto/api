# Implementation: Client contract tests for frontend 1.0.15

id: minor
developer: @developer
date: 2026-10-14

## Summary

These are the first client contract tests for the 1.0.15 frontend (the first F-Droid release). They cover two things:

- **The route fixture.** It lists every API call 1.0.15 makes, taken from `solyto/app` at tag `1.0.15` (`22c5a68`).
- **The tests.** One route test checks that each call in the fixture still hits the route it should. Then there are field tests for the endpoints listed in brief step 3.

Every route and field test passes against the current API, so no break was found. The only repo files changed are `tests/Unit/ClientContractTest.php` and the new `tests/Fixtures/client-contract/1.0.15-routes.json`.

The frontend source was read from `/workspace/tmp/solyto-app-1.0.15/`, which is git-ignored. Nothing from it is committed, and nothing in it was run.

Checks: `php artisan test tests/Unit/ClientContractTest.php` passes (26 tests). `composer test` and `composer analyse` both exit 0.

## Changes

TASK-1: Added `tests/Fixtures/client-contract/1.0.15-routes.json`, with 241 unique `{method, uri}` pairs under `routes` and 18 never-called `apiRoutes.ts` entries under `unused`.
- **Where the methods come from:** every `apiRoutes.*` reference in `src/` (state and components), using the `ApiService` method each one is passed to. For example, `list`/`get`/`image` count as GET, `create`/`post`/`postRaw`/`postFormData`/`uploadFile` as POST, `update`/`put` as PUT, and `delete` as DELETE. Raw `fetch` calls use their `method:` value.
- **Direct `fetch` calls:** `GET api/v1/health` (`src/routes/setup/+page.svelte:34`) and `GET export/{id}/download`.
- **Dynamic URLs:** library `search`/`import` calls append `/{provider}/{query}` or `/{provider}`.
- **URI form:** URIs are written exactly as the router reports them, with the `api/v1/` prefix and the parameter names from `routes/api.php` (open point 3 in the tasks). Each pair was matched against `php artisan route:list --path=api/v1`, and none were left unmatched.
- **`unused` entries:** stored as `{key, path}`. They include `notes.export`, `notes.get`, `notes.newest`, `todos.get`, `todos.listDueDate`, `todos.changeWorkspaceDefaultStatus`, the library `*.get` entries, `libraries.getCover` and `calendars.listInvites`.

TASK-2: Added `it('still registers every route 1.0.15 calls')`. It loads the fixture and asserts it isn't empty. For each pair, it replaces every `{param}` with `1` and builds a `Request`.
- It runs `Route::getRoutes()->match()` without dispatching anything. If no route matches, the test fails with the offending pair.
- It also asserts that the matched route's `uri()` equals the fixture URI, so a call answered by the wrong route fails too.
- `1` doesn't collide with any static segment, and no route has a `where` constraint.

TASK-3: Field tests for `POST auth/login`, `POST auth/refresh` and `GET users/me`.
- **login and refresh:** `token`, `token_type`, `token_expires_at`, and `user.{id,name,email,role}`. 1.0.15 loads `users/me` straight after either call.
- **users/me:** `id`, `name`, `email`, `role`, `created_at`, `profile.profile_image_path`, and the settings keys 1.0.15 reads: `language`, `timezone`, `date_format`, `time_format`, `navigation`, `openai_api_key`, `first_visit`, `temperature_unit`.
- **Left out on purpose:** `settings.widgets`, `settings.ai_enabled` and `additional_info_last_updated_at` are in `types/auth.ts`, but no 1.0.15 code reads them.

TASK-4: Field tests for `GET notes` (note fields including `tags[]`) and `GET notes/categories`. The categories test checks the category fields, including nested `children` with the same fields.

TASK-5: Field tests for `GET todos`, `GET todos/categories` and `GET todos/workspaces`.
- **todos:** all fields, including `category`, `tags[]` and `subtasks[]`. `relevance` is left out because 1.0.15 computes it client-side.
- **workspaces:** includes `categories[]`.

TASK-6: Field tests for `GET calendars`, `GET calendars/events/{yearMonth}`, `GET address-books` and `GET address-books/contacts`. Data is created through the API, the same way `CalendarApiTest` and `ContactApiTest` do it. Events use a fixed month (2026-03), so the test doesn't depend on today's date. Two keys are left out:
- **Event `created_at`/`updated_at`:** never read by 1.0.15.
- **Contact `title`:** see Known issues.

TASK-7: Field tests for `GET time-tracking/projects`, `GET time-tracking/entries` and `GET time-tracking/entries/statistics?from=…&to=…`. The query uses the same `YYYY-MM-DD` format 1.0.15 sends.
- **statistics:** pins top-level `total_minutes` and `by_project[].{project_id, project_title, color, total_minutes}`. The project's color is null, so the test also proves the `color` key is present when its value is null.
- **projects:** `total_duration` is left out because 1.0.15 never reads it.

TASK-8: Field tests for the book, music, movie and game list endpoints. They cover every key in the 1.0.15 types, plus `genres[].{id,title}` and `tags[].{id,name,color}` where the type has tags. Genre timestamps are left out: they're in the types, but 1.0.15 never reads them, and the genre resources don't return them.

TASK-9: Two field tests.
- **`GET notifications/settings`:** all 36 `{type}_{channel}` keys.
- **`GET users/me/settings/check-in`:** `enabled_trackers` and `selected_sports`, which 1.0.15 reads in `CheckInData.svelte.ts`.

"User settings" (open point 2) turned out to be two sources. The settings on `users/me` are covered by the TASK-3 test, so they aren't tested twice. The check-in settings endpoint is the only other settings GET that 1.0.15 calls.

TASK-10: Verification only. I ran `php artisan test tests/Unit/ClientContractTest.php`, then `composer test` (exit 0) and `composer analyse` (exit 0, "No errors"). `git status` shows nothing staged from `tmp/`.

## Known issues / follow-ups

- **Contact `title` is read but never returned.** `src/lib/components/contacts/ContactEdit.svelte:71` reads `c.title` (and `types/contact.ts:32` declares it), but `ContactResource` has never had a `title` key (`git log -p` back to the initial commit). This is an old gap, not a regression since 1.0.15, so the contact test doesn't pin it. Whether to add the field is the owner's call.
- **Event `calendar_id` is a string.** On `calendars/events/{yearMonth}` it comes back as a string (`EventDTO::$calendarId` is `?string`), while `calendars[].id` is an int, and `types/calendar.ts` says `number` for both. 1.0.15 only calls `.toString()` on it, so nothing breaks. The contract tests check keys only, not types.
- **Movie genres use the wrong resource.** `LibraryMovieResource` renders `genres` with `LibraryMovieResource::collection(...)` rather than `LibraryMovieGenreResource`. The keys 1.0.15 reads (`id`, `title`) are present, so the test passes, but it looks like a bug.
- **Environment notes from this run:**
  - `vendor/` was missing, so I ran `composer install` to sync with the lock file. No new packages were added.
  - The git-ignored `storage/framework/*` directories didn't exist, so Laravel couldn't boot. I created them.
  - There's no `.env`, so every test reports a phpdotenv "file_get_contents(.env)" warning. Warnings don't affect the exit code.
  - The first `composer test` run had 2 failures in `ExportTest`: `fopen` on `storage/app/public/user/…` failed because that directory didn't exist yet in this fresh worktree. The next two full runs passed, so this looks like a first-run environment issue that has nothing to do with this job.
  - `.env.testing` shows as modified in `git status`, but in this sandbox it's a masked character device. It can't be hashed or committed, and it must not be, so I left it alone.

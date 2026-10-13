# Report: Deployment readiness of `main` since 1.0.15 (API without frontend)

date: 2026-10-13
scope: All commits in `6bdedcd` (tag 1.0.15) .. `1bee6fa` (tip of `main` when job `hide` was created). Question: can the API be deployed without a matching frontend release, and how does the user-deletion commit `1bee6fa` reach production while the unfinished features stay unpublished? Written for the maintainer deciding on the next deploy.
read: true

## Summary

**No endpoint was removed or renamed, and no request validation was tightened between 1.0.15 and `main`.** Every route, request field and response field from 1.0.15 still exists, and all four migrations are additive. Deploying `main`'s API without the frontend would not break a 1.0.15 frontend at the contract level.

**But the unfinished features are not fully "dark".** Two changes would be visible to users of the old frontend:

1. A data backfill creates a `.journal` note category for **every** user. The old frontend will list it under note categories, and editing or deleting it returns 403.
2. Time-tracking statistics now take their `color` from the new project `color` column, which is `NULL` for all existing projects. Statistics colours in the old UI go blank.

**`1bee6fa` (full user deletion) has no dependency on any other commit since 1.0.15.** Every file it touches is byte-identical at `6bdedcd` and at its parent, so a cherry-pick onto 1.0.15 applies textually clean. It adds an artisan command only (no endpoint), so it needs no frontend change. It does write to the DAV `pgsql` database (flagged below).

**Recommendation:** ship user deletion as a hotfix release, 1.0.15 + cherry-pick of `1bee6fa` (option B). Deploy `main` later, together with the frontend.

## Findings

### F1 — Commit inventory

Source: `git log --oneline 6bdedcd..main` (full) and `git log --first-parent`. The two merges `fa86708` (parents `4648dd9`, `588773d`) and `d72587a` (parents `acb653b`, `c48fa49`) bring in `588773d`, `780d58d` and `c48fa49`. `git show --cc` shows no conflict resolutions in either merge.

| Commit | Date | Subject | Feature group | Runtime code? |
|---|---|---|---|---|
| `e0f4e1f` | 10-08 | deactivate movie release notifications | Movie release notifications | yes, `routes/console.php` |
| `588773d` | 10-10 | time tracking improvements | Time-tracking colours / stats filter | yes, plus 1 migration |
| `4648dd9` | 10-10 | notebook: journal folders | Journal folders / categories | yes, plus 2 migrations |
| `fa86708` | 10-10 | Merge (brings `588773d`) | — | no own changes |
| `acb653b` | 10-10 | php/laravel/api best pratices | Standards (docs) | **no**: README + `docs/` only |
| `780d58d` | 10-10 | Journal & calendar-related settings | Calendar marks / journal settings | yes, plus 1 migration |
| `c48fa49` | 10-10 | More efficient calendar marks | Calendar marks | yes |
| `d72587a` | 10-10 | Merge (brings `780d58d`, `c48fa49`) | — | no own changes |
| `0ba7665` | 10-13 | STANDARDS | Standards (docs) | **no**: README + `docs/` only |
| `1bee6fa` | 10-13 | full user deletion routine | **User deletion (must go live)** | yes, incl. `app/Dav/*` |

These are unchanged across the whole range (`git diff --name-status 6bdedcd 1bee6fa`): `bootstrap/app.php` (error envelope, command registration), `config/`, `composer.json`/`composer.lock`, `app/OpenApi/`, `app/Shared/`.

### F2 — Migrations (`git diff --name-status 6bdedcd 1bee6fa -- database/`)

| Migration | Commit | Change | Destructive? | Effect on a 1.0.15 frontend |
|---|---|---|---|---|
| `2026_10_10_000000_add_system_and_hidden_to_note_categories` | `4648dd9` | `note_categories.is_system`, `is_hidden`: boolean, default `false` | no; `down()` drops the columns | none by itself |
| `2026_10_10_000001_add_color_to_time_tracking_projects` | `588773d` | `time_tracking_projects.color`: string, nullable, no backfill | no | indirect, see F3 #2 |
| `2026_10_10_000001_backfill_journal_categories` | `4648dd9` | **Data backfill:** `NoteCategory::provisionJournal()` for every user (chunks of 500). Creates a root category titled `.journal` with `is_system = true`, `is_hidden = true` | not reversible (`down()` is a no-op, by design) | **visible**, see F3 #1 |
| `2026_10_10_000002_add_journal_display_settings_to_user_settings` | `780d58d` | `user_settings.journal_enabled`, `journal_calendar_mark`, `check_in_calendar_mark`: boolean, default `false` | no | none (extra response fields only) |

Notes:
- Two files share the `000001` prefix. Laravel orders them by filename (`add_color…` before `backfill…`). The backfill depends only on `000000`, so the order is correct.
- `UserObserver::created` (`4648dd9`) also provisions `.journal` for every **new** user from now on.
- `firstOrCreate` matches on `(user_id, title='.journal', parent_id=null)`. If a user already has a root category named `.journal`, it is reused and keeps `is_system = false`. This is an edge case.
- The backfill writes through the model, not through `NoteCategoryService`, so the per-user `note_categories` cache (TTL 86400 s) is not cleared. The new folder appears for each user within at most 24 h, or sooner after their next category change.

### F3 — API contract diff (1.0.15 → `main`)

Sources: `git diff 6bdedcd 1bee6fa -- routes/api.php app/Api/*/{Controllers,Requests,Resources,Services}`.

**Removed or renamed endpoints:** none. **Newly required fields on existing endpoints:** none. **Tightened validation on existing endpoints:** none. **Removed, renamed or re-typed response fields:** none.

New endpoints (additive; a 1.0.15 frontend never calls them):

| Endpoint | Commit | Feature |
|---|---|---|
| `PATCH /v1/notes/categories/{category}/hidden` | `4648dd9` | Journal folders |
| `GET /v1/notes/journal/marks?from&to` | `c48fa49` | Calendar marks |
| `GET /v1/check-in/marks?from&to` | `c48fa49` | Calendar marks |
| `PUT /v1/users/me/settings/journal` | `780d58d` | Journal settings |
| `PUT /v1/users/me/settings/journal-calendar-mark` | `780d58d` | Calendar marks |
| `PUT /v1/users/me/settings/check-in-calendar-mark` | `780d58d` | Calendar marks |

Additive changes to existing endpoints:
- `NoteCategoryResource`: new `is_system`, `is_hidden` fields (`4648dd9`).
- `UserSettingsResource`: new `journal_enabled`, `journal_calendar_mark`, `check_in_calendar_mark` fields (`780d58d`).
- `TimeTrackingProjectResource`: new `color` field. Store/update requests accept an optional `color` (`nullable|string|max:7`) (`588773d`).
- `PUT /v1/time-tracking/entries/{entry}` now accepts optional `has_exact_times`. `GET …/statistics` accepts optional `category_id` (`588773d`).
- OpenAPI annotations: descriptions only on existing operations.

**Behaviour changes on existing endpoints. These are visible to a 1.0.15 frontend:**

| # | Endpoint | Change | Impact on the 1.0.15 frontend |
|---|---|---|---|
| 1 | `GET /v1/notes/categories` | Lists the backfilled `.journal` root category for every user (`NoteCategoryService::listRoots` does not filter `is_hidden`/`is_system`) | An unexplained `.journal` folder shows up in every user's notebook. Users can open it and file notes into it. |
| 1a | `PUT`/`DELETE /v1/notes/categories/{id}` | Return **403** for `is_system` categories (`NoteCategoryController::update/destroy`) | Renaming or deleting `.journal` from the old UI fails. How the old UI shows this 403 is unknown. |
| 1b | `GET /v1/notes/newest` | Excludes notes in hidden categories (`NoteService::newest`) | Notes a user files into `.journal` from the old UI no longer appear in "newest". |
| 2 | `GET /v1/time-tracking/statistics` | `by_project[].color` now comes from `project.color` instead of the project's first category's colour (`TimeTrackingService::getStatistics`) | All existing projects have `color = NULL` (no backfill), so statistics colours go blank or fall back to whatever default the old UI uses. The old UI also cannot set `color`. |

### F4 — User deletion `1bee6fa` in isolation

- **What it adds:** the artisan command `app:users:delete {email}` (`app/Api/Users/Commands/DeleteUser.php`, interactive confirmation), plus `UserService::destroy()` rewritten to return a summary array instead of `void`. The command is auto-registered through the existing `->withCommands([... app/Api/Users/Commands ...])` in `bootstrap/app.php`, which is unchanged.
- **No HTTP surface:** `routes/api.php` is not touched by `1bee6fa`. The only caller of `UserService::destroy()` is the new command (`git grep -F 'UserService' 1bee6fa -- app routes`). No frontend change is needed, and the API contract is unaffected.
- **No dependency on other commits since 1.0.15:**
  - Each of the 14 files it touches is byte-identical at `6bdedcd` and at `1bee6fa^`. Checked per file with `git rev-parse 6bdedcd:<f>` vs `1bee6fa^:<f>`. The 9 code/test files and 5 job-archive docs are new or unchanged in between, so `git cherry-pick 1bee6fa` onto `6bdedcd` applies without conflicts.
  - All referenced symbols already exist at `6bdedcd`: `UserCacheService::forgetByPrefix`, `CalendarSubscription::scopeForPrincipal`, `DevRequest`, `VerificationToken`, `HasPushSubscriptions`/`Notifiable`/`HasApiTokens` on `User`, `deleteCalendarCustom`, `deleteAddressBookCustom`.
  - `DeleteUserTest.php` and `DavServiceTest.php` reference none of the new features (no journal, `is_system`, `color` or calendar-mark usage).
  - It needs no migration.
- **Covers the new data too:** if `main` is deployed later, the users-FK cascades also remove the `.journal` category and the new `user_settings` columns. The cache-key list includes `note_categories` and `notes_newest`.
- **⚠ DAV / `pgsql` flag (project hard rule):** `1bee6fa` adds **DELETE statements on the dedicated `pgsql` (SabreDAV) connection**:
  - `AppCalendarsPDO::deleteSharedInstancesCustom` deletes from `calendarinstances` where `access > 1`.
  - `Calendars::deleteForUser` deletes owned calendars only and skips shared ones.
  - `AddressBooks::deleteForUser`.
  - `DavService::deleteForUser` deletes from `calendarsubscriptions`, `schedulingobjects` and `propertystorage` (path prefix `calendars/<email>`, `addressbooks/<email>`).
  - `Principals::delete` deletes the principal and its proxy principals from `principals`, plus `groupmembers` rows in both directions.

  It does **not** change the `pgsql` schema or any DAV route (`routes/web.php` is untouched). Deploying the code deletes nothing on its own. Data is removed only when someone runs `php artisan app:users:delete <email>` and confirms.
- **Known gaps, as recorded in the job's own `implementation.md`:** Pulse/Telescope rows are not purged. Pending queue jobs holding a `User` can recreate cache keys for up to 24 h. Orphaned shared `feeds` rows remain. The Telegram conversation state expires on its own after 600 s.

### F5 — Non-API runtime changes

- `routes/console.php` (`e0f4e1f`): the weekly `GrabMovieReleases` schedule is **commented out**, so movie release notifications stop. This ships with any deploy of `main` but **not** with a hotfix built from `6bdedcd` unless `e0f4e1f` is cherry-picked too. `e0f4e1f` touches only `routes/console.php` plus its own job docs.
- No other scheduled, queued or notification changes: no files under `app/Api/*/Jobs` or `app/Api/*/Notifications` changed.
- No config, cache-store, composer or Docker-secret changes: `config/`, `composer.*` and `app/Shared/` are unchanged, and the diff adds no new `DockerSecretHelper`, `config()` or `env()` usages.
- Deploying `main` runs 4 migrations, including a backfill that loops over all users. Deploying 1.0.15 + `1bee6fa` runs **none**.

### F6 — Are the unfinished features dark?

| Feature | Dark if `main` is deployed? | Why |
|---|---|---|
| Calendar marks (journal + check-in) | **Yes** | New endpoints and settings only. The new settings default to `false`. |
| Journal settings (`journal_enabled`) | **Yes** | New endpoint and field, default `false`. |
| Journal folders (`.journal`, hidden categories) | **No** | Backfill plus existing list endpoint (F3 #1, 1a, 1b) |
| Time-tracking project colours / stats filter | **Partly** | New fields are additive, but statistics colours change for everyone (F3 #2) |
| Movie release notifications off | n/a | Runtime change (F5) |

### F7 — Limitations of this analysis

- The frontend repository is not in this workspace. Compatibility is judged **only from the API contract** (routes, requests, resources, services). How the 1.0.15 UI renders a `.journal` folder (for example, whether it hides dot-prefixed titles), a 403 on edit, or a `null` stats colour was not verified.
- The test suite was **not run**: there is no `vendor/` in this worktree, and installing dependencies was out of scope for a report. The clean cherry-pick is shown at file level, not by an actual cherry-pick plus test run. The agent's git is limited to read/commit, so `cherry-pick` and `merge-tree` were unavailable.
- No migrations were run against a real database.

## Recommendations

**Option A: deploy `main` API-only, as it is now.**
Action: release `main` (`1bee6fa`), run the 4 migrations, leave the frontend at 1.0.15.
Consequence: user deletion goes live. Calendar marks and journal settings stay dark. Users see the `.journal` folder (F3 #1) and blank statistics colours (F3 #2). Movie release notifications stop (F5). The `.journal` backfill cannot be reversed by migration rollback. Choose this only if "not ready to be published" means "the frontend doesn't show it yet" and the two visible effects are acceptable. Otherwise, first make `GET /v1/notes/categories` hide `is_system`/`is_hidden` categories and backfill or fall back for stats colours. That would be a separate job.

**Option B: hotfix 1.0.16 = `6bdedcd` + cherry-pick `1bee6fa`.**
Action: branch from tag `1.0.15`, `git cherry-pick 1bee6fa` (add `e0f4e1f` too if movie notifications should also be off in production), run `composer test`, tag 1.0.16, deploy. No migrations run. Then run `php artisan app:users:delete <email>` for the user(s) concerned, which writes to `pgsql` (F4 flag).
Consequence: only user deletion (plus optionally the cron change) reaches production. None of the unfinished code or migrations ships. `main` already contains `1bee6fa`, so the next normal release from `main` stays consistent.

**Recommendation: Option B.** The brief asks for user deletion to go live while the other features stay unpublished. Option B does exactly that with no migrations and no user-visible change. Option A leaks part of the journal-folders work into the old UI, and its backfill can't be rolled back.

**Before deploying either option:** run the full test suite on the release commit. Run `app:users:delete` once against a staging copy of both databases (MariaDB and `pgsql`) before using it in production.

### Open questions for the maintainer

1. Which features count as "not ready"? This report assumes calendar marks, journal settings, journal folders and time-tracking colours. Please confirm.
2. Does "not ready to be published" mean "frontend shouldn't show it" (A becomes possible after fixing F3 #1/#2) or "code and migrations must not reach production" (only B works)?
3. Should the movie-release deactivation (`e0f4e1f`) go into the hotfix as well?
4. The frontend repo was not available: please confirm how the 1.0.15 UI handles the `.journal` category and a `null` statistics colour, in case Option A is still considered.

# Tasks: Including external calendars via DAV

id: immediate
analyst: @analyst
date: 2026-11-08

## Context / open points

- Job type is `report`: the only output is a new file in `docs/reports/`. No
  code, migrations, config, or route changes.
- Branch checked: worktree HEAD is `report/immediate_including-external-calendars-via-dav`, matches brief.
- The brief says "There should already be a report on this." None of the
  current `docs/reports/*.md` covers external calendars. The closest is
  `docs/reports/vtodo-caldav-sync-2026-10-14.md` (it covers ICS
  subscriptions/webcal for todos, §D1 and "Client reach"). The developer
  should search again (also `docs/backlog/`, `docs/jobs/archive/`, git log)
  and say in the report whether a previous report exists. If none exists,
  say so and don't make one up.
- The brief leaves "Why" and "Out of scope" empty, so it's unclear whether
  the target is read-only (subscribe and show), one-time import, or two-way
  sync. The report should compare these as options and not pick one
  silently. It should list the choice as an open question for the owner.
- The analyst can't run git (read-only agent). The legacy Nextcloud
  integration still shows up in the tree. Evidence so far:
  - migrations `2025_08_23_174641_create_nextlcoud_app_tokens.php`
    (`nextcloud_url`, `username`, `app_token` stored per user)
  - `2025_08_29_063842_create_nextcloud_calendar_entries_table.php`
  - `2025_10_20_14*` (url/etag/index on entries)
  - `2025_11_04_172320_add_is_recurring_to_nextcloud_calendar_entries.php`
  - removed models `App\Models\NextcloudCalendarEntry` / `NextcloudContact`
    (noted in `docs/jobs/archive/delay_create-tests/implementation.md`)
  - `calendar_entries` table "from the Nextcloud integration"
    (`docs/jobs/archive/shadow_…/brief.md`)
- Current building blocks to evaluate:
  - `app/Dav/Services/CalendarImport.php`: generic PROPFIND/REPORT
    discovery + basic-auth one-time import, already not Nextcloud-specific
  - `app/Api/Calendars/Controllers/ImportController.php` + `Jobs/ImportCalendars.php`
  - SabreDAV `calendarsubscriptions` table / `App\Dav\Models\CalendarSubscription`
    (`source`, `refreshrate`, `isExternal()`) on the `pgsql` connection
  - `app/Dav/Backend/AppCalendarsPDO.php`, `app/Dav/Services/Calendars.php`,
    `Events.php`, `CalendarService.php`, `RefreshCalendarCache` job
  - `DockerSecretHelper` / credential storage conventions; scheduler in `routes/console.php`

## Task breakdown

TASK-1: Use `git log` (e.g. `git log --all -S nextcloud -i`, `git log --diff-filter=D --name-only` on `app/Models/Nextcloud*`, the migration dates above) to find the commits that added, ran, and removed the Nextcloud calendar integration, and write down their hashes and the relevant files.
  files: none changed (read-only research; git history, `database/migrations/2025_08_*`–`2025_11_04_*`)
  depends: none
  risk: low — read-only, but the history may be squashed or rewritten, so the report must say exactly what was and wasn't found.

TASK-2: Reconstruct how the legacy integration worked from those commits: auth (app tokens), discovery, fetch/sync (etag/url, polling schedule, jobs), storage (`nextcloud_calendar_entries`), recurrence handling, write-back (if any), error handling, and why it was removed.
  files: none changed (reads old versions of `app/Models/Nextcloud*`, related services/jobs/controllers via `git show`)
  depends: TASK-1
  risk: medium — "it worked fine" comes from the brief and can't be checked in the code. The report should keep the documented behaviour apart from claims it can't verify.

TASK-3: Map the current DAV setup that's relevant to external calendars: `CalendarImport` (generic discovery), the `calendarsubscriptions` table and `CalendarSubscription::isExternal()`, how API calendar listing/events read from `pgsql`, caching (`RefreshCalendarCache`, `UserCacheService`), and how DAV clients would see an external calendar.
  files: none changed (`app/Dav/**`, `app/Api/Calendars/**`, `routes/console.php`, `routes/web.php`)
  depends: none
  risk: low — read-only. Anything that would touch the `pgsql` schema or DAV routes must be flagged in the report, per hard rules.

TASK-4: Check existing reports/backlog for a previous external-calendar report (see open points). Note how it overlaps with `vtodo-caldav-sync-2026-10-14.md` so the new report links to it and doesn't repeat it.
  files: none changed (`docs/reports/`, `docs/backlog/`, `docs/jobs/archive/`)
  depends: none
  risk: low — read-only.

TASK-5: Draft generic (provider-agnostic) design options that combine the legacy approach with the current setup. At minimum: (a) read-only ICS/webcal subscription via `calendarsubscriptions` + scheduled refresh; (b) CalDAV pull-sync of remote collections into local calendars, reusing `CalendarImport` discovery plus ctag/etag or sync-token (RFC 6578) diffs; (c) two-way CalDAV sync. For each option, cover data model, credential storage/encryption, scheduling/queue load, SSRF/outbound-fetch limits (link `bot-traffic-detection-2026-10-14.md`), recurrence/timezone handling, how the API and DAV clients would see it, user deletion (`DavService::deleteForUser`), and effort.
  files: none changed
  depends: TASK-2, TASK-3
  risk: medium — design judgement. Credential storage and SSRF need clear flags, and any `pgsql`/DAV changes an option needs must be called out explicitly.

TASK-6: Write the report to `docs/reports/external-calendars-via-dav-2026-11-08.md`, using the structure of the existing reports (summary, findings with file:line and commit refs, legacy analysis, options compared in a table, recommendation, suggested follow-up jobs, open questions for the owner, e.g. read-only vs two-way, provider auth like OAuth for Google/iCloud app passwords, per-user limits).
  files: `docs/reports/external-calendars-via-dav-2026-11-08.md` (new)
  depends: TASK-1, TASK-2, TASK-3, TASK-4, TASK-5
  risk: low — documentation only. Main risk is claims it can't verify, so mark unverified points as such, as the vtodo report does.

TASK-7: Fill in `implementation.md` for this job and commit the report. `composer test` and `composer analyse` must still exit 0. Nothing in code changes, but the project rules require both checks for every job.
  files: `docs/jobs/immediate_including-external-calendars-via-dav/implementation.md`, the new report
  depends: TASK-6
  risk: low — docs only.

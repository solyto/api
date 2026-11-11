# Report: Including external calendars via DAV

date: 2026-11-08
branch: `report/immediate_including-external-calendars-via-dav` (job `immediate`), tree at `6d7fdfc`
scope: How can users include an external calendar (Nextcloud, or any CalDAV/ICS source) in solyto? This report looks at the legacy Nextcloud integration, the current DAV setup, and provider-neutral options. It's written for the maintainer, who makes the product decisions. No application code, migrations, config, routes or DAV wiring were changed.
read: true

## Summary

**There is no earlier report on this.** The brief expected one. I searched `docs/reports/`, `docs/backlog/`, `docs/jobs/archive/` and the git log and found none. The closest is `vtodo-caldav-sync-2026-10-14.md`. It covers solyto *serving* data to clients (including an ICS feed for todos, its option D), not solyto *pulling* external calendars. This report links to it and doesn't repeat it (§4).

**The legacy Nextcloud code is not in git history (§1).** The repo starts at `37f9642` "Initial Commit" (2026-04-07). By then the Nextcloud models, services and jobs were already gone. The integration ran from August to November 2025, before the repo's history begins. What survives:

- its migrations (schema only)
- two helpers that still referenced the removed models until `5e2e4aa`
- the generic CalDAV fetch/parse code, which now powers the one-time import

So the legacy design (§2) is rebuilt from schema and leftover code. Its behaviour, including "it worked fine", **can't be verified** from this repo.

**What the legacy integration did:** it stored a Nextcloud URL, username and app token per user. It mirrored the remote calendars into MariaDB tables (`nextcloud_calendars`, `nextcloud_calendar_entries`), keeping each event's remote `url` and `etag` so later fetches could spot changes. Recurrence was stored as a raw `RRULE`. Write-back can't be shown.

**What exists today (§3):**

- a working one-time CalDAV import (`CalendarImport`). Discovery is still Nextcloud-shaped: it hardcodes `principals/users/<username>/`.
- an unused SabreDAV `calendarsubscriptions` table with a `CalendarSubscription::isExternal()` helper
- a read-only share mechanism (`access = 2`) that DAV clients already respect

Nothing refreshes anything on a schedule. The import throws away the remote UID and ETag, so it can't be re-run without making duplicates.

**Options (§5):**

- **A:** read-only ICS/webcal subscription
- **B:** read-only CalDAV pull-sync of remote collections into a local, read-only solyto calendar
- **C:** two-way CalDAV sync

**Recommendation (§6):** B. It is the legacy approach made provider-neutral: RFC 6764/4791 discovery instead of the Nextcloud path, and ctag + ETag diffs (sync-token where offered) instead of Nextcloud specifics. A falls out of it cheaply as a first phase, because an ICS URL is just a collection without discovery. C is not recommended for now.

**B and A need a new MariaDB table and an outbound URL guard. They don't need pgsql schema changes or DAV route changes.** Mirrored events would live in ordinary pgsql calendars, so the API, widgets, reminders and DAV clients see them with no new read path. ⚑ One point needs a decision because it touches pgsql *data* semantics: how a mirrored calendar is marked read-only (§5.B, "Read-only marking").

## Method

- Code was read on this tree. File:line references point at `6d7fdfc`.
- Git: `git log --all -i --grep=nextcloud`, `git log --all -S Nextcloud`, `git grep -i nextcloud 37f9642`, `git log -- 'app/Models/*'` and `git show` of the relevant commits.
- The legacy schema comes from the migrations, which are still in `database/migrations/` and unchanged since `37f9642`.
- Nothing ran against a live remote DAV server. Statements about remote servers (Google, iCloud, Fastmail, Nextcloud) are from general protocol knowledge and marked **unverified** where it matters.
- `composer test` and `composer analyse` were run at the end of the job (see `implementation.md`).

## 1. Git findings (TASK-1)

| Commit | Date | What it shows |
| --- | --- | --- |
| `37f9642` Initial Commit | 2026-04-07 | First commit in the repo. The Nextcloud **models, services, jobs and controllers are already gone** (`git log -- 'app/Models/*'` is empty). Remnants: `app/Bots/SolytoBot.php:12,100` still imports and queries `App\Models\NextcloudCalendarEntry` (dead, would fatal at runtime). `app/Dav/Helpers/ICalendarHelper.php:14-66` has `buildICalendarData(NextcloudCalendarEntry)` and `generateEventFilename()`. `app/Dav/Helpers/VCardHelper.php:12,110` has the contact equivalents. All Nextcloud migrations are present. |
| `4803c35` Clean up bot functionality | 2026-05-14 | Removes the `NextcloudCalendarEntry` use from the bot. |
| `0d932a3` create tests | 2026-08-14 | Adds `tests/Unit/Dav/ICalendarHelperTest.php`. The job notes (`docs/jobs/archive/delay_create-tests/implementation.md:173-174`) flag the helpers' Nextcloud branches as dead. |
| `5e2e4aa` chore: remove dead nextcloud code from dav helpers | 2026-08-14 | Removes `buildICalendarData` / `generateEventFilename` (and the vCard equivalents). The **parse and discovery** functions in `ICalendarHelper` stay. This is the only commit with "nextcloud" in its message. |
| `e2da36a` | 2026-10-29 | Daily reminder and `/day` read events from DAV instead of the legacy `calendar_entries` model (`docs/jobs/archive/shadow_…/brief.md:13`). |

**Not found:** the commits that added, ran or removed the Nextcloud sync itself (models, HTTP client, sync job, schedule, controllers). They predate `37f9642`, and the history before it isn't in this repo, either squashed or kept elsewhere. If an older repo or backup exists, the open questions (§8) ask for it.

**Legacy migrations** (still run on every fresh install, never dropped):

| Migration | Content |
| --- | --- |
| `2025_08_23_174641_create_nextlcoud_app_tokens.php:14-21` | `nextcloud_app_tokens`: `nextcloud_url`, `username`, `app_token` (unique, **plain string, not encrypted**), `user_id` FK cascade |
| `2025_08_29_063842_create_nextcloud_calendar_entries_table.php:15-40` | `nextcloud_calendars` (`title`, `url`, `is_active`, `user_id`) and `nextcloud_calendar_entries` (`title`, `description`, `location`, `start_date`, `end_date`, `is_all_day`, `calendar_id` FK, `user_id`) |
| `2025_10_20_131136_add_color_to_calendar.php` | `color` on `nextcloud_calendars` |
| `2025_10_20_142359_change_calendar_entries_to_uuid.php` | entry ids become UUIDs. On MariaDB it **deletes all entries first** (`:33`), so the data was treated as a re-fetchable cache. |
| `2025_10_20_144250_add_url_etag_to_nextcloud_calendar_entries.php:14-21` | `etag`, `url` (1000) per entry |
| `2025_10_20_144426_add_url_index_to_nextcloud_calendars.php:14-16` | `nextcloud_calendars.url` unique |
| `2025_11_04_172320_add_is_recurring_to_nextcloud_calendar_entries.php:11-15` | `is_recurring`, `recurrence_rule`, `recurrence_end` |
| `2025_11_04_181435_create_native_dav_tables.php` | Same day: native `calendars` / `calendar_entries` (MariaDB). That was the first own-calendar step. |
| `2025_12_08_161442_create_dav_tables.php` | SabreDAV schema on `pgsql`, the current setup |

Nothing references the `nextcloud_*` tables any more (grep over `app/`, `routes/`, `config/`, `tests/`). User deletion still covers them through the `users` FK cascade (`docs/jobs/archive/reduction_full-user-deletion-routine/implementation.md:128`).

## 2. How the legacy integration worked (TASK-2)

Kept apart by evidence level.

**Shown by schema or surviving code:**

- **Auth:** one Nextcloud account per user (`nextcloud_app_tokens`): base URL + username + app password, sent as HTTP Basic. The surviving `CalendarImport::getBasicRequest` (`app/Dav/Services/CalendarImport.php:224-231`) does exactly this.
- **Discovery:** PROPFIND on `<base>/principals/users/<username>/` for `calendar-home-set`, then PROPFIND Depth 1 on the home set for collections with `resourcetype/calendar`, `displayname` and Apple `calendar-color` (`CalendarImport.php:177-207`, `ICalendarHelper.php:11-58,182-228`). The principal path is the Nextcloud/SabreDAV convention. The test fakes "a Nextcloud-style remote DAV server" at `remote.php/dav/` (`app/Dav/Tests/DavImportTest.php:12,27`).
- **Fetch:** one `calendar-query` REPORT per collection, filtered to VEVENT in ±10 years, asking for `getetag` + `calendar-data` (`ICalendarHelper.php:230-249`).
- **Parse:** sabre/vobject, one row per VEVENT, keeping `etag` and `href` (`ICalendarHelper.php:60-160`). The parser's output keys (`url`, `etag`, `is_recurring`, `recurrence_rule`, `recurrence_end`) match the legacy entry columns one to one. That strongly suggests this is the legacy sync parser, reused for import.
- **Storage:** a MariaDB mirror of selected calendars (`is_active` per calendar), flattened to columns. Remote identity was the entry `url` + `etag`. Calendar identity was the unique collection `url`.
- **Recurrence:** raw `RRULE` string plus `UNTIL` as `recurrence_end`. `RECURRENCE-ID` overrides were flagged `is_recurring` and stored as separate rows (`ICalendarHelper.php:109-114`). EXDATE and timezones weren't stored. The bot read the table with `start_date LIKE 'Y-m-d%'` (`37f9642:app/Bots/SolytoBot.php:100`), so recurring events showed only on their first day.
- **Write-back:** `buildICalendarData(NextcloudCalendarEntry)` (removed in `5e2e4aa`) rendered a full VEVENT from an entry. Rendering and a `<uid>.ics` filename point to PUTs back to Nextcloud, or to serving entries over DAV. **Which one can't be told** from what survives.

**Not shown, can't be verified here:** the refresh schedule (nothing in `routes/console.php` at `37f9642`), whether sync was ctag/ETag-diffed or a full re-fetch, error handling and backoff, deleted-event handling, why the integration was removed, and "it worked fine". The likely reason for removal is that the own SabreDAV server (Nov–Dec 2025 migrations) replaced the need to use Nextcloud as storage. **Inferred, not documented.**

**Lessons that carry over:** remote `url` + `etag` per object is the right change key. Storing the remote object as flattened columns lost data (EXDATE, overrides, TZ, alarms). An unencrypted token column doesn't meet today's standards.

## 3. Current DAV setup relevant to external calendars (TASK-3)

**One-time import (`/api/v1/calendars/import`, `routes/api.php:187-191`)**

- `ImportController` → `CalendarImport::start` → discovery → user picks calendars → `ImportCalendars` job (`app/Api/Calendars/Jobs/ImportCalendars.php`) creates a local pgsql calendar per remote one and an event per VEVENT.
- It's provider-neutral except for discovery: `CalendarImport.php:181` hardcodes `principals/users/<username>/`. Google, iCloud, Fastmail and others use different principal paths (**unverified per provider**). A generic client should follow RFC 6764 (`/.well-known/caldav`) and `current-user-principal` (RFC 5397) instead.
- Events go through `EventDTO::fromImport` (`app/Dav/DTOs/EventDTO.php:96-115`), which sets `uri: null, etag: null`. The **remote UID and ETag are dropped** and the event is re-rendered from parsed fields (alarms, attendees, EXDATE, overrides as linked exceptions are lost). `AppCalendarsPDO::importEventCustom` (`app/Dav/Backend/AppCalendarsPDO.php:409-414`) stores raw vCal and would avoid this, but nothing calls it.
- The parser never sets the `timezone` key that `fromImport` reads (`EventDTO.php:108`), so imported events have no TZID.
- The import state, **including the plaintext app password**, sits in the default cache for 60 min (`CalendarImport.php:233-236`, `CalendarImportDTO::$secret`).
- No outbound URL guard: any `url` that passes `required|url|max:255` (`StartImportRequest.php`) is fetched. This matches the SSRF side finding in `bot-traffic-detection-2026-10-14.md` §2, where `*/import/*` is listed for the `outbound-fetch` limiter (§5 of that report).

**`calendarsubscriptions` (pgsql)**

- The table exists (`create_dav_tables.php:145,336`) with `source`, `refreshrate`, `striptodos/alarms/attachments`. `App\Dav\Models\CalendarSubscription` has `isExternal()` (`app/Dav/Models/CalendarSubscription.php:45-48`). `DavService::deleteForUser` deletes the rows (`app/Dav/Services/DavService.php:66`).
- `Sabre\CalDAV\Subscriptions\Plugin` isn't registered (`app/Dav/Factories/DavServerFactory.php:44-58`). Per the CalDAV subscriptions model, **sabre never fetches `source` itself**: the table only stores a URL that *clients* (mainly Apple) fetch on their own. It can't serve events to the API, the widgets or the reminders. A server-side refresh would need its own storage anyway.

**Read path**

- `CalendarService::list` / `listEvents` (`app/Api/Calendars/Services/CalendarService.php:31,88`) read calendars and expanded events from pgsql through `AppCalendarsPDO`, cached per user (`calendars`, `calendar_events` keys, TTL 15 min to 7 days, `:17-22`). The reminder, `/day` and widgets use the same DAV-backed source.
- Anything written into a normal pgsql calendar shows up everywhere (API, widgets, reminders, export, every DAV client) with no new read code.
- Server-side writes go through `AppCalendarsPDO` → stock sabre PDO, so `calendarchanges` and sync-tokens update and DAV clients pick up mirrored changes through the Sync plugin. Writes outside a DAV request don't trigger `CacheInvalidationPlugin`, so a refresh job must clear the user cache itself, as `ImportCalendars` does (`ImportCalendars.php:40`).

**Read-only semantics**

- Sharee instances with `access = 2` are exposed as `read-only` (`AppCalendarsPDO.php:109-111,163-165`), and sabre refuses client writes to them.
- The **owner** instance is always `access = 1`. sabre has no read-only owner calendar.
- The API's event write endpoints have no access check of their own. They call the backend directly (from reading `CalendarController` and `CalendarService`), so a read-only rule needs an explicit API guard.

**Scheduling**

- Nothing calendar-related in `routes/console.php`. The precedent for a periodic outbound fetch is `SyncFeeds` (hourly, `routes/console.php:8`). It is the abuse vector the bot-traffic report ranks first.

## 4. Earlier reports (TASK-4)

- No external-calendar report exists (searched `docs/reports/*`, `docs/backlog/*`, `docs/jobs/archive/*`, git log).
- `vtodo-caldav-sync-2026-10-14.md` overlaps only at the edges:
  - its option D (an ICS feed *served* by solyto, per-user secret URL) is the opposite direction
  - its "Client reach" notes on ICS subscriptions describe client behaviour
  - its §1.4 "the DAV server already accepts VTODOs" matters here too: mirrored remote collections can contain VTODOs. Options A/B should strip them (like `striptodos`) until that report's Phase 0 is done.
- `bot-traffic-detection-2026-10-14.md` §2 and §5: SSRF guard and `outbound-fetch` limits. Every option below inherits these and should be gated on them.

## 5. Options (TASK-5)

### Option A: read-only ICS/webcal subscription

**What:** the user pastes an ICS URL (`webcal://` → `https://`). A scheduled job GETs it, with `If-None-Match` / `If-Modified-Since`, and replaces the contents of a local read-only pgsql calendar.

- **Data model:** new MariaDB table `external_calendars` (user, kind=`ics`, URL, local `instance_id`, `etag`/`last_modified` of the feed, `last_synced_at`, `last_error`, `failures`). Objects are keyed by UID, written with `importEventCustom` (raw vCal, keeps RRULE/EXDATE/overrides/TZ). Deterministic resource names (`hash(UID).ics`) so a refresh can update or delete instead of recreating. Not `calendarsubscriptions`: sabre doesn't fetch it (§3), and it is pgsql/DAV-owned.
- **Credentials:** usually none. Secret URLs *are* credentials, so store the URL `encrypted` (precedent: `UserPlugin` `encrypted:array` cast, `app/Plugins/Models/UserPlugin.php:33`).
- **Load:** one GET per feed per interval. Honour 304. Per-user feed cap. Backoff on failures.
- **SSRF:** shared URL guard (no private/loopback/link-local, re-checked after DNS and on redirects), size cap, timeout, `outbound-fetch` limiter.
- **Recurrence/TZ:** handled by storing raw VEVENTs. sabre expands them on read. Missing VTIMEZONE in sloppy feeds: **unverified** how sabre treats it, test with real feeds.
- **API/DAV visibility:** a normal calendar for both. Needs a read-only marker (see B).
- **User deletion:** the MariaDB row cascades on `users`. The pgsql calendar goes with `Calendars::deleteForUser`.
- **Effort:** S–M. Covers Google/iCloud/Outlook "secret iCal address" with no auth work.

### Option B: read-only CalDAV pull-sync (recommended)

**What:** the legacy approach, provider-neutral. The user gives a server URL + username + app password. solyto discovers collections, the user picks some, and each becomes a local read-only pgsql calendar refreshed on a schedule.

- **Discovery:** `/.well-known/caldav` (RFC 6764) → `current-user-principal` → `calendar-home-set` → collections. Fall back to the URL as given, and to today's `principals/users/<username>/` path for Nextcloud. This also fixes the one-time import (§7.2).
- **Sync:**
  - per collection, compare `getctag` / `sync-token` first, so an unchanged calendar costs one PROPFIND
  - if changed: `sync-collection` REPORT (RFC 6578) where supported, otherwise PROPFIND `getetag` Depth 1 and diff against stored ETags
  - `calendar-multiget` only the changed hrefs, delete local objects whose href vanished
  - no ±10-year `calendar-query`, which loses old recurring masters and fetches everything every time
- **Data model:**
  - `external_accounts`: user, base URL, username, `secret` (`encrypted`), status, `last_error`
  - `external_calendars`: account, remote href, remote ctag/sync-token, local `instance_id`, interval
  - `external_calendar_objects`: external calendar, remote href, remote ETag, local URI

  All MariaDB, new migrations, **no pgsql schema change**. The object map is what lets updates and deletes land on the right local object (`Calendars` / `AppCalendarsPDO` by URI). The legacy `url`/`etag` columns were the same idea.
- **Credentials:** app passwords only (Nextcloud, iCloud app-specific passwords, Fastmail; **unverified** per provider). Google CalDAV requires OAuth 2. That is a separate auth flow, so for Google this report suggests option A (secret iCal address). The password never goes in the cache or logs. Fix the import's 60-min plaintext cache while touching it.
- **Load:**
  - `SyncExternalCalendars` scheduled job (e.g. every 15–30 min) fans out one queued job per account
  - stagger accounts, cap accounts and calendars per user
  - backoff, and auto-disable after N failures with a notification
  - ctag short-circuit keeps the steady state at roughly one request per calendar per interval
- **SSRF:** same guard as A, applied to every discovered href too. A hostile server can return hrefs that point to internal hosts, so hrefs must stay on the account's host.
- **Recurrence/TZ:** store the remote object's raw iCalendar via `importEventCustom` / update with raw data, never re-render through `EventDTO`. Strip VTODO/VALARM per policy.
- **Read-only marking:** ⚑ decision needed, it touches pgsql data semantics but not the schema. Choices:
  1. Owner instance stays `access = 1`. The API refuses event writes when the calendar is in `external_calendars`, and a sabre plugin (new plugin, ⚑ `DavServerFactory` wiring) refuses DAV PUT/DELETE into those calendars.
  2. Create the calendar under a system principal and give the user an `access = 2` instance, which reuses sabre's read-only handling. But it bends the sharing model, the owner-name logic and `deleteForUser`.

  Option 1 is less invasive.
- **API/DAV visibility:** full: list, month view, widgets, reminders, export, and every DAV client of the user (DAVx⁵, Apple, Thunderbird) through the existing Sync plugin. That is the main advantage over the bare `calendarsubscriptions` route. `CalendarResource` should expose `is_external` / `read_only`, which is an API addition the frontend needs.
- **User deletion:** MariaDB rows cascade. The local calendars go with `Calendars::deleteForUser`. Optionally also delete when the external account is removed.
- **Effort:** M–L. Reuses `ICalendarHelper` parsing, `CalendarImport` HTTP and the import flow's select UI.

### Option C: two-way CalDAV sync

**What:** B plus write-back. Local edits are PUT to the remote server with `If-Match`, and remote changes are pulled.

- Needs everything in B, plus conflict handling (412 → re-fetch + merge or remote-wins), echo/loop suppression (the same issues `vtodo-caldav-sync-2026-10-14.md` lists under "Echo and loops" / "Ordering"), a write path from both the API and DAV-client edits on the mirror, and per-provider quirks (scheduling, attendees, iTIP).
- **Credentials:** need write scope. **Effort:** L–XL. Risk: data loss on the user's *remote* calendar if there is a bug. That is the part users care about most.
- The usual expectation of DAV users is the reverse: point their client at both servers. That already works today.

### Comparison

| | A: ICS subscription | B: CalDAV pull (read-only) | C: two-way CalDAV |
| --- | --- | --- | --- |
| Covers | any public/secret ICS URL (Google, iCloud, Outlook, webcal) | Nextcloud, iCloud, Fastmail, Radicale, Baïkal… with app password | same as B |
| Auth | none / secret URL | Basic + app password; Google needs OAuth (not covered) | as B, write scope |
| New storage | 1 MariaDB table | 3 MariaDB tables | as B + conflict state |
| pgsql schema / DAV routes | unchanged / unchanged | unchanged / unchanged | unchanged / unchanged |
| DAV wiring | read-only guard plugin ⚑ | read-only guard plugin ⚑ | none (writes allowed) |
| Change detection | HTTP ETag / 304 (whole feed) | ctag → sync-token or ETag diff (per object) | as B + If-Match |
| Visible in API, widgets, reminders, DAV clients | yes | yes | yes |
| SSRF / outbound limits | required | required (+ href host pinning) | required |
| Effort | S–M | M–L | L–XL |
| Main risk | sloppy feeds, abuse as crawler | provider discovery quirks | remote data loss |

## 6. Recommendation

**Build B with A as its first phase, and don't do C now.**

1. **Phase 0, prerequisites:** the SSRF URL guard and the `outbound-fetch` limiter from `bot-traffic-detection-2026-10-14.md`. Without them, a scheduled external fetch is the same open crawler as feeds.
2. **Phase 1, A:**
   - `external_calendars` (kind `ics`), scheduled refresh, raw-vCal storage keyed by UID
   - read-only guard (API + sabre plugin)
   - `is_external` on `CalendarResource`
3. **Phase 2, B:** generic discovery, `external_accounts` with an encrypted secret, ctag/sync-token/ETag diff sync, per-object map. Reuse the generic discovery in the one-time import.
4. **Revisit C** only if users ask for editing remote events from solyto specifically.

The reasons: B is what the legacy integration proved (mirror + url/etag), minus its two weak spots (flattened storage, Nextcloud-only discovery). It lands mirrored data where everything in solyto already reads it, so the API, reminders, the bot and DAV clients need no new read paths.

## 7. Suggested follow-up jobs

1. **SSRF URL guard + outbound limiter.** Already proposed in the bot-traffic report (§7, items 3 and 6). Prerequisite.
2. **Fix the one-time import** (small, independent):
   - generic discovery (well-known + `current-user-principal`, keep the Nextcloud path as fallback)
   - store raw VEVENTs via `importEventCustom` (keeps UID, TZ, EXDATE, overrides)
   - don't cache the secret in plaintext
3. **External ICS subscriptions (option A):** migration (shown before running), model, refresh job + schedule, read-only guard (⚑ `DavServerFactory` plugin), API endpoints + OpenAPI, `CalendarResource.is_external`, tests with `Http::fake`. User-facing strings (sync-failure notification) in `en`, then the translator agent.
4. **External CalDAV accounts (option B):** builds on 2 and 3.
5. **Legacy cleanup** (optional): a migration that drops the unused `nextcloud_*` tables and the native-but-dead `calendars` / `calendar_entries` once nothing reads them. Check `CalendarEntry` usages first.

## 8. Open questions for the owner

1. **Target behaviour:** read-only (A/B), one-time import (exists), or two-way (C)? This report assumes read-only.
2. **Does any older repo or backup with the 2025 Nextcloud code exist?** It would confirm the refresh interval, write-back and why it was removed.
3. **Google:** is a secret ICS URL (A) enough, or is OAuth-based CalDAV wanted? OAuth needs a Google Cloud app, consent screen and verification.
4. **Read-only marking:** API + sabre guard plugin (§5.B option 1) or a system principal with `access = 2` instances (option 2)?
5. **Limits:** max external accounts and calendars per user, minimum refresh interval, max feed size. Should self-hosters be able to change them in config?
6. **VTODOs and alarms from remote calendars:** strip them (recommended until VTODO Phase 0), or keep them?
7. **On repeated failures:** auto-disable and notify by push/email? Which channel?
8. **When the user removes an external calendar:** delete the local mirror, or keep it as a normal editable calendar (a "detach")?
9. **Should the dead `nextcloud_*` tables be dropped** (§7.5)?

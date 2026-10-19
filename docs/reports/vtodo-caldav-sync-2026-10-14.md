# Report: CalDAV sync of todos as VTODO

date: 2026-10-14
branch: `feature/scope_report-vtodo` (job `scope`), tree at `aff643a`
scope: Can solyto offer CalDAV sync of todos as VTODO components, and if so, how? Written for the maintainer, who makes the product decisions. This report only describes options. No application code, migrations, config, routes or DAV wiring were changed. Implementation goes to a follow-up job.
read: true

## Summary

**Yes, solyto can offer CalDAV sync of todos as VTODO.** The stack is not the blocker. `sabre/dav` 4.7 and `sabre/vobject` 4.5.8 handle VTODO natively, and the DAV server already runs the Sync, ACL and Sharing plugins. The blocker is the data model. solyto's todo model differs from VTODO in four places, and every option has to decide how lossy each of them may be (§2):

1. **Recurrence:** solyto keeps one row per occurrence and spawns the next row on completion. VTODO uses one master with `RRULE`.
2. **Subtasks:** solyto's are a lightweight separate table. VTODO uses full child VTODOs with `RELATED-TO`.
3. **Status:** solyto has five workflow states plus a separate completed flag. VTODO has four states.
4. **Organisation:** solyto has category, workspaces and tags. CalDAV has collections and `CATEGORIES`.

**Unexpected finding: the DAV server already accepts VTODOs (§1.4).** `AppCalendarsPDO::createCalendarCustom` hardcodes `'components' => 'VEVENT'`, but sabre ignores that key. Every app-created calendar is stored as `VEVENT,VTODO`, so task clients can already save tasks into solyto calendars. Those tasks live only in pgsql and are invisible to the todos module. Reading the code (not tested), they would also leak into the calendar export as events with no start. This needs a decision whatever happens with this feature.

**Correction to the analysis:** todos have a single write path, `TodoService`. Quick-add, `IntegrationGateway` and the Telegram bot delegate to it, and `EventAttachmentService` only writes link rows (§1.2). That makes a MariaDB-backed design much easier.

**Recommendation:** option A, a virtual VTODO-only collection per user served straight from MariaDB through a custom sabre backend that writes via `TodoService`. Phases:

- **Phase 0:** contain the existing VTODO acceptance.
- **Phase 1:** read-only collection.
- **Phase 2:** two-way for core fields.
- **Phase 3:** subtasks and inbound recurrence.

Option A changes the DAV server wiring (⚑ `DavServerFactory`) but not the pgsql schema, the pgsql data, or the DAV routes. Mirroring into pgsql (B) and moving todos to pgsql (C) were rejected for consistency and scope reasons. An ICS feed (D) doesn't count as sync. Twelve open questions for the maintainer are in §5.

## Method

- Code was read on this tree. File:line references point at `aff643a`.
- The sabre sources come from the upstream tags that `composer.lock` pins: `sabre/dav` 4.7.0 and `sabre/vobject` 4.5.8. `vendor/` isn't installed in this sandbox, and nothing was installed. These files were read from the GitHub tags. In this report, references like "sabre `CalDAV/Backend/PDO.php:239`" mean that upstream file at that tag.
- RFC text comes from rfc-editor.org: RFC 5545 (iCalendar), RFC 4791 (CalDAV), RFC 6578 (WebDAV sync) and RFC 9253 (iCalendar relationships).
- Client documentation: the DAVx⁵ manual (`manual.davx5.com/tasks_notes.html`) and the tasks.org docs (`tasks.org/docs/recurrence`, `tasks.org/docs/sync_caldav`).
- Nothing ran against a live DAV server, and no real client was tested. Statements about how the *current* server behaves come from reading the code and say so. Client behaviour I couldn't confirm from a primary source is marked **unverified**.

## 1. Current state

### 1.1 Todo data model (MariaDB, default connection)

`App\Api\Todos\Models\Todo` (`app/Api/Todos/Models/Todo.php`) uses `HasUuids` (`:18`). Its fillable fields are at `:25-43` and its casts at `:45-53`.

| Field | Storage (migration) | Notes |
| --- | --- | --- |
| `id` | `uuid` PK (`2025_08_09_140509_create_todos.php`) | stable, globally unique |
| `title` | `string` (255) | request rule `required\|string\|max:255` (`StoreTodoRequest.php:13`) |
| `description` | `text`, nullable | request rule `max:1000` (`StoreTodoRequest.php:14`, `UpdateTodoRequest.php:14`) |
| `link` | `string(2048)`, nullable (`2026_05_20_000001_add_link_to_todos.php`) | free string, not validated as a URL (`StoreTodoRequest.php:15`) |
| `is_completed` | `boolean`, default false | separate from `status` |
| `completed_at` | `dateTime`, nullable (`2025_08_23_171954_…`) | set by `TodoService::update` when completing (`TodoService.php:189-193`) |
| `priority` | `enum('low','medium','high')`, default `medium` | `TodoService::parse` defaults to `medium` (`TodoService.php:60`) |
| `status` | `enum('backlog','pending','in-progress','waiting','almost-done')`, default `pending` (`2025_08_21_104026_…`) | independent of `is_completed` |
| `effort` | `enum('low','medium','high')`, nullable (`2025_08_15_135424_…`) | |
| `progress` | `integer`, nullable, default 0 | request rule `0..100` (`StoreTodoRequest.php:19`) |
| `due_at` | column is `dateTime` (`create_todos.php`), but the cast is `date` (`Todo.php:48`) | `resolveDate()` always returns `Y-m-d` (`TodoService.php:122-157`), so it is **date-only in practice** |
| `recurrence_frequency` / `_interval` / `_ends_at` | `enum(daily,weekly,monthly,yearly)` nullable / `tinyint` default 1 / `date` nullable (`2026_03_09_000001_…`) | |
| `parent_task_id` | `uuid`, nullable, FK → `todos.id` `nullOnDelete` | the **recurrence series root**, *not* a subtask parent (`TodoService.php:231`) |
| `auto_generated` | `boolean` | true on rows spawned by recurrence |
| `category_id` | FK → `todo_categories`, `nullOnDelete` (`2025_08_12_180448_…`) | at most one category per todo |
| tags | pivot `tag_todo` (`todo_id`, `tag_id`), both `cascade` | many-to-many (`Todo.php:65-69`) |
| `created_at` / `updated_at` | timestamps | |

Related models:

- `TodoSubtask` (`TodoSubtask.php`): integer `id`, `title`, `is_completed`, `todo_id` FK `cascade` (`2025_09_10_192722_…`). Subtasks have no due date, priority, description or nesting, and they are not `Todo` rows.
- `TodoCategory` (`TodoCategory.php`): integer `id`, `title`, `user_id`.
- `TodoWorkspace` (`TodoWorkspace.php`): integer `id`, `title`, `is_hideable`. It groups categories many-to-many through `workspace_category` (`TodoWorkspace.php:207-210`, `2025_08_30_114531_…`). One category can sit in several workspaces.
- `CalendarEventTodo` (`app/Api/Calendars/Models/CalendarEventTodo.php`) links a todo to a DAV event: `calendar_object_id` (`unsignedBigInteger`, the pgsql `calendarobjects.id`), `todo_id`, `user_id` (`2026_06_02_000000_…:11-20`).

### 1.2 Who writes todos

**Correction to the analysis:** there is exactly **one write path for todo rows**, and it is `TodoService`. The other "writers" in the task list delegate to it or only read:

| Code | What it does with todos |
| --- | --- |
| `TodoService::create/update/destroy/addSubtask/updateSubtask/destroySubtask` (`TodoService.php:159-292`) | all writes. Each one calls `forgetUser()` (`:294-298`) to drop the `todos` / `todos_due` caches |
| `TodoService::spawnNextOccurrence` (`:212-254`) | creates the next row of a recurring series |
| `QuickAddService::commitTodo` (`app/Shared/Services/QuickAddService.php:140-145`) | `TodoService::parse` + `create` |
| `IntegrationGateway::commit` (`app/Shared/Services/IntegrationGateway.php:32-37`) | delegates to `QuickAddService` |
| `SolytoBot` (`app/Bots/SolytoBot.php:139,209,221`) | `gateway->commit(...)` for writes. `todosCommand` (`:115-132`) is read-only |
| `EventAttachmentService::attachTodo/detachTodo` (`app/Api/Calendars/Services/EventAttachmentService.php:53-83`) | writes `calendar_event_todos` link rows, **not** todos |
| `TodoExportService::export` (`app/Api/Export/Services/TodoExportService.php:11-36`) | read-only CSV |
| `IntegrationGateway::dueTodos`, `SendDailyDayRemindersCommand`, `StatisticsService` | read-only |

Some writes change what a todo "looks like" without going through `TodoService` and without touching `todos.updated_at`. A sync design must not rely on `updated_at` alone:

- Deleting a category nulls `category_id` at DB level (`nullOnDelete`).
- Deleting a tag cascades `tag_todo` rows.
- `tags()->sync()` / `attach()` (`TodoService.php:164-166, 201-203`) and subtask writes don't touch the parent's `updated_at`. `TodoSubtask` has no `$touches`.
- Deleting a user cascades everything.
- `destroy()` is a hard delete (`TodoService.php:256-262`). There are no soft deletes, so nothing records that a todo was deleted.

### 1.3 Recurrence model

When a todo with `recurrence_frequency` and a `due_at` is completed, `TodoService::update` calls `spawnNextOccurrence` (`TodoService.php:197-199`). That method:

- advances `due_at` by the interval until it lies **after today**, so missed occurrences are skipped (`:218-225`)
- stops if the new date passes `recurrence_ends_at` (`:227-229`)
- creates a **new `Todo` row** with `parent_task_id` = series root and `auto_generated = true`. The new row copies title, description, priority, effort, category and tags, and resets `status` to `pending`. It does **not** copy `link`, `progress` or subtasks (`:233-251`).

So there is no "master" item with a rule. Each occurrence is its own row, and the next one only exists once the current one is done. This is the opposite of RFC 5545, where one component carries an `RRULE` and the occurrences are computed (§3.8.5.3).

Quick-add can set a recurrence with `repeat:daily|weekly|monthly|yearly`, but only together with a due date (`TodoService.php:100-105, 114-117`).

### 1.4 The DAV server

- `DavServerFactory::createServer` (`app/Dav/Factories/DavServerFactory.php:29-55`) builds the server from the **stock** `Sabre\CalDAV\Backend\PDO` on the `pgsql` connection (`:31-34`). The node tree is principals + `CalendarRoot` + `AddressBookRoot` (`:36-40`). The plugins are Auth (`LaravelAuthBackend`), ACL, Browser, CalDAV, CardDAV, **Sync** (`:49`), Sharing, CalDAV Sharing and `CacheInvalidationPlugin` (`:44-52`). There is no `ICSExportPlugin`.
- `AppCalendarsPDO` (`app/Dav/Backend/AppCalendarsPDO.php`) extends the stock backend but is only used by the app's own services (`app/Dav/Services/Calendars.php:18`, `Events.php`, `CalendarSharing.php`). The DAV server doesn't use it.
- Routes: `routes/web.php:10-11` (`dav/{path?}` and the `dav.solyto.de` domain). There is no `/.well-known/caldav` route in this repo. It may be handled by the reverse proxy (**unverified**). Clients such as tasks.org rely on it for auto-discovery (`tasks.org/docs/sync_caldav`).
- pgsql schema (`database/migrations/2025_12_08_161442_create_dav_tables.php`): `calendars.components VARCHAR(21)` (`:101`), `calendarobjects` with `componenttype`, and `calendarchanges` for sync tokens (`:163-171`).

**Correction to the analysis, and the most important finding:** the server **already accepts VTODOs today**.

- `AppCalendarsPDO::createCalendarCustom` passes `'components' => 'VEVENT'` (`AppCalendarsPDO.php:156-169`). Sabre's `createCalendar` ignores that key. It only reads the component list from the `{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set` property, and otherwise defaults to `'VEVENT,VTODO'` (sabre `CalDAV/Backend/PDO.php:239-254`). `'components'` is also not in `$propertyMap` (`:96-102`). So every calendar the app creates is stored as `VEVENT,VTODO`.
- Calendars that clients create with MKCALENDAR get whatever the client asks for, or the same default.
- The CalDAV plugin only rejects a component if it is missing from the collection's supported set (sabre `CalDAV/Plugin.php:813-836`). So a client such as Thunderbird, or DAVx⁵ with tasks.org, can already store VTODOs in a solyto calendar.

Those VTODOs live only in pgsql, and the todos module never sees them. Reading the code, they also leak into the app:

- `getExpandedEventsCustom` filters `name => 'VEVENT'` (`AppCalendarsPDO.php:212-216`), so the calendar view ignores them.
- `getEventsCustom` (`:198-210`) and `getEventByUriCustom` (`:343-354`) don't filter on `componenttype`. They run every object through `EventDTO::fromSabre`, whose `parseVCal` (`app/Dav/DTOs/EventDTO.php:144`) is line-based and not component-aware. `CalendarExportService` (`app/Api/Export/Services/CalendarExportService.php:24`) uses that list. So a stored VTODO would likely show up in the calendar export as an event with no start. This comes from reading the code; it was **not tested**.

Other VEVENT-only code:

- `EventDTO` only renders VEVENT (`EventDTO.php:292-321`).
- `ICalendarHelper`, used to import external CalDAV calendars, only reads VEVENT (`app/Dav/Helpers/ICalendarHelper.php:108, 243`).

`CacheInvalidationPlugin` (`app/Dav/Plugins/CacheInvalidationPlugin.php`):

- It hooks `afterCreateFile` / `afterWriteContent` / `afterUnbind` / `afterBind` (`:22-28`) and parses the URL path (`:198-201`).
- It only handles `calendars/…` and `addressbooks/…`. For calendars it looks up `calendarinstances` on pgsql and the user by email from `principals/<email>` (`:88-120`). It then drops the `calendar_events` cache and dispatches `RefreshCalendarCache`.
- It knows nothing about todos. For a path that has no `calendarinstances` row it returns early (`:99-101`), so it is a harmless no-op for any non-pgsql collection.

A grep for `VTODO` in `app/`, `routes/`, `config/` and `database/` finds nothing. Only the sabre library handles it, implicitly.

## 2. Field mapping: solyto todo → VTODO

The sources are RFC 5545 §3.6.2 (VTODO grammar) and the property sections cited in each row. "Out" means solyto → client and "In" means client → solyto. Grades:

- **lossless**: round-trips with no loss in either direction.
- **lossy**: maps, but some values or precision are lost in at least one direction.
- **unmappable**: no standard VTODO property fits. It can only travel as an `X-SOLYTO-*` property, which RFC 5545 allows but clients aren't required to keep.

| solyto | VTODO | Out | In | Grade |
| --- | --- | --- | --- | --- |
| `id` (uuid) | `UID` (§3.8.4.7) and resource name `<uuid>.ics` | UUID as UID | Clients choose their own UID and resource name on PUT. Both must be stored somewhere, or the client will think its item vanished. | lossless out. In needs extra storage (§3, option A) |
| — | `DTSTAMP` (required, §3.6.2) | `updated_at` in UTC | ignored | n/a |
| `title` | `SUMMARY` (§3.8.1.12) | as is | truncated to 255 | lossy (in, long titles) |
| `description` | `DESCRIPTION` (§3.8.1.5) | as is | the API caps it at 1000 characters, but the column is `text`. Either truncate or skip the API rule for DAV writes (decision) | lossy (in) |
| `link` | `URL` (§3.8.4.6, value type URI) | only if the string is a valid URI. Otherwise the output is invalid, because `link` isn't validated as a URL | as is (≤ 2048) | lossless for valid URLs |
| `is_completed` + `completed_at` | `STATUS:COMPLETED` + `COMPLETED` (§3.8.2.1, UTC date-time) + `PERCENT-COMPLETE:100` | as is | `STATUS:COMPLETED` or a `COMPLETED` value → `is_completed = true`, which must go through `TodoService::update` so recurrence spawns | lossless |
| `status` (5 values) | `STATUS` (§3.8.1.11): `NEEDS-ACTION`, `IN-PROCESS`, `COMPLETED`, `CANCELLED` | `backlog`, `pending` → `NEEDS-ACTION`. `in-progress`, `waiting`, `almost-done` → `IN-PROCESS`. The exact value goes in `X-SOLYTO-STATUS` | `NEEDS-ACTION` → `pending` and `IN-PROCESS` → `in-progress`, unless `X-SOLYTO-STATUS` survived and agrees. `CANCELLED` has **no solyto equivalent** (decision: treat as completed, delete, or reject) | lossy |
| `status` while `is_completed` | — | In solyto a completed todo keeps its workflow status (for example `in-progress`). VTODO has one `STATUS`, so the workflow value only survives in `X-SOLYTO-STATUS` | — | lossy |
| `priority` low/medium/high | `PRIORITY` 0–9 (§3.8.1.9: 1–4 HIGH, 5 MEDIUM, 6–9 LOW, 0 undefined) | high → 1, medium → 5, low → 9 | 1–4 → high, 5 → medium, 6–9 → low. 0 or missing → `medium`, because solyto has no "no priority" | lossy (in: 9 values → 3, and "undefined" is lost). Because solyto defaults to `medium`, every todo shows as medium priority in clients unless `medium` is sent as "no `PRIORITY`" (decision) |
| `progress` 0–100 | `PERCENT-COMPLETE` (§3.8.1.8, 0–100) | as is. `null` → omit | as is | lossless |
| `effort` low/medium/high | — | `X-SOLYTO-EFFORT` | only if the client keeps the X-property | unmappable |
| `due_at` (date-only) | `DUE;VALUE=DATE` (§3.8.2.3) | as is | `DUE` with a time → the time is dropped, and the timezone has to be resolved first. `DURATION` (which requires `DTSTART`, §3.6.2) → convert to a date or reject | lossless out, lossy in |
| — | `DTSTART` (§3.8.2.4) | not sent. solyto has no start date, **but** `DTSTART` is required whenever `RRULE` is present (§3.8.2.4) | dropped | lossy (in) |
| `recurrence_*` | `RRULE` (§3.8.5.3) | see "Recurrence" below. Recommended: **don't send `RRULE`**, and put the rule in `X-SOLYTO-RECURRENCE` | Simple rules can map: `FREQ=DAILY..YEARLY;INTERVAL=n[;UNTIL=date]`. `COUNT`, `BY*`, `RDATE`, `EXDATE`, `RECURRENCE-ID` overrides and "repeat from completion" have no equivalent | lossy, structurally different |
| `parent_task_id` (series root) | `RELATED-TO;RELTYPE=FIRST:<root uid>` (RFC 9253 §5: "the referenced calendar component is the first in a series") | optional, informative only | ignored | lossless out, for clients that support RFC 9253 (likely few, **unverified**). It must **not** be sent as the default `RELTYPE=PARENT`, or clients would show the series as subtasks |
| `auto_generated` | — | `X-SOLYTO-AUTO-GENERATED` or omit | ignored | unmappable |
| subtasks (title + done) | a child VTODO per subtask, each its own resource (RFC 4791 §4.1), with `RELATED-TO:<todo uid>` (default `RELTYPE=PARENT`, RFC 5545 §3.8.4.5) | UID like `solyto-subtask-<id>`, `SUMMARY`, `STATUS` | A child created by a client becomes a `TodoSubtask`, but its due date, priority, description, nesting below one level, and any further children are lost. Alternative: put the subtasks into `DESCRIPTION` as a `[ ]` / `[x]` checklist (works with every client, read-only) | lossy |
| `category` (0..1) | either `CATEGORIES` (§3.8.1.2) **or** one collection per category | see §3 | — | lossless as a collection. Lossy as `CATEGORIES`, because it can't be told apart from tags |
| tags (0..n) | `CATEGORIES` | tag names | names → find or create `Tag`. This is the same logic `TodoService::parse` already uses for `#tag` (`TodoService.php:68-81`) | lossless if category isn't also sent in `CATEGORIES` |
| workspaces | — | — | — | unmappable. Workspaces group *categories* many-to-many, so one category can be in several workspaces, and VTODO/CalDAV has no level above a collection |
| event links (`calendar_event_todos`) | `RELATED-TO;RELTYPE=SIBLING:<event UID>` (§3.8.4.5) | needs a lookup from `calendar_object_id` (pgsql id) to the event's `UID` | ignored | lossless out (optional) |
| `created_at` / `updated_at` | `CREATED` (§3.8.7.1) / `LAST-MODIFIED` (§3.8.7.3) | as is | ignored | lossless (out) |
| — | `VALARM`, `CLASS`, `LOCATION`, `GEO`, `ATTENDEE`, `ORGANIZER`, `ATTACH`, `COMMENT`, `RESOURCES`, client `X-*` | — | no columns. Dropped on round-trip unless the raw iCalendar is stored (decision, §5) | unmappable (in) |

### Recurrence in detail

The two models don't fit, and this is the hardest part of the mapping:

- **If solyto sends `RRULE`** on the open occurrence (with the `DTSTART` it then needs), clients will show future occurrences that solyto doesn't have yet. When the user completes one in the client, clients react in different ways:
  - Some move `DTSTART`/`DUE` forward on the same item (tasks.org's "repeats from due date / completion date", `tasks.org/docs/recurrence`).
  - Others write a `RECURRENCE-ID` override, or mark the whole series completed (**unverified** per client).
  
  None of these matches "mark this row done and let the server create a new row". Once solyto spawns the next row, the client would see two items for the same occurrence.
- **If solyto doesn't send `RRULE`** (recommended), every client sees one plain todo per open occurrence. Completing it in the client marks the row done. `TodoService::update` spawns the next row, and that row shows up as a new item on the next sync. This works with every client. The downside is that the client doesn't know the item repeats, and its "edit recurrence" UI won't reflect solyto's rule.
- **Inbound `RRULE`** (a client creates a recurring task): solyto can map a simple subset and treat the item as the first occurrence. Everything else must be either rejected (403 with the CalDAV `valid-calendar-data` precondition) or dropped silently, which needs a decision. The client will then also see solyto's spawned rows as separate items.

## 3. Architectural options

Every option below is judged on the same points:

- sync (ETag, CTag, RFC 6578 `sync-collection` through the Sync plugin)
- sharing
- ACL
- `CacheInvalidationPlugin`
- what it does to the `pgsql` connection, its schema, and the DAV routes and wiring

Anything that touches the `pgsql` DAV schema or data, `DavServerFactory`, or the DAV routes is **flagged ⚑**, per the project hard rules. Effort is relative: S, M, L or XL. There are no time estimates.

### Option A: virtual todo collection served from MariaDB (custom Sabre backend)

**What:** add a `Todos` calendar collection to each user's calendar home. It advertises `supported-calendar-component-set = VTODO` and is backed by a Sabre CalDAV backend that reads and writes the MariaDB `todos` tables. `TodoService` does the writes. Nothing is duplicated, and `todos` stays the single source of truth.

**How (sabre pieces):**

- Write a backend that implements `Sabre\CalDAV\Backend\BackendInterface`, extending `AbstractBackend`, which already provides a generic `calendarQuery` filter (sabre `CalDAV/Backend/AbstractBackend.php:107-120`). It also implements `SyncSupport` (sabre `CalDAV/Backend/SyncSupport.php:82`).
- Its methods: `getCalendarObjects` / `getCalendarObject` render the todo, and its subtasks if those are chosen, with `sabre/vobject`. `createCalendarObject` / `updateCalendarObject` parse the incoming VTODO, map it (§2), and call `TodoService::create/update`. `deleteCalendarObject` calls `TodoService::destroy`.
- `createCalendar` / `deleteCalendar` are refused (403). Users can't create todo collections from a client.
- ⚑ **Wiring:** sabre's `CalendarHome` gets its children from a single backend. There are two ways to add the collection:
  - **A1:** a composite backend that routes stock-PDO calendar ids (`[calendarId, instanceId]` arrays) to `Sabre\CalDAV\Backend\PDO` and a synthetic id to the todo backend.
  - **A2:** subclass `CalendarRoot` / `CalendarHome` to append a `TodoCollection` node.

  Both change `DavServerFactory::createServer` (`DavServerFactory.php:34-38`). Neither changes the pgsql schema, the pgsql data, or `routes/web.php`.

**Sync:**

- ETag = a hash of the rendered VTODO, not `updated_at`, because tag, subtask and category changes don't touch `updated_at` (§1.2).
- Sync token / CTag: needs a per-user change counter and a change log with tombstones, because todos are hard-deleted (§1.2). That means a new MariaDB table, roughly `todo_dav_changes(user_id, uri, synctoken, operation)`, written from `TodoService` and from the DB-cascade paths, plus a migration. That's MariaDB, not pgsql, but per the hard rules it must be shown before it runs.
- Without `SyncSupport`, clients fall back to PROPFIND on CTag and ETags. That's simpler, but slower for clients with many items. DAVx⁵ supports both (**unverified** for the fallback details).

**Storing client identity:** clients choose the resource name and often the UID. A two-way version needs columns such as `dav_uri` and `dav_uid` on `todos` (MariaDB migration), or a mapping table. A read-only version can use `<uuid>.ics` and the UUID as UID.

**Sharing:** none. Todos have no sharing concept today. The collection doesn't implement `ISharedCalendar`, so the Sharing plugins leave it alone.

**ACL:** the node returns the user's principal as owner, with read, or read and write, for the owner only. Principals are `principals/<email>`. The user lookup is by email, the same as `CacheInvalidationPlugin.php:103-105`. A user who changes their email moves principals, and that's the same for calendars today.

**CacheInvalidationPlugin:** no change needed. DAV writes go through `TodoService`, which already drops the `todos` / `todos_due` caches (`TodoService.php:294-298`). The plugin's pgsql lookup returns null for the virtual collection and exits (`CacheInvalidationPlugin.php:99-101`).

**Pros:**

- One source of truth.
- Recurrence spawning, quick-add, Telegram, export and statistics keep working unchanged.
- No pgsql change.
- The read-only version is a clean subset of the two-way version.

**Cons:**

- A hand-written backend in the DAV request path, wired next to the stock backend (⚑ factory change).
- Own sync-token bookkeeping.
- Every mapping decision in §2 has to be written as code.

**Effort:** M read-only, L two-way.

### Option B: two-way mirror into pgsql calendar objects

**What:**

- Each user gets a real pgsql calendar `todos` with components `VTODO`.
- **Outbound:** a hook after every `TodoService` write, either in the service or a queued job, renders the VTODO and writes it with the stock backend's `createCalendarObject` / `updateCalendarObject` / `deleteCalendarObject`. Those methods log to `calendarchanges` themselves (sabre `CalDAV/Backend/PDO.php:560`), so sync tokens, ETags and CTags come for free.
- **Inbound:** a server plugin like `CacheInvalidationPlugin` listens to `afterCreateFile` / `afterWriteContent` / `afterUnbind`. It parses the stored object and applies it through `TodoService`.

**Risks:**

- **Echo and loops:** an inbound write updates MariaDB, the outbound hook then rewrites the same object, the ETag changes, and the client re-downloads, or in the worst case sees a conflict. Outbound writes made directly on the backend don't fire server events, so it isn't an infinite loop. But every inbound write needs echo suppression.
- **No cross-database transaction (MariaDB + PostgreSQL):** a failure between the two writes leaves the two copies out of sync. That needs a reconciliation job.
- **Ordering:** a queued outbound job can overwrite a newer client edit.
- **Recurrence spawn:** a completed item becomes two writes (update the old row, create a new one), and both have to be mirrored.

**Sync:** native (stock PDO and the Sync plugin).

**Sharing:** this one is dangerous. A pgsql calendar *can* be shared through the existing Sharing plugins. A sharee's write would then change another user's MariaDB todos, which has no meaning in the todos module. Sharing must be blocked for that calendar.

**ACL:** native.

**CacheInvalidationPlugin:** it already fires on these paths and would refresh *calendar* caches for nothing. The inbound handler should live next to it or inside it.

⚑ It doesn't change the pgsql **schema**, but it writes a new kind of data into pgsql (VTODO objects in an app-managed calendar) and adds a server plugin. It also has to account for the VTODOs that may already be stored there (§1.4).

**Pros:** the DAV side is all stock sabre and battle-tested.

**Cons:** two copies of every todo, consistency and loop handling, more moving parts than option A.

**Effort:** L, plus ongoing reconciliation risk.

### Option C: move todos into pgsql as the source of truth

**What:** todos become VTODO calendar objects. `TodoService`, `TodoResource`, statistics, export, reminders, quick-add and the bot all read and write through a DAV backend, the way calendars already do (`app/Dav/Services/*`).

**Consequences:**

- Every reader and writer in §1.2 gets rewritten.
- Tags, category, workspace, effort and the workflow status become X-properties or pgsql side tables.
- `calendar_event_todos.todo_id` (a uuid FK, `2026_06_02_000000_…:19`) and `tag_todo` can't keep a foreign key across databases.
- The recurrence model changes to `RRULE`, which changes the API behaviour the frontend sees.
- A MariaDB → pgsql data migration for all users.
- Queries on todos (due lists, statistics) turn into iCalendar parsing or denormalised columns.

**Sync, sharing, ACL:** all native, and todo lists become shareable for free. That's a product change.

⚑ This changes the pgsql data layout and makes the DAV database the owner of a core module. It is the largest change to the DAV area of any option.

**Effort:** XL. It only makes sense if a product decision wants todos to *be* CalDAV-native, for example shared task lists. Not recommended for "offer sync".

### Option D: read-only ICS feed (one-way export)

**What:** a URL that returns a `VCALENDAR` with one VTODO per open todo, rendered with the outbound mapping of §2.

**Where to serve it:**

- **D1:** a Laravel route. All API routes use `auth:sanctum` and calendar subscription clients can't send bearer tokens, so this needs a per-user secret URL token. That means a new column or table, a new non-sanctum route, token rotation, and a security review, because the token URL exposes all of a user's todos.
- **D2:** a node under `dav/` with DAV Basic auth through `LaravelAuthBackend`. No new route, but ⚑ factory wiring.

**Sync:** none. Clients poll and re-download everything. No ETags per item and no writes.

**Client reach:** poor for tasks. Most clients treat subscribed ICS as read-only *events*:

- Apple Calendar subscriptions and ICSx⁵ (DAVx⁵'s ICS companion) are events-only (**unverified**).
- Thunderbird can show tasks from an ICS calendar (**unverified**).

In practice D is an export and backup format (a richer sibling of `TodoExportService`'s CSV), not sync.

**Effort:** S.

### Comparison

| | A (virtual, MariaDB) | B (mirror to pgsql) | C (pgsql is truth) | D (ICS feed) |
| --- | --- | --- | --- | --- |
| Single source of truth | yes | no (two copies) | yes (moves) | yes |
| Two-way | yes (phase 2) | yes | yes | no |
| Sync tokens / ETags | own change log (MariaDB) | native | native | none |
| Sharing | none | native, must be blocked | native (product change) | n/a |
| Touches all writers | no (`TodoService` only) | no (hook in `TodoService`) | **yes** | no |
| ⚑ pgsql / DAV wiring | factory wiring | factory + new pgsql data + plugin | pgsql data layout, whole module | D2: factory wiring. D1: new public route |
| Consistency risk | low | **high** | medium (migration) | none |
| Effort | M read-only / L two-way | L | XL | S |

## 4. RFC requirements and client compatibility

### 4.1 RFC requirements that shape the design

- **One component type per resource** (RFC 4791 §4.1): "Calendar object resources … MUST NOT contain more than one type of calendar component". Subtasks therefore can't be nested inside the parent's `.ics`. Each child VTODO is its own resource, linked with `RELATED-TO` (RFC 5545 §3.6.2: "'VTODO' calendar components cannot be nested within another calendar component. However, 'VTODO' calendar components can be related to each other … with the 'RELATED-TO' property"). The same section says resources "MUST NOT specify the iCalendar METHOD property".
- **How collections advertise VTODO** (RFC 4791 §5.2.3): the `CALDAV:supported-calendar-component-set` property:
  - Clients may set it with MKCALENDAR. After that it is protected, so PROPPATCH can't change it.
  - A store of any other component type "MUST result in an error", the `supported-calendar-component` precondition (§5.3.2.1).
  - "In the absence of this property, the server MUST accept all component types."
  
  Sabre implements this in `CalDAV/Plugin.php:813-836`.
- **Required VTODO properties** (RFC 5545 §3.6.2): `DTSTAMP` and `UID` are required. `DUE` and `DURATION` must not both appear. `DURATION` requires `DTSTART`.
- **`RRULE` requires `DTSTART`** (RFC 5545 §3.8.2.4: DTSTART "is REQUIRED in all types of recurring calendar components that specify the 'RRULE' property"). solyto has no start date, so sending `RRULE` would mean making one up, for example `DTSTART = DUE`.
- **Priority**: §3.8.1.9 defines the 3-level mapping used in §2 (1–4 HIGH, 5 MEDIUM, 6–9 LOW, 0 undefined).
- **Status**: §3.8.1.11 lists `NEEDS-ACTION | COMPLETED | IN-PROCESS | CANCELLED` for VTODO.
- **Percent complete**: §3.8.1.8 allows 0–100.
- **Relations**: `RELATED-TO` defaults to `RELTYPE=PARENT` (§3.8.4.5). RFC 9253 §5 adds `FIRST`, `NEXT`, `DEPENDS-ON` and more. `FIRST` describes solyto's `parent_task_id` (the series root) exactly.
- **Time-range queries on VTODO** (RFC 4791 §9.9) use a decision table over `DTSTART`, `DUE`, `DURATION`, `COMPLETED` and `CREATED`. With DUE-only todos this works without `DTSTART`. Sabre evaluates it through `CalendarQueryValidator`, which `AbstractBackend::calendarQuery` also uses for custom backends.
- **Sync** (RFC 6578 §3.2): `DAV:sync-collection` needs a token that orders all changes *including deletions*. That's the reason option A needs a tombstone log.

### 4.2 Discovery: VTODO-only or mixed collections?

- Clients pick task lists by the component set. The DAVx⁵ manual says the server "must support the VTODO component" and that the user needs "at least one collection activated for synchronization that supports VTODOs" (`manual.davx5.com/tasks_notes.html` §3.4). DAVx⁵ syncs VTODO with jtx Board, OpenTasks and tasks.org (§3.1).
- A mixed `VEVENT,VTODO` collection appears as both a calendar and a task list. That is what every solyto calendar advertises today (§1.4). So once a user connects a task app, **all their calendars show up as task lists too**. This follows from the component-set logic above. How each app presents it is **unverified**.
- **Recommendation from this:** use dedicated **VTODO-only** collections for todos, and make app-created calendars VEVENT-only (§5, phase 0).
- `/.well-known/caldav` matters for auto-discovery (tasks.org setup asks for it, `tasks.org/docs/sync_caldav`). It isn't routed in this repo (§1.4). Check the proxy before testing with clients.

### 4.3 Client behaviour

Only the DAVx⁵ rows that cite the manual and the tasks.org recurrence options (`tasks.org/docs/recurrence`) come from a primary source. **Everything else in this table is unverified** general knowledge and must be tested with real clients before phase 2.

| Client | Finds VTODO lists | Subtasks (`RELATED-TO`) | Recurring VTODO | `STATUS` / `PERCENT-COMPLETE` |
| --- | --- | --- | --- | --- |
| Apple Reminders (CalDAV account, not iCloud) | collections that support VTODO show up as lists (unverified) | subtasks, tags and flags are iCloud-only features. CalDAV lists appear flat (unverified) | supports simple repeats (unverified) | completed / not completed only. Priority shown as 3 levels (unverified) |
| Thunderbird | yes, a calendar shows its tasks if it supports VTODO (unverified) | no hierarchy UI. Children appear as separate tasks (unverified) | yes, the series is edited as a master (unverified) | full: Needs action / In process / Completed / Cancelled, and % complete (unverified) |
| DAVx⁵ + tasks.org | yes, needs a VTODO collection (DAVx⁵ manual §3.4) | yes (unverified) | yes, with "repeat from due date or completion date" and "end after X times" (`tasks.org/docs/recurrence`). The last two have no solyto equivalent. Completion moves the dates on the same item (unverified) | completion yes, percent partly (unverified) |
| DAVx⁵ + jtx Board | yes (DAVx⁵ manual §3.1, §3.4) | yes, including notes and journals as children if the collection also allows VJOURNAL (manual §3.5) | yes, with exceptions (unverified) | full (manual §3.4 mentions "progress"; status unverified) |
| Evolution | yes (unverified) | flat (unverified) | yes (unverified) | full (unverified) |

What follows from this, whatever the client details turn out to be:

- **Subtasks:** at least one major client (Apple) is likely to show child VTODOs as flat items next to their parent. Sending subtasks as child VTODOs helps tasks.org and jtx users and clutters lists for Apple users. Putting them in `DESCRIPTION` as a checklist works everywhere, but only in one direction.
- **Recurrence:** clients disagree on completion semantics. Not sending `RRULE` (§2) is the only behaviour that is the same in every client.
- **`STATUS` / `PERCENT-COMPLETE`:** sending both, with `PERCENT-COMPLETE:100` and `STATUS:COMPLETED` together, is safe. Clients that only know "done" read `STATUS` / `COMPLETED`.

## 5. Recommendation (for decision, not decided)

**Recommended: option A (virtual VTODO collection served from MariaDB), built in phases, read-only first.** It keeps `todos` as the only source of truth and keeps every existing writer and feature working. It avoids option B's two-copy consistency problems and option C's rewrite. The only DAV-side change is the server wiring (⚑ `DavServerFactory`). The pgsql schema and data and the DAV routes stay untouched.

Option D is not a substitute for sync (§3, client reach). It is worth doing on its own only if the goal is "see my todos in my calendar app".

### Phased plan

| Phase | What | Effort | Flags |
| --- | --- | --- | --- |
| 0. Contain what already exists | Decide what to do about the VTODOs the server already accepts (§1.4). Suggested: (a) `createCalendarCustom` passes a real `SupportedCalendarComponentSet(['VEVENT'])` so new app calendars are VEVENT-only. (b) `getEventsCustom` / `getEventByUriCustom` skip non-VEVENT objects, so calendar export and lists stop picking up VTODOs. (c) First count existing `componenttype = 'VTODO'` objects in pgsql, which wasn't possible from here. If users already rely on them, changing existing `calendars.components` rows takes a pgsql data update. | S | ⚑ (c) is a pgsql data change. (a) and (b) touch DAV backend code |
| 1. Read-only VTODO collection | One `Todos` collection per user, VTODO-only, read-only ACL. Outbound mapping from §2 with no `RRULE` and `X-SOLYTO-*` for status, effort and recurrence. Completed todos are included for a window to be decided. ETag = content hash. Plus a change log and sync token, or CTag-only to start. Test discovery and display with the clients in §4.3 | M | ⚑ factory wiring. A MariaDB migration if there's a change log (show before running) |
| 2. Two-way core fields | PUT and DELETE through `TodoService` for title, description, link, completion, status, priority, progress, due date and tags. Store the client's UID and href. Use `If-Match` / ETag for conflicts. Completion triggers the existing spawn. Reject or drop unsupported input (§2) | L | MariaDB migration (`dav_uid` / `dav_uri` or a mapping table) |
| 3. Structure | Subtasks as child VTODOs both ways (if wanted). Inbound simple `RRULE` subset. Optionally one collection per category, where moving an item between collections changes its category | M | — |

### Risks

1. **Mapping loss surprises users** (§2): status collapse, `CANCELLED`, 9→3 priorities, dropped alarms and client X-properties on round-trip, and description truncation. Mitigation: phase 1 is read-only, document what's lossy, and optionally store the raw inbound iCalendar to keep unknown properties.
2. **Recurrence duplicates**, if `RRULE` is ever sent, or if a client creates a recurring task and also sees solyto's spawned rows (§2, "Recurrence in detail").
3. **Sync-token correctness**: changes that bypass `TodoService` (category or tag deletion cascades, user deletion, §1.2) must still bump the token, or clients go stale silently.
4. **Factory change in the DAV request path** (⚑): a bug in the composite backend or home subclass could break *calendar* sync for everyone, not just todos. It needs DAV-level tests for the existing calendar paths too.
5. **VTODOs already in pgsql** (§1.4): users may already have tasks in solyto calendars from Thunderbird or DAVx⁵. Phase 0 could make them disappear from clients, and option A would show them *next to* a new "Todos" list. They need a decision: migrate them into `todos`, keep them, or leave them alone.
6. **Apple Reminders shows subtasks flat** (unverified): child VTODOs may clutter lists. That's the reason the subtask representation is an open question.
7. **Discovery**: `/.well-known/caldav` isn't routed in this repo. If the proxy doesn't handle it, clients need the full URL.
8. **Performance**: `AbstractBackend::calendarQuery` loads every object of a collection. That's fine at todo volumes, but completed todos need a cut-off.

### Open questions for a human

1. **Is two-way sync required**, or is read-only (phase 1) enough for the first release? The brief's "Why" is empty.
2. **What's the goal behind "sync"?** Using a native task app (option A), sharing task lists with others (only option C or B gives that, and it's a product change), or seeing due todos in a calendar (option D)?
3. **How do categories and workspaces map?** One `Todos` collection with category and tags in `CATEGORIES`, one collection per category, or one per workspace? Workspaces can't map cleanly because one category can be in several of them.
4. **Must subtasks sync?** If yes: as child VTODOs (structure for tasks.org and jtx, possible clutter in Apple) or as a checklist in `DESCRIPTION` (one-way)?
5. **How lossy may recurrence be?** Is "no `RRULE`, every occurrence is a plain todo" acceptable? For inbound recurring tasks, should solyto map a simple subset, reject them, or drop the rule?
6. **What does inbound `STATUS:CANCELLED` mean?** Completed, deleted, or rejected?
7. **Should `medium` priority be sent as `PRIORITY:5` or as no priority?** solyto defaults every todo to `medium`.
8. **Which completed todos are exposed?** None, the last N days, or all?
9. **Is changing the DAV server wiring (`DavServerFactory`) acceptable** (⚑ option A)? Also: are pgsql data changes acceptable in phase 0 (⚑), and is option B's new pgsql data, or option C's move, on the table at all?
10. **What happens to VTODOs already stored in pgsql calendars**, and should app-created calendars stop accepting VTODO (phase 0)?
11. **Should unknown client properties (alarms, X-props) be kept** by storing the raw iCalendar, or dropped?
12. **Should DAV writes follow the API's limits** (`description` max 1000), or the column limits?

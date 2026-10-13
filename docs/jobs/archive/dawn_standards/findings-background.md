# Findings — jobs, commands, notifications, bots, DAV (TASK-6)

Lighter pass. DAV is describe-only: nothing here proposes a change to
`app/Dav/` or the `pgsql` connection.

## Jobs (18 under `app/Api/*/Jobs`)

- All `implements ShouldQueue` with `Queueable, Dispatchable, InteractsWithQueue`.
- `$tries`: set in 2 (`SyncFeeds` 1, `SyncFeed` 2); `$timeout`: 4
  (`SyncFeeds` 60, `SyncFeed` 240, `DeleteOldFeedItems` 300, `ProcessExport`
  300). The other 14 run on queue defaults — fine for the cleanup jobs,
  questionable for `Grab*Releases` (external APIs, minutes) and
  `ImportCalendars`/`ImportContacts`.
- Constructor payloads: ids/strings in `SyncFeed(string $feedId)`,
  `ProcessExport(string $userId, int $jobId)`, `ScaleProfileImage(string
  $path)`, `GenerateCoverPreview(string $disk, string $path)` — good.
  **Whole models**: `RefreshCalendarCache(public User $user)`,
  `ImportCalendars(public User $user)`, `RefreshContactsCache(public User
  $user)`, `ImportContacts(public User $user)`. None of the 18 jobs uses
  `SerializesModels` (`grep -rl SerializesModels app/Api/*/Jobs` → 0), so
  the four serialise the full `User` row (including hashed password and
  settings relation if loaded) into Redis and run with a stale copy. Laravel
  docs (queues.md, "Queued Relationships"): the trait stores only the key and
  re-fetches on `handle()`. Either add the trait or pass `$userId` like
  `ProcessExport`.
- `handle()` re-reads state through services (`RefreshCalendarCache:24-29`
  `app(CalendarService::class)->list($this->user)`); `Log::info` narration
  at `:26`.
- `Grab*Releases:36`, `ScaleCovers:70`: `app()->makeWith(LibraryReleases::class,
  ['user' => $user])` — the `LibraryReleases` constructor-user problem from
  `findings-services.md` §2 leaks into the jobs.

## Schedule (`routes/console.php`)

46 lines, grouped by module with `/* … */` headers, `Schedule::job(...)`
for jobs and `Schedule::command(...)` for the two reminder commands. The
deactivated `GrabMovieReleases` is a commented line with a three-line
reason naming the job (`:21-24`). Nothing to change; this is the shape.

## Commands (18)

- 16 under `app/Api/<Module>/Commands`, 2 under `app/Bots/Commands`;
  every directory is listed in `bootstrap/app.php:32-40` `withCommands`.
  No `app/Console`. `Calendars`, `Clipboard`, … have no commands and no
  entry — adding one means adding the path (documented rule).
- `RepairCorruptedVCards::handle` (73 lines) and `ScaleContactPhotos::handle`
  (55) write to `DB::connection('pgsql')->table('cards')` directly — repair
  tooling outside `app/Dav`, touching DAV tables (`cards`, `addressbookchanges`,
  `addressbooks.synctoken`). Correct per the DAV layout as far as read, and
  the only place outside `app/Dav` that writes `pgsql`. Flagged, not
  proposed for change.
- Comments: `RepairCorruptedVCards` has 7 `//` lines (`:101-113`) describing
  the two repair strategies — why-comments, keep.

## Notifications

- `App\Shared\Notifications\BaseNotification` (abstract): `via()` reads the
  per-type `{type}_ui/_push/_email/_telegram` flags from
  `UserNotificationSettings` (`firstOrCreate` + `refresh()` with a
  why-comment), `withLocale()` swaps the app locale around the callback.
  Module notifications extend it and implement `toDatabase`/`toWebPush`/
  `toMail`/Telegram. `getNotificationType()` is the stable client id.
  Clean; it is the thing to copy.
- `withLocale(): mixed` (`:24`) is one of the 20 `mixed` returns; here it
  is honest (callback result).

## Bots (`app/Bots`, 15 files)

- `SolytoBot` + `State/` machine; every user-visible string is a case of
  `enum SolytoMessage: string` mapping to a `bot.*` translation key with a
  `trans()` method (`Messages/SolytoMessage.php`). 0 literal strings passed
  to `sendMessage`. Good — this is the i18n rule for the API side.
- `IsTelegramBot:38-45`: outer `try { … $this->entrypoint(); } catch
  (\Exception $e)` logs to the `bots` channel with file:line and replies the
  default error — fine. `IsTelegramBot:161-168`: inner `try { $this->{$route}();
  return true; } catch (\Exception $e) { return false; }` swallows handler
  errors before the outer one can log them; the dynamic method call
  `$this->{$this->routes[...]}()` is the same string-dispatch as
  `call_user_func` in the Libraries services.
- `IsTelegramBot:50` `$this->request = request();` — the one `request()`
  helper call in non-test code; a webhook controller could pass the request
  in.

## DAV (`app/Dav`, 39 files, ≈4 100 lines) — describe only

- `Helpers/ICalendarHelper.php` (5 `catch (\Exception $e)`: `:53,94,155,170,207`)
  and `Helpers/VCardHelper.php` (4: `:64,96,170,204`) wrap XML/vCard parsing,
  `Log::error('Error parsing …')` and return what was parsed so far
  (`ICalendarHelper:46-58`). Lenient-by-design parsers for third-party
  input; the pattern is consistent and tested (`tests/Unit/Dav/*`).
- `Plugins/CacheInvalidationPlugin.php` (4 `catch (\Throwable)`): cache
  invalidation must never break a DAV request — correct place for a
  swallow.
- `Services/CalendarImport.php:89,128`, `ContactImport.php:89,128`: per-item
  `catch (\Exception)` inside import loops, same lenient reasoning.
- `Backend/AppCalendarsPDO.php` holds the longest methods in the repo
  (`getExpandedEventsCustom` 129 lines, `updateOccurrenceCustom` 76);
  `DTOs/ContactDTO::parseVCard` 111, `EventDTO::parseVCal` 105. Format
  parsers; the AI job `76e77a0` added 47 lines to `AppCalendarsPDO` and the
  owner accepted it.
- `tests/TestCase.php` remaps `pgsql` to a file SQLite; the DAV migration is
  driver-aware. Any rule in `STANDARDS.md` stops at the `app/Dav` boundary.

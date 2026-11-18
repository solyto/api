# Report: optional end-to-end encryption — API side

date: 2026-11-18
branch: `feature/brief_solyto-encrypted` (job `brief`)
scope: Can solyto offer optional encryption of user data, what does the user give up, and what does the API have to change? Written for the maintainer after a joint review with the frontend (ophelia, app report: `ophelia/encryption-research.md` in the room). Report only. No application code, migrations, config, routes or DAV wiring were changed.

## Conclusions

1. **Worth doing:** yes, as end-to-end encryption (E2EE). The app encrypts and decrypts on the device. The server and the database only see scrambled text, so a leaked dump or backup reveals no content.
2. **One switch for the user:** "Encrypt my data". It covers every area supported so far. Areas added later migrate automatically on the next unlock. The phases below are build order only.
3. **Exceptions, shown in settings:**
   - Feeds and library metadata stay unencrypted. The server needs URLs and titles to fetch feeds, covers and release alerts.
   - Calendars and address books are encrypted only if the user picks them. Default is none, so nobody loses calendar/contact sync by surprise.
4. **Separate encryption password.** The login password can't serve as the key: the server receives it on every REST login and every CalDAV/CardDAV Basic-auth request (`app/Dav/Auth/LaravelAuthBackend.php`). Passkey users can unlock with fingerprint or Face ID where the device supports it, with the encryption password as fallback.
5. **No effect on users who don't switch it on.** Every API change is additive and every new rule only applies to users with the switch on (see "Safety for other users").

## Downsides the maintainer accepted

These are trade-offs each user makes when switching it on:

- If the user loses the encryption password, the recovery code and every remembered device, the data is gone for good. An email password reset can't recover it.
- Encrypted calendars and address books don't sync to phone, Thunderbird or DAVx⁵ apps.
- Encrypted calendars can't be shared with friends at first.
- Email, Telegram and ntfy reminders only show counts ("3 todos today"), not titles.
- The Telegram bot can't show encrypted items.

## Remaining downsides and how they're softened

| Downside | Mitigation | What's left |
|---|---|---|
| Unlocking on each new device/browser | "Remember this device" by default, fingerprint/passkey unlock (WebAuthn PRF), optional auto-lock | Enter the password once per new device |
| Dates and structure stay readable | Check-in rows and finance values are encrypted as one block each, and tag and folder names are encrypted too | A leak shows *when* (event times, due dates, check-in days, journal dates), not *what* |
| Old copies after switching on | Clear the user's Redis cache, ready export zips and stored notifications at switch-on | Backups from before the switch age out within 7 days (current retention). The UI should say so instead of promising instant protection |
| Switching on takes time with many large notes | Progress, pause/resume, and the notes list loads without content | A one-time wait |
| Web version trusts the server's JavaScript | Content-Security-Policy, and recommend the desktop/mobile apps to privacy users | Remains true for the web |
| Nobody can repair encrypted content | Failed decrypts show read-only and can't be saved back, plus an admin command that shows flags and key-id counts (no content) | A corrupt entry stays lost |
| Widgets | The native shell stores the key in Keychain/Keystore (shell repo) | Blank until that's built |
| Ongoing cost for the developer | — | Every future server feature that reads content (VTODO sync, search, AI on notes, new reminder types) must run in the app or skip encrypted areas |

Not downsides after review: search, filters, sorting, stats, dashboard, recurring todos (the server copies ciphertext as-is in `TodoService::spawnNextOccurrence`), quick add (`QuickAddService::detect` is only keyword matching and moves to the app), import (the app parses files and uploads them encrypted), and export (the app decrypts it).

## What the API has to change

### Keys and switch
- New table for the user's key material: a wrapped data key (DEK) per unlock method (password, recovery code, passkey PRF, remembered device), plus salt and KDF parameters. The server never sees the DEK.
- One user-level switch, plus an internal per-area status in `user_settings` (`off` / `migrating` / `encrypted`). The server sets an area to `encrypted` by itself once no plaintext rows are left, so it doesn't rely on the client saying it's done.
- Ciphertext envelope `enc:v1:<key-id>:<iv>:<data>`. Encrypted and plaintext rows can coexist, and the key id allows key rotation (e.g. after a lost device).

### Storage
- Notes, todos, clipboard, time tracking: the content fields hold the envelope. `varchar(255)` columns (`notes.title`, `todos.title`, `time_tracking_entries.description`, category/folder titles) need widening to `text`, since ciphertext is about 1.4× longer. `notes.content` is already `longText`.
- Check-in, budget values, wealth values: a new nullable `payload` column holds the whole row as one blob, with only the date in plaintext. The old columns stay for plaintext users and are NULL for encrypted rows. One blob per row hides which trackers are filled in; with per-field encryption, the NULL pattern alone shows things like the menstruation cycle. `budgets.value` (copied from the latest value in `BudgetService`) must be the blob or NULL, otherwise the current amount leaks.
- Tags (`tags.name`, `varchar(50)`, shared by todos, notes, links, books, movies, games, quotes and budgets) are encrypted as soon as the switch is on, with an HMAC "blind index" column so the server can still prevent duplicates without reading names.
- Journal entries keep their date title in plaintext. `NoteService::journalMarks` filters on it (`whereBetween('title', …)`), and the date isn't secret beyond `created_at`. The app must not allow renaming a journal entry to anything else.

### Server logic that changes for encrypted users
- **Validation:** content fields lose length and format rules and must carry the envelope. Writes without the envelope get `422 encryption_required` (FormRequest rule) while the area is `migrating` or `encrypted`. That is the actual guard against old clients. The 426 version gate (`RecordClientVersion`) can't do it: it runs before auth resolves the user, and requests without an `X-Client-Version` header are let through on purpose.
- **Moves to the app:** `TodoService::parse` (`#tag`, `/category`, `due:`, `repeat:`, plus tag/category lookup by `LOWER(name)`), the `NoteService` sort by title, the `WealthService::listFields` sort by value, time-tracking statistics, notes import/export (`NoteImportService`, `NoteExportService`), ICS/vCard import.
- **Reminders:** `DailyDayReminderNotification` leaves out titles of encrypted items for mail, Telegram and ntfy. Web push carries the stored ciphertext titles, decrypted by the service worker, capped to the roughly 4 KB payload limit with "+X more". The database notification row stores ids/counts only.
- **Server-side writers** (Telegram bot, quick add, recipe import from URL) are blocked for encrypted areas at first. Later, a per-user public key (ECDH P-256 + AES-GCM, available in PHP's openssl and in WebCrypto) lets the server write entries it can't read back. Text typed into Telegram is still visible to Telegram.
- **Migration:** a batch update endpoint per area, ownership-checked, with an `updated_at` precondition per row (409 → reload and retry), in batches of about 50.
- **Calendars (later):** an "encrypted" flag on the calendar instance hides it from CalDAV PROPFIND. Sharing an encrypted calendar is refused in `CalendarService`, because a shared calendar also reaches the friend over DAV. ⚑ This touches the `pgsql`/DAV layout and needs explicit sign-off.

### Switch-on cleanup
Delete the user's `UserCacheService` keys (`forgetByPrefix`), ready export zips (normally 48 h, `DeleteExpiredExports`) and stored notifications.

## Safety for other users

- **Additive changes:** nullable columns, `text` widening, new tables, and a switch that defaults to off. Plaintext rows read and write exactly as today.
- **Scoped rules:** the 422 guard, title-free reminders, blocked bot writes and cleanup only run when the user's switch is on.
- **Shared change:** the notes list without content. Make it opt-in (e.g. `?fields=summary`) so older apps keep working.
- **Real risk:** the column-widening migrations on large tables, which are reversible, and bugs in shared code paths. Both need tests per area for plaintext and encrypted users.

## Plan

**Phase 0 — for everyone, worth doing regardless**
- `Cache-Control: no-store` on `/api/v1`. API JSON currently gets Symfony's default `no-cache, private`, which still lets browsers keep responses on disk.
- Notifications store ids/counts instead of todo/event titles. `DailyDayReminderNotification::toDatabase` writes them as plaintext into the notifications table today, for every user.
- Notes list without content, plus fetching single notes (`NoteService::list` returns and caches every note with full content today).
- Content-Security-Policy (app repo).

**Phase 1 — foundation + Notes/journal:** key tables, switch and area status, envelope validation, batch endpoint, note column widening, journal title rule, notes import/export in the app.

**Phase 2 — Check-in, Finances, tags:** `payload` columns, `budgets.value` handling, tag blind index.

**Phase 3 — Todos, Clipboard text:** todo parsing in the app, reminder changes, bot/quick-add blocking.

**Later, optional:** titles in push notifications, individual encrypted calendars/address books (⚑ DAV), the Telegram bot writing with the public key, encrypted calendar sharing (per-calendar keys), widgets.

## Corrections during the review

- Telescope is registered only in the `local` environment (`AppServiceProvider`), so production records no request bodies. Not a leak path.
- Redis (default config) writes RDB snapshots to disk. The cache purge at switch-on handles that. Don't turn snapshots off: the same Redis holds the job queue, and queued jobs would be lost on restart.
